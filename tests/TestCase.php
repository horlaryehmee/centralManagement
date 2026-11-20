<?php

namespace Tests;

use App\Models\Client;
use App\Models\Installation;
use App\Models\Release;
use App\Models\User;
use App\Services\ReleaseService;
use App\Services\Signer;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use ZipArchive;

abstract class TestCase extends BaseTestCase
{
    protected string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/central-test-'.Str::random(8);
        @mkdir($this->tmp, 0777, true);
        config([
            'central.signing_key_path' => $this->tmp.'/signing.key',
            'central.allow_insecure_api' => true,
            'filesystems.disks.local.root' => $this->tmp.'/storage',
        ]);
        app()->forgetInstance(Signer::class);
        app(Signer::class)->generate();
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmp);
        parent::tearDown();
    }

    protected function admin(): User
    {
        return User::factory()->create();
    }

    /** A connected installation and its plain secret. */
    protected function connectedInstallation(?Client $client = null, array $attrs = []): array
    {
        $client ??= Client::create(['name' => 'Client '.Str::random(4)]);
        $installation = Installation::create(['client_id' => $client->id, 'name' => 'Site '.Str::random(4), 'domain' => Str::lower(Str::random(6)).'.test'] + $attrs);
        $code = $installation->issueEnrollmentCode();
        $res = $this->postJson('/api/v1/register', ['code' => $code, 'site_url' => 'https://'.$installation->domain, 'version' => $attrs['core_version'] ?? '1.0.0'])->assertOk();

        return [$installation->fresh(), $res->json('secret')];
    }

    /** Send a request signed exactly like the update agent does. */
    protected function signed(Installation $installation, string $secret, string $method, string $path, array $body = [], array $overrides = []): TestResponse
    {
        $json = $method === 'GET' ? '' : json_encode($body);
        $ts = (string) ($overrides['timestamp'] ?? time());
        $nonce = $overrides['nonce'] ?? Str::random(32);
        $sig = hash_hmac('sha256', implode("\n", [$method, ltrim($path, '/'), $ts, $nonce, hash('sha256', $json)]), $overrides['secret'] ?? $secret);
        $server = $this->transformHeadersToServerVars([
            'X-Installation-Id' => $overrides['id'] ?? $installation->uuid, 'X-Timestamp' => $ts, 'X-Nonce' => $nonce, 'X-Signature' => $sig,
            'Accept' => 'application/json', 'Content-Type' => 'application/json',
        ]);

        return $this->call($method, $path, [], [], [], $server, $json);
    }

    protected function heartbeat(Installation $i, string $secret, string $version = '1.0.0', array $modules = []): TestResponse
    {
        return $this->signed($i, $secret, 'POST', '/api/v1/heartbeat', ['version' => $version, 'php' => '8.3.0', 'modules' => $modules]);
    }

    /** Build a package zip on disk. */
    protected function zip(array $files): string
    {
        $path = $this->tmp.'/pkg-'.Str::random(6).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    protected function coreZip(string $version): string
    {
        return $this->zip(['VERSION' => $version, 'artisan' => 'php', 'vendor/autoload.php' => '<?php', 'bootstrap/app.php' => '<?php', 'public/build/manifest.json' => '{}', 'app/Feature.php' => "<?php // {$version}"]);
    }

    protected function moduleZip(string $slug, string $version, string $type = 'module', array $requires = []): string
    {
        return $this->zip(['module.json' => json_encode(['slug' => $slug, 'name' => ucfirst($slug), 'version' => $version, 'type' => $type, 'requires' => $requires]), 'src/Provider.php' => '<?php']);
    }

    protected function publishedRelease(string $zip, array $data = []): Release
    {
        $service = app(ReleaseService::class);

        return $service->publish($service->createFromPackage($zip, $data))->fresh();
    }

    protected function rmrf(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach (glob($path.'/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $item) {
            $this->rmrf($item);
        }
        @rmdir($path);
    }
}
