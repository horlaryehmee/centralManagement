<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_with_a_one_time_code_issues_unique_credentials(): void
    {
        $client = Client::create(['name' => 'Acme']);
        $installation = Installation::create(['client_id' => $client->id, 'name' => 'Acme shop', 'domain' => 'acme.test']);
        $code = $installation->issueEnrollmentCode();

        $res = $this->postJson('/api/v1/register', ['code' => $code, 'site_url' => 'https://acme.test', 'version' => '1.0.0'])->assertOk()
            ->assertJsonStructure(['installation_id', 'secret', 'client_key', 'signing' => ['key_id', 'public_key', 'fingerprint'], 'check_interval']);

        $this->assertSame($installation->uuid, $res->json('installation_id'));
        $this->assertSame($client->key, $res->json('client_key'));
        $fresh = $installation->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertNull($fresh->enrollment_code_hash);
        // Stored encrypted, not in plain text.
        $this->assertStringNotContainsString($res->json('secret'), (string) \DB::table('installations')->value('secret'));

        // The code works once only.
        $this->postJson('/api/v1/register', ['code' => $code, 'site_url' => 'https://acme.test'])->assertStatus(422)->assertJson(['error' => 'invalid_code']);
    }

    public function test_expired_codes_and_suspended_clients_cannot_register(): void
    {
        $client = Client::create(['name' => 'Acme']);
        $i = Installation::create(['client_id' => $client->id, 'name' => 'A', 'domain' => 'a.test']);
        $code = $i->issueEnrollmentCode();
        $i->forceFill(['enrollment_expires_at' => now()->subMinute()])->save();
        $this->postJson('/api/v1/register', ['code' => $code, 'site_url' => 'https://a.test'])->assertStatus(422)->assertJson(['error' => 'expired_code']);

        $code = $i->issueEnrollmentCode();
        $client->update(['status' => 'suspended']);
        $this->postJson('/api/v1/register', ['code' => $code, 'site_url' => 'https://a.test'])->assertStatus(403);
    }

    public function test_signed_requests_are_verified(): void
    {
        [$i, $secret] = $this->connectedInstallation();

        $this->heartbeat($i, $secret)->assertOk()->assertJson(['status' => 'active', 'instructions' => []]);

        // Wrong secret, unknown installation, malformed headers.
        $this->signed($i, $secret, 'POST', '/api/v1/heartbeat', ['version' => '1.0.0'], ['secret' => 'wrong'])->assertStatus(401)->assertJson(['error' => 'unauthenticated']);
        $this->signed($i, $secret, 'POST', '/api/v1/heartbeat', ['version' => '1.0.0'], ['id' => '00000000-0000-0000-0000-000000000000'])->assertStatus(401)->assertJson(['error' => 'unauthenticated']);
        $this->postJson('/api/v1/heartbeat', ['version' => '1.0.0'])->assertStatus(401);
    }

    public function test_tampered_bodies_old_timestamps_and_replays_are_rejected(): void
    {
        [$i, $secret] = $this->connectedInstallation();

        // Signature made for one body, different body sent.
        $ts = (string) time();
        $nonce = str_repeat('a', 32);
        $sig = hash_hmac('sha256', implode("\n", ['POST', 'api/v1/heartbeat', $ts, $nonce, hash('sha256', '{"version":"1.0.0"}')]), $secret);
        $this->call('POST', '/api/v1/heartbeat', [], [], [], $this->transformHeadersToServerVars(['X-Installation-Id' => $i->uuid, 'X-Timestamp' => $ts, 'X-Nonce' => $nonce, 'X-Signature' => $sig, 'Accept' => 'application/json', 'Content-Type' => 'application/json']), '{"version":"9.9.9"}')
            ->assertStatus(401);

        $this->signed($i, $secret, 'POST', '/api/v1/heartbeat', ['version' => '1.0.0'], ['timestamp' => time() - 600])->assertStatus(401)->assertJson(['error' => 'clock_skew']);

        $nonce = str_repeat('b', 32);
        $this->signed($i, $secret, 'POST', '/api/v1/heartbeat', ['version' => '1.0.0'], ['nonce' => $nonce])->assertOk();
        $this->signed($i, $secret, 'POST', '/api/v1/heartbeat', ['version' => '1.0.0'], ['nonce' => $nonce])->assertStatus(401)->assertJson(['error' => 'replayed']);
    }

    public function test_revoked_and_suspended_installations(): void
    {
        [$i, $secret] = $this->connectedInstallation();
        $release = $this->publishedRelease($this->coreZip('1.1.0'));
        $deployment = app(DeploymentService::class)->create($release, [$i->id], []);
        $target = $deployment->targets()->first();

        // Suspended: still monitored, but no instructions or deliveries.
        $i->update(['status' => 'suspended']);
        $this->heartbeat($i, $secret)->assertOk()->assertJson(['status' => 'suspended', 'instructions' => []]);
        $this->signed($i, $secret, 'POST', "/api/v1/deployments/{$target->id}/accept")->assertStatus(403);

        // Revoked: credentials no longer work at all.
        $i->update(['status' => 'revoked']);
        $this->heartbeat($i, $secret)->assertStatus(401);
    }

    public function test_one_installation_can_never_see_another_installations_deployments(): void
    {
        [$a, $secretA] = $this->connectedInstallation();
        [$b, $secretB] = $this->connectedInstallation();
        $release = $this->publishedRelease($this->coreZip('1.1.0'));
        $targetA = app(DeploymentService::class)->create($release, [$a->id], [])->targets()->first();

        $this->heartbeat($b, $secretB)->assertJson(['instructions' => []]);
        foreach (['accept', 'progress', 'complete'] as $action) {
            $this->signed($b, $secretB, 'POST', "/api/v1/deployments/{$targetA->id}/{$action}", ['step' => 'x', 'result' => 'succeeded'])->assertStatus(404);
        }
        $this->signed($b, $secretB, 'GET', "/api/v1/deployments/{$targetA->id}/package")->assertStatus(404);
        // And B's secret can't be used to speak as A.
        $this->signed($a, $secretB, 'POST', '/api/v1/heartbeat', ['version' => '1.0.0'])->assertStatus(401);
    }

    public function test_https_is_required_unless_explicitly_allowed(): void
    {
        config(['central.allow_insecure_api' => false]);
        $this->postJson('/api/v1/register', ['code' => 'x', 'site_url' => 'https://a.test'])->assertStatus(403)->assertJson(['error' => 'https_required']);
        $this->postJson('https://localhost/api/v1/register', ['code' => 'x', 'site_url' => 'https://a.test'])->assertStatus(422);
    }

    public function test_registration_is_rate_limited(): void
    {
        foreach (range(1, 10) as $n) {
            $this->postJson('/api/v1/register', ['code' => "bad-{$n}", 'site_url' => 'https://a.test'])->assertStatus(422);
        }
        $this->postJson('/api/v1/register', ['code' => 'bad-11', 'site_url' => 'https://a.test'])->assertStatus(429);
    }

    public function test_heartbeat_records_versions_and_modules(): void
    {
        [$i, $secret] = $this->connectedInstallation();
        $this->heartbeat($i, $secret, '1.2.0', [
            ['slug' => 'gift-wrap', 'type' => 'module', 'version' => '1.0.0', 'requires' => ['core' => '^1.0']],
            ['slug' => 'acme-pay', 'type' => 'extension', 'version' => '2.0.0'],
        ])->assertOk();

        $i->refresh()->load('modules');
        $this->assertSame('1.2.0', $i->core_version);
        $this->assertSame(['acme-pay' => '2.0.0', 'gift-wrap' => '1.0.0'], collect($i->moduleVersions())->sortKeys()->all());

        // Modules no longer reported are removed.
        $this->heartbeat($i, $secret, '1.2.0', [['slug' => 'gift-wrap', 'type' => 'module', 'version' => '1.0.1']])->assertOk();
        $this->assertSame(['gift-wrap' => '1.0.1'], $i->fresh()->load('modules')->moduleVersions());
    }
}
