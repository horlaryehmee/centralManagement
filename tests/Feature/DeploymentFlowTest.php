<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Installation;
use App\Services\DeploymentService;
use App\Services\ReleaseService;
use App\Services\Signer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeploymentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_manifest_is_signed_and_lists_every_file(): void
    {
        $release = $this->publishedRelease($this->coreZip('1.1.0'), ['notes' => 'Faster']);
        $manifest = json_decode($release->manifest, true);

        $this->assertTrue(app(Signer::class)->verify($release->manifest, $release->signature));
        $this->assertFalse(app(Signer::class)->verify(str_replace('1.1.0', '1.1.1', $release->manifest), $release->signature));
        $this->assertSame(['core', 'core', '1.1.0'], [$manifest['type'], $manifest['slug'], $manifest['version']]);
        $this->assertSame($release->package_sha256, $manifest['package']['sha256']);
        $this->assertSame(hash('sha256', '<?php // 1.1.0'), $manifest['files']['app/Feature.php']);
        $this->assertCount(6, $manifest['files']);
    }

    public function test_unsafe_or_mismatched_packages_are_refused(): void
    {
        $service = app(ReleaseService::class);
        $this->expectException(ValidationException::class);
        $service->createFromPackage($this->zip(['VERSION' => '1.0.0', 'artisan' => '', 'vendor/autoload.php' => '', 'bootstrap/app.php' => '', 'public/build/manifest.json' => '', '.env' => 'APP_KEY=x']), []);
    }

    public function test_full_deployment_only_succeeds_when_the_site_reports_it(): void
    {
        [$i, $secret] = $this->connectedInstallation();
        $release = $this->publishedRelease($this->coreZip('1.1.0'));
        $deployment = app(DeploymentService::class)->create($release, [$i->id], []);
        $target = $deployment->targets()->first();

        $this->heartbeat($i, $secret)->assertJsonPath('instructions.0.type', 'deploy')->assertJsonPath('instructions.0.target', $target->id);

        $accept = $this->signed($i, $secret, 'POST', "/api/v1/deployments/{$target->id}/accept")->assertOk();
        $this->assertTrue(app(Signer::class)->verify($accept->json('manifest'), $accept->json('signature')));
        $this->assertSame('delivered', $target->fresh()->status);

        $download = $this->signed($i, $secret, 'GET', "/api/v1/deployments/{$target->id}/package")->assertOk();
        $this->assertSame($release->package_sha256, hash_file('sha256', $download->baseResponse->getFile()->getPathname()));
        $this->assertSame('bytes', $download->headers->get('Accept-Ranges')); // resumable

        $this->signed($i, $secret, 'POST', "/api/v1/deployments/{$target->id}/progress", ['step' => 'backup', 'message' => 'Backing up'])->assertOk();
        $this->assertSame('running', $target->fresh()->status);
        // Sent and running is NOT success.
        $this->assertSame('running', $deployment->fresh()->status);
        $this->assertSame('1.0.0', $i->fresh()->core_version);

        $this->signed($i, $secret, 'POST', "/api/v1/deployments/{$target->id}/complete", ['result' => 'succeeded', 'version' => '1.1.0', 'log' => ['done']])->assertOk();
        $this->assertSame('succeeded', $target->fresh()->status);
        $this->assertSame('completed', $deployment->fresh()->status);
        $this->assertSame('1.1.0', $i->fresh()->core_version);
    }

    public function test_retryable_failures_are_retried_with_backoff_then_fail(): void
    {
        [$i, $secret] = $this->connectedInstallation();
        $deployment = app(DeploymentService::class)->create($this->publishedRelease($this->coreZip('1.1.0')), [$i->id], ['max_attempts' => 2]);
        $target = $deployment->targets()->first();

        $this->signed($i, $secret, 'POST', "/api/v1/deployments/{$target->id}/accept")->assertOk();
        $this->signed($i, $secret, 'POST', "/api/v1/deployments/{$target->id}/complete", ['result' => 'failed', 'error' => 'Download timed out', 'error_code' => 'download', 'retryable' => true])->assertOk();
        $t = $target->fresh();
        $this->assertSame('retry_wait', $t->status);
        $this->assertTrue($t->next_attempt_at->isFuture());
        $this->heartbeat($i, $secret)->assertJson(['instructions' => []]); // not before the backoff

        $this->travel(6)->minutes();
        app(DeploymentService::class)->tick();
        $this->heartbeat($i, $secret)->assertJsonPath('instructions.0.target', $target->id);
        $this->signed($i, $secret, 'POST', "/api/v1/deployments/{$target->id}/accept")->assertOk();
        $this->signed($i, $secret, 'POST', "/api/v1/deployments/{$target->id}/complete", ['result' => 'failed', 'error' => 'Timed out again', 'retryable' => true])->assertOk();

        $this->assertSame('failed', $target->fresh()->status); // max attempts reached
        $this->assertSame(2, $target->fresh()->attempts);
        $this->assertSame('failed', $deployment->fresh()->status);
    }

    public function test_test_installation_goes_first_and_a_failure_stops_the_rest(): void
    {
        $client = Client::create(['name' => 'Acme']);
        [$canary, $cs] = $this->connectedInstallation($client, ['is_test' => true]);
        [$other, $os] = $this->connectedInstallation($client);
        $release = $this->publishedRelease($this->coreZip('1.1.0'));
        $service = app(DeploymentService::class);

        // Success path: others are released only after the canary reports success.
        $d = $service->create($release, [$canary->id, $other->id], ['canary_installation_id' => $canary->id]);
        $this->heartbeat($other, $os)->assertJson(['instructions' => []]);
        $ct = $d->targets()->where('installation_id', $canary->id)->first();
        $this->signed($canary, $cs, 'POST', "/api/v1/deployments/{$ct->id}/accept")->assertOk();
        $this->signed($canary, $cs, 'POST', "/api/v1/deployments/{$ct->id}/complete", ['result' => 'succeeded', 'version' => '1.1.0'])->assertOk();
        $this->heartbeat($other, $os)->assertJsonPath('instructions.0.type', 'deploy');

        // Failure path on the next release.
        $release2 = $this->publishedRelease($this->coreZip('1.2.0'));
        $this->heartbeat($canary, $cs, '1.1.0');
        $this->heartbeat($other, $os, '1.1.0');
        $other->targets()->update(['status' => 'cancelled']);
        $d2 = $service->create($release2, [$canary->id, $other->id], ['canary_installation_id' => $canary->id]);
        $ct2 = $d2->targets()->where('installation_id', $canary->id)->first();
        $this->signed($canary, $cs, 'POST', "/api/v1/deployments/{$ct2->id}/accept")->assertOk();
        $this->signed($canary, $cs, 'POST', "/api/v1/deployments/{$ct2->id}/complete", ['result' => 'failed', 'error' => 'Migration error', 'retryable' => false])->assertOk();

        $ot2 = $d2->targets()->where('installation_id', $other->id)->first();
        $this->assertSame('cancelled', $ot2->status);
        $this->assertSame('canary_failed', $ot2->error_code);
        $this->assertSame('failed', $d2->fresh()->status);
    }

    public function test_extensions_only_go_to_their_own_client(): void
    {
        $acme = Client::create(['name' => 'Acme']);
        $globex = Client::create(['name' => 'Globex']);
        [$acmeSite, $as] = $this->connectedInstallation($acme);
        [$globexSite, $gs] = $this->connectedInstallation($globex);
        $release = $this->publishedRelease($this->moduleZip('acme-pay', '1.0.0', 'extension'), ['client_id' => $acme->id]);

        $preview = app(DeploymentService::class)->preview($release, Installation::whereIn('id', [$acmeSite->id, $globexSite->id])->get());
        $this->assertTrue($preview->firstWhere('installation.id', $acmeSite->id)['ok']);
        $this->assertSame(['This extension belongs to another client.'], $preview->firstWhere('installation.id', $globexSite->id)['reasons']);

        $d = app(DeploymentService::class)->create($release, [$acmeSite->id, $globexSite->id], []);
        $this->assertSame([$acmeSite->id], $d->targets()->pluck('installation_id')->all());
        $this->assertSame($acme->key, json_decode($release->manifest, true)['client']);

        // Globex can't fetch Acme's extension manifest either.
        $this->signed($globexSite, $gs, 'GET', '/api/v1/manifests/extension/acme-pay/1.0.0')->assertStatus(404);
        $this->signed($acmeSite, $as, 'GET', '/api/v1/manifests/extension/acme-pay/1.0.0')->assertOk();
    }

    public function test_compatibility_rules_block_incompatible_installs(): void
    {
        [$i, $secret] = $this->connectedInstallation();
        $this->heartbeat($i, $secret, '1.0.0', [['slug' => 'old-mod', 'type' => 'module', 'version' => '1.0.0', 'requires' => ['core' => '<1.5']]]);
        $preview = fn ($r) => app(DeploymentService::class)->preview($r, collect([$i->fresh()]))->first();

        $this->assertStringContainsString('old-mod 1.0.0 needs core <1.5', $preview($this->publishedRelease($this->coreZip('2.0.0')))['reasons'][0]);
        $this->assertStringContainsString('Needs core 1.2.0 or newer first', $preview($this->publishedRelease($this->coreZip('1.3.0'), ['upgrade_from_min' => '1.2.0']))['reasons'][0]);
        $this->assertStringContainsString('Needs core ^2.0', $preview($this->publishedRelease($this->moduleZip('new-mod', '1.0.0', 'module', ['core' => '^2.0'])))['reasons'][0]);
        $this->assertStringContainsString('Needs module payments', $preview($this->publishedRelease($this->moduleZip('addon', '1.0.0', 'module', ['modules' => ['payments' => '^1.0']])))['reasons'][0]);

        $ok = $preview($this->publishedRelease($this->moduleZip('fresh', '1.0.0', 'module', ['core' => '^1.0'])));
        $this->assertTrue($ok['ok']);
        $this->assertSame('install', $ok['action']);

        // Re-checked at delivery: the site downgraded its module report after the deployment was created.
        $mod = $this->publishedRelease($this->moduleZip('old-mod', '1.1.0', 'module', ['core' => '^1.0']));
        $d = app(DeploymentService::class)->create($mod, [$i->id], []);
        $this->heartbeat($i, $secret, '1.0.0', [['slug' => 'old-mod', 'type' => 'module', 'version' => '1.2.0']]);
        $t = $d->targets()->first();
        $this->signed($i, $secret, 'POST', "/api/v1/deployments/{$t->id}/accept")->assertStatus(409)->assertJson(['error' => 'incompatible']);
        $this->assertSame('failed', $t->fresh()->status);
    }

    public function test_scheduled_cancelled_and_withdrawn_deployments(): void
    {
        [$i, $secret] = $this->connectedInstallation();
        $release = $this->publishedRelease($this->coreZip('1.1.0'));
        $service = app(DeploymentService::class);

        $d = $service->create($release, [$i->id], ['scheduled_at' => now()->addHour()->toDateTimeString()]);
        $this->assertSame('scheduled', $d->status);
        $this->heartbeat($i, $secret)->assertJson(['instructions' => []]);
        $this->travel(61)->minutes();
        $service->tick();
        $this->assertSame('running', $d->fresh()->status);
        $this->heartbeat($i, $secret)->assertJsonPath('instructions.0.type', 'deploy');

        $this->assertSame(1, $service->cancel($d->fresh()));
        $this->assertSame('cancelled', $d->fresh()->status);
        $this->heartbeat($i, $secret)->assertJson(['instructions' => []]);

        // Withdrawing cancels queued targets of every deployment of that release.
        $d2 = $service->create($release, [$i->id], []);
        app(ReleaseService::class)->withdraw($release);
        $this->assertSame('cancelled', $d2->targets()->first()->status);
    }

    public function test_silent_installations_become_no_response_not_success(): void
    {
        [$i, $secret] = $this->connectedInstallation();
        $d = app(DeploymentService::class)->create($this->publishedRelease($this->coreZip('1.1.0')), [$i->id], []);
        $t = $d->targets()->first();
        $this->signed($i, $secret, 'POST', "/api/v1/deployments/{$t->id}/accept")->assertOk();

        $this->travel(181)->minutes();
        app(DeploymentService::class)->tick();

        $this->assertSame('no_response', $t->fresh()->status);
        $this->assertSame('failed', $d->fresh()->status);
        $this->assertSame('1.0.0', $i->fresh()->core_version);
    }

    public function test_rollback_is_requested_and_confirmed_by_the_site(): void
    {
        [$i, $secret] = $this->connectedInstallation();
        $release = $this->publishedRelease($this->coreZip('1.1.0'), ['database_rollback' => true]);
        $d = app(DeploymentService::class)->create($release, [$i->id], []);
        $t = $d->targets()->first();
        $this->signed($i, $secret, 'POST', "/api/v1/deployments/{$t->id}/accept");
        $this->signed($i, $secret, 'POST', "/api/v1/deployments/{$t->id}/complete", ['result' => 'succeeded', 'version' => '1.1.0']);

        app(DeploymentService::class)->requestRollback($t->fresh());
        $this->heartbeat($i, $secret, '1.1.0')->assertJsonPath('instructions.0.type', 'rollback');
        $this->signed($i, $secret, 'POST', "/api/v1/deployments/{$t->id}/accept")->assertOk()->assertJson(['action' => 'rollback', 'rollback_database' => true]);
        $this->assertSame('rolling_back', $t->fresh()->status);
        $this->signed($i, $secret, 'POST', "/api/v1/deployments/{$t->id}/complete", ['result' => 'rolled_back'])->assertOk();

        $this->assertSame('rolled_back', $t->fresh()->status);
        $this->assertSame('1.0.0', $i->fresh()->core_version);
    }

    public function test_admin_pages_render_and_require_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/api/v1/updates')->assertStatus(401);

        $this->actingAs($this->admin());
        [$i] = $this->connectedInstallation();
        $release = $this->publishedRelease($this->coreZip('1.1.0'));
        $d = app(DeploymentService::class)->create($release, [$i->id], []);

        foreach (['/', '/clients', "/clients/{$i->client_id}", '/clients/create', '/installations', "/installations/{$i->id}", '/installations/create',
            '/releases', "/releases/{$release->id}", '/releases/create', '/deployments', '/deployments/create', "/deployments/{$d->id}"] as $url) {
            $this->get($url)->assertOk();
        }
        $this->post('/deployments/preview', ['release_id' => $release->id, 'installations' => [$i->id]])->assertOk();
    }
}
