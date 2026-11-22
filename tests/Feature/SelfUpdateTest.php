<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SelfUpdate\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Runs real self-updates of the central app against a throwaway copy of a "live
 * install" in a temp folder, with GitHub faked. Checks the files that must change do, and the client's
 * own files (.env, storage, custom public files) never do.
 */
class SelfUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected string $tmp;

    protected string $site;

    protected string $zipBytes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::query()->create(['name' => 'Admin', 'email' => 'admin@central.test', 'password' => bcrypt('password')]));

        $this->tmp = sys_get_temp_dir().'/selfupdate-test-'.Str::random(8);
        $this->site = $this->tmp.'/site';

        // The "live site", version 1.0.0.
        $this->write('VERSION', "1.0.0\n");
        $this->write('artisan', 'old artisan');
        $this->write('app/Old.php', 'old');
        $this->write('vendor/autoload.php', 'old vendor');
        $this->write('bootstrap/app.php', 'old bootstrap');
        $this->write('.env', 'APP_KEY=client-secret');
        $this->write('storage/app/public/photo.jpg', 'client upload');
        $this->write('public/build/manifest.json', '{"old":true}');
        $this->write('public/index.php', 'custom public_html index');
        $this->write('public/.htaccess', '# cPanel PHP handler');

        // The release package, version 1.1.0.
        $this->zipBytes = $this->makeZip([
            'VERSION' => "1.1.0\n",
            'artisan' => 'new artisan',
            'app/New.php' => 'new',
            'vendor/autoload.php' => 'new vendor',
            'bootstrap/app.php' => 'new bootstrap',
            '.env' => 'APP_KEY=from-package',
            'storage/app/public/photo.jpg' => 'package file',
            'public/build/manifest.json' => '{"new":true}',
            'public/index.php' => 'stock index',
            'public/.htaccess' => 'stock htaccess',
            'public/robots.txt' => 'new file',
        ]);

        config(['selfupdate.repository' => 'acme/catalog', 'updater.token' => 'secret-token']);
        $this->app->instance(Updater::class, new Updater($this->site, $this->site.'/public', $this->tmp.'/work'));
        $this->fakeGithub('v1.1.0', hash('sha256', $this->zipBytes));
    }

    protected function tearDown(): void
    {
        app(Updater::class)->bringOnline();
        $this->rmrf($this->tmp);
        parent::tearDown();
    }

    public function test_a_full_update_replaces_code_and_keeps_client_files(): void
    {
        $this->get('/updates')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Updates')
            ->where('status.current', '1.0.0')
            ->where('status.latest', '1.1.0')
            ->where('status.available', true)
            ->where('source.effective', 'acme/catalog'));

        foreach (Updater::STEPS as $step) {
            $this->postJson("/updates/step/{$step}")->assertOk()->assertJsonStructure(['message']);
        }

        // Code replacedâ€¦
        $this->assertSame("1.1.0\n", $this->read('VERSION'));
        $this->assertSame('new vendor', $this->read('vendor/autoload.php'));
        $this->assertFileExists($this->site.'/app/New.php');
        $this->assertFileDoesNotExist($this->site.'/app/Old.php');
        $this->assertSame('{"new":true}', $this->read('public/build/manifest.json'));
        $this->assertSame('new file', $this->read('public/robots.txt'));

        // â€¦client files untouched.
        $this->assertSame('APP_KEY=client-secret', $this->read('.env'));
        $this->assertSame('client upload', $this->read('storage/app/public/photo.jpg'));
        $this->assertSame('custom public_html index', $this->read('public/index.php'));
        $this->assertSame('# cPanel PHP handler', $this->read('public/.htaccess'));

        // Online again, work files cleaned up, a restorable backup with a database dump kept.
        $this->assertFalse(app(Updater::class)->isDown());
        $this->assertNull(app(Updater::class)->pending());
        $this->assertDirectoryDoesNotExist($this->tmp.'/work/staging');
        $backups = app(Updater::class)->backups();
        $this->assertCount(1, $backups);
        $this->assertSame('1.0.0', $backups[0]['version']);
        $this->assertSame('1.1.0', $backups[0]['replaced_by']);
        $this->get("/updates/backups/{$backups[0]['id']}/database")->assertOk()->assertDownload();

    }

    public function test_a_failed_install_puts_every_file_back(): void
    {
        $failing = new class($this->site, $this->site.'/public', $this->tmp.'/work') extends Updater
        {
            protected function move(string $from, string $to): void
            {
                if (str_ends_with($from, '/staging/vendor')) {
                    throw new RuntimeException('disk full');
                }
                parent::move($from, $to);
            }
        };
        $this->app->instance(Updater::class, $failing);

        foreach (['download', 'backup', 'extract'] as $step) {
            $this->postJson("/updates/step/{$step}")->assertOk();
        }
        $this->postJson('/updates/step/install')
            ->assertStatus(422)
            ->assertJson(['message' => 'Installing failed, so the previous version was put back. Nothing was changed. (disk full)']);

        $this->assertSame("1.0.0\n", $this->read('VERSION'));
        $this->assertSame('old vendor', $this->read('vendor/autoload.php'));
        $this->assertSame('old artisan', $this->read('artisan'));
        $this->assertFileExists($this->site.'/app/Old.php');
        $this->assertFileDoesNotExist($this->site.'/app/New.php');
        $this->assertSame('{"old":true}', $this->read('public/build/manifest.json'));
        $this->assertFalse($failing->isDown());
        // Can be retried: still waiting at the install step.
        $this->assertSame('extract', $failing->pending()['step']);
    }

    public function test_a_damaged_download_is_rejected(): void
    {
        $this->fakeGithub('v1.1.0', str_repeat('0', 64));

        $this->postJson('/updates/step/download')->assertStatus(422)->assertJsonFragment(['message' => 'The downloaded file is damaged (checksum mismatch). Please try again.']);
        $this->assertNull(app(Updater::class)->pending());
    }

    public function test_steps_cannot_run_out_of_order(): void
    {
        $this->postJson('/updates/step/install')->assertStatus(422)->assertJsonFragment(['message' => 'The update steps ran out of order. Please start the update again.']);
        $this->assertSame("1.0.0\n", $this->read('VERSION'));
    }

    public function test_up_to_date_and_error_states(): void
    {
        $this->fakeGithub('v1.0.0', null);
        $this->get('/updates?refresh=1')->assertInertia(fn (Assert $p) => $p->where('status.available', false)->where('status.error', null));

        Http::swap(new HttpFactory);
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);
        $this->get('/updates?refresh=1')->assertInertia(fn (Assert $p) => $p->where('status.available', false)->where('status.error', fn ($e) => str_contains($e, 'No published release found')));

        config(['selfupdate.repository' => null]);
        $this->get('/updates?refresh=1')->assertInertia(fn (Assert $p) => $p->where('status.error', fn ($e) => str_contains($e, 'No update source')));
    }

    public function test_update_source_is_saved_with_an_encrypted_token(): void
    {
        $this->put('/updates/source', ['repository' => 'https://github.com/my-co/shop.git', 'token' => 'ghp_abc'])->assertRedirect();

        $s = SystemSetting::query()->first();
        $this->assertSame('my-co/shop', $s->update_repository);
        $this->assertSame('ghp_abc', $s->update_token);
        $this->assertStringNotContainsString('ghp_abc', (string) \DB::table('system_settings')->value('update_token'));

        $this->get('/updates')->assertInertia(fn (Assert $p) => $p->where('source.has_token', true)->missing('source.token'));

        $this->put('/updates/source', ['repository' => 'not a repo'])->assertSessionHasErrors('repository');
    }

    public function test_unsafe_package_paths_are_rejected(): void
    {
        $this->zipBytes = $this->makeZip(['VERSION' => '1.1.0', '../evil.php' => 'x']);
        $this->fakeGithub('v1.1.0', hash('sha256', $this->zipBytes));

        $this->postJson('/updates/step/download')->assertOk();
        $this->postJson('/updates/step/backup')->assertOk();
        $this->postJson('/updates/step/extract')->assertStatus(422)->assertJsonFragment(['message' => 'The update package contains unsafe file paths and was rejected.']);
        $this->assertFileDoesNotExist($this->tmp.'/work/evil.php');
    }

    public function test_the_update_page_works_during_maintenance_but_the_rest_does_not(): void
    {
        $this->artisan('down');

        $this->get('/updates')->assertOk();
        $this->get('/')->assertStatus(503);
        $this->get('/installations')->assertStatus(503);

        $this->post('/updates/online')->assertRedirect();
        $this->get('/')->assertOk();
    }

    public function test_versions_compare_numerically(): void
    {
        $this->assertTrue(Updater::isNewer('v1.10.0', '1.9.2'));
        $this->assertFalse(Updater::isNewer('1.0.0', 'v1.0.0'));
        $this->assertSame('owner/repo', Updater::cleanRepository('github.com/owner/repo/'));
        $this->assertNull(Updater::cleanRepository('owner'));
    }

    /** Runs the update to the faked release; $table is created by a migration that ships with it. */
    protected function updateWithMigration(string $version, string $table, ?string $down = null): void
    {
        $dir = $this->tmp.'/migrations-'.$table;
        @mkdir($dir, 0777, true);
        $down ??= "Schema::dropIfExists('{$table}');";
        file_put_contents($dir.'/2099_01_01_00000'.strlen($table).'_create_'.$table.'.php', <<<PHP
        <?php
        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Support\\Facades\\Schema;
        return new class extends Migration {
            public function up(): void { Schema::create('{$table}', fn (\$t) => \$t->id()); }
            public function down(): void { {$down} }
        };
        PHP);
        $this->app['migrator']->path($dir);

        foreach (Updater::STEPS as $step) {
            $this->postJson("/updates/step/{$step}")->assertOk();
        }
        $this->assertSame("{$version}\n", $this->read('VERSION'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable($table));
    }

    /** 1.0.0 → 1.1.0 (adds table probe_a) → 1.2.0 (adds table probe_bb). */
    protected function twoUpdates(): void
    {
        $this->updateWithMigration('1.1.0', 'probe_a');
        $this->travel(5)->seconds();

        $this->zipBytes = $this->makeZip(['VERSION' => "1.2.0\n", 'artisan' => 'newer artisan', 'app/Newer.php' => 'newer', 'vendor/autoload.php' => 'newer vendor', 'bootstrap/app.php' => 'newer bootstrap', 'public/build/manifest.json' => '{"newer":true}']);
        $this->fakeGithub('v1.2.0', hash('sha256', $this->zipBytes));
        $this->updateWithMigration('1.2.0', 'probe_bb');
    }

    public function test_one_click_rollback_undoes_the_last_update_files_and_database(): void
    {
        $this->twoUpdates();
        $backups = app(Updater::class)->backups();
        $this->assertSame(['1.1.0', '1.0.0'], array_column($backups, 'version')); // two rollbacks available

        $this->postJson("/updates/backups/{$backups[0]['id']}/rollback")->assertOk()->assertJson(['version' => '1.1.0']);

        $this->assertSame("1.1.0\n", $this->read('VERSION'));
        $this->assertSame('new vendor', $this->read('vendor/autoload.php'));
        $this->assertFileDoesNotExist($this->site.'/app/Newer.php');
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('probe_bb'));  // 1.2.0's change undone
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('probe_a'));    // 1.1.0's change kept
        $this->assertSame('APP_KEY=client-secret', $this->read('.env'));
        $this->assertFalse(app(Updater::class)->isDown());
        // Still one more step back available.
        $this->assertSame(['1.0.0'], array_column(app(Updater::class)->backups(), 'version'));
    }

    public function test_rolling_back_two_versions_undoes_both_updates_in_order(): void
    {
        $this->twoUpdates();
        $oldest = collect(app(Updater::class)->backups())->firstWhere('version', '1.0.0');

        $this->postJson("/updates/backups/{$oldest['id']}/rollback")->assertOk()->assertJson(['version' => '1.0.0']);

        $this->assertSame("1.0.0\n", $this->read('VERSION'));
        $this->assertSame('old vendor', $this->read('vendor/autoload.php'));
        $this->assertFileExists($this->site.'/app/Old.php');
        $this->assertFileDoesNotExist($this->site.'/app/New.php');
        $this->assertFileDoesNotExist($this->site.'/app/Newer.php');
        $this->assertFileDoesNotExist($this->site.'/public/robots.txt');
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('probe_a'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('probe_bb'));
        $this->assertSame('custom public_html index', $this->read('public/index.php'));
        $this->assertSame([], app(Updater::class)->backups());
    }

    public function test_a_database_change_that_cannot_be_undone_leaves_the_site_as_it_was(): void
    {
        // This update's migration can't be undone.
        $this->updateWithMigration('1.1.0', 'probe_c', "throw new \\RuntimeException('cannot undo');");
        $backup = app(Updater::class)->backups()[0];

        $this->postJson("/updates/backups/{$backup['id']}/rollback")->assertStatus(422)
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'nothing was rolled back') && str_contains($m, 'cannot undo')));

        $this->assertSame("1.1.0\n", $this->read('VERSION'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('probe_c'));
        $this->assertFalse(app(Updater::class)->isDown());
        $this->assertCount(1, app(Updater::class)->backups()); // still available
    }

    public function test_at_least_two_backups_are_kept_and_old_ones_are_deleted(): void
    {
        config(['selfupdate.keep_backups' => 1]); // never fewer than 2
        $make = function (string $id, string $kind, string $slug) {
            $dir = $this->tmp.'/work/backups/'.$id;
            @mkdir($dir, 0777, true);
            file_put_contents($dir.'/manifest.json', json_encode(['kind' => $kind, 'slug' => $slug, 'from' => '1.0.0', 'entries' => [['name' => 'app', 'existed' => true]]]));
        };
        foreach (['2026-01-01_000001_v1.0.0', '2026-01-02_000001_v1.1.0', '2026-01-03_000001_v1.2.0', '2026-01-04_000001_v1.3.0'] as $id) {
            $make($id, 'core', 'core');
        }

        (fn () => $this->pruneBackups())->call(app(Updater::class));

        $left = array_map('basename', glob($this->tmp.'/work/backups/*'));
        sort($left);
        $this->assertSame(['2026-01-03_000001_v1.2.0', '2026-01-04_000001_v1.3.0'], $left);
    }

    /* helpers */

    protected function fakeGithub(string $tag, ?string $digest): void
    {
        // Earlier fakes take priority over later ones, so start from a clean client.
        Http::swap(new HttpFactory);
        Http::fake([
            'api.github.com/repos/acme/catalog/releases/latest' => Http::response([
                'tag_name' => $tag,
                'name' => $tag,
                'body' => "## Changes\n- Faster pages",
                'published_at' => '2026-11-01T10:00:00Z',
                'html_url' => "https://github.com/acme/catalog/releases/{$tag}",
                'assets' => [[
                    'id' => 1,
                    'name' => "central-{$tag}.zip",
                    'size' => strlen($this->zipBytes),
                    'url' => 'https://api.github.com/repos/acme/catalog/releases/assets/1',
                    'digest' => $digest ? "sha256:{$digest}" : null,
                ]],
            ]),
            // Like the real API: the file only when octet-stream is the *only* accepted type,
            // otherwise the asset's JSON description.
            'api.github.com/repos/acme/catalog/releases/assets/1' => fn ($request) => $request->header('Accept') === ['application/octet-stream']
                ? Http::response($this->zipBytes, 200, ['Content-Type' => 'application/octet-stream'])
                : Http::response(['id' => 1, 'name' => "central-{$tag}.zip", 'state' => 'uploaded']),
        ]);
        cache()->forget('selfupdate.latest');
    }

    protected function makeZip(array $files): string
    {
        $path = $this->tmp.'/build-'.Str::random(4).'.zip';
        @mkdir($this->tmp, 0777, true);
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    protected function write(string $relative, string $content): void
    {
        @mkdir(dirname($this->site.'/'.$relative), 0777, true);
        file_put_contents($this->site.'/'.$relative, $content);
    }

    protected function read(string $relative): string
    {
        return file_get_contents($this->site.'/'.$relative);
    }

    protected function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (glob($path.'/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $item) {
            $this->rmrf($item);
        }
        @rmdir($path);
    }
}
