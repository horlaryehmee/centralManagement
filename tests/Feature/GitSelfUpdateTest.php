<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SelfUpdate\GitUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real Git, no GitHub: a bare repository stands in for GitHub, a "dev" clone pushes
 * commits to it, and a "live" folder is the installed app that updates itself.
 * A tiny fake Composer records when it runs.
 */
class GitSelfUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected string $origin;

    protected string $dev;

    protected string $live;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::query()->create(['name' => 'Admin', 'email' => 'admin@central.test', 'password' => bcrypt('password')]));

        $this->origin = $this->tmp.'/origin.git';
        $this->dev = $this->tmp.'/dev';
        $this->live = $this->tmp.'/live';

        $this->sh(['git', 'init', '-q', '--bare', '-b', 'main', $this->origin], $this->tmp);
        $this->sh(['git', 'clone', '-q', $this->origin, $this->dev], $this->tmp);
        $this->commit([
            'VERSION' => "1.0.0\n",
            'app/Feature.php' => '<?php // version one',
            'composer.json' => '{"name":"acme/central"}',
            'composer.lock' => '{"packages":[]}',
            '.gitignore' => "/vendor\n.env\n/storage\n",
        ], 'First version');

        // The installed app: a clone with its own .env, storage and installed packages.
        $this->sh(['git', 'clone', '-q', $this->origin, $this->live], $this->tmp);
        file_put_contents($this->live.'/.env', 'APP_KEY=live-secret');
        @mkdir($this->live.'/storage/app', 0777, true);
        file_put_contents($this->live.'/storage/app/signing.key', 'keep me');
        @mkdir($this->live.'/vendor', 0777, true);
        file_put_contents($this->live.'/vendor/autoload.php', '<?php // installed');

        // A fake Composer: "--version" answers like Composer; "install" leaves a marker (or fails).
        $fake = $this->tmp.'/fake-composer.php';
        file_put_contents($fake, '<?php if (in_array("--version", $argv)) { echo "Composer version 2.8.0\n"; exit(0); } if (getenv("FAKE_COMPOSER_FAIL") || file_exists(getcwd()."/FAIL_COMPOSER")) { fwrite(STDERR, "Your requirements could not be resolved"); exit(2); } @mkdir(getcwd()."/vendor"); file_put_contents(getcwd()."/vendor/installed-by-composer.txt", file_get_contents(getcwd()."/composer.lock")); file_put_contents(getcwd()."/vendor/autoload.php", "<?php // installed"); exit(0);');

        config([
            'selfupdate.repository' => 'acme/central',
            'selfupdate.remote' => $this->origin,
            'selfupdate.branch' => 'main',
            'selfupdate.php_binary' => PHP_BINARY,
            'selfupdate.composer_binary' => $fake,
        ]);
        $this->app->instance(GitUpdater::class, new GitUpdater($this->live, $this->tmp.'/work'));
    }

    protected function tearDown(): void
    {
        app(GitUpdater::class)->bringOnline();
        parent::tearDown();
    }

    protected function sh(array $cmd, string $cwd): string
    {
        $p = new Process($cmd, $cwd, ['GIT_AUTHOR_NAME' => 'Dev', 'GIT_AUTHOR_EMAIL' => 'dev@example.test', 'GIT_COMMITTER_NAME' => 'Dev', 'GIT_COMMITTER_EMAIL' => 'dev@example.test']);
        $p->mustRun();

        return trim($p->getOutput());
    }

    /** Commit files in the dev clone and push to "GitHub". */
    protected function commit(array $files, string $message): void
    {
        foreach ($files as $path => $content) {
            @mkdir(dirname($this->dev.'/'.$path), 0777, true);
            file_put_contents($this->dev.'/'.$path, $content);
        }
        $this->sh(['git', 'add', '-A'], $this->dev);
        $this->sh(['git', '-c', 'core.autocrlf=false', 'commit', '-q', '-m', $message], $this->dev);
        $this->sh(['git', 'push', '-q', 'origin', 'HEAD:main'], $this->dev);
    }

    protected function migration(string $table): array
    {
        return ["database/migrations/2099_01_01_000000_create_{$table}.php" => "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\Schema;\nreturn new class extends Migration {\n    public function up(): void { Schema::create('{$table}', fn (\$t) => \$t->id()); }\n    public function down(): void { Schema::dropIfExists('{$table}'); }\n};\n"];
    }

    protected function runUpdate(array $fetch = []): void
    {
        $this->app['migrator']->path($this->live.'/database/migrations');
        foreach (GitUpdater::STEPS as $step) {
            $this->postJson("/updates/step/{$step}", $step === 'fetch' ? $fetch : [])->assertOk()->assertJsonStructure(['message']);
        }
    }

    public function test_a_new_commit_is_shown_and_installed_with_migrations_keeping_env_and_storage(): void
    {
        $this->get('/updates?refresh=1')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Updates')->where('connected', true)->where('status.available', false));

        $this->commit(['app/Feature.php' => '<?php // version two', 'VERSION' => "1.1.0\n", ...$this->migration('probe_a')], 'Add probe table');
        $this->get('/updates?refresh=1')->assertInertia(fn (Assert $p) => $p
            ->where('status.available', true)
            ->where('status.behind', 1)
            ->where('status.commits.0.subject', 'Add probe table'));

        $this->runUpdate();

        $this->assertSame('<?php // version two', file_get_contents($this->live.'/app/Feature.php'));
        $this->assertTrue(Schema::hasTable('probe_a'));
        // Not in Git: never touched.
        $this->assertSame('APP_KEY=live-secret', file_get_contents($this->live.'/.env'));
        $this->assertSame('keep me', file_get_contents($this->live.'/storage/app/signing.key'));
        // composer.lock didn't change: Composer didn't run.
        $this->assertFileDoesNotExist($this->live.'/vendor/installed-by-composer.txt');
        $this->assertFalse(app(GitUpdater::class)->isDown());
        $this->assertNull(app(GitUpdater::class)->pending());
        $this->assertCount(1, app(GitUpdater::class)->backups());
        $this->get('/updates?refresh=1')->assertInertia(fn (Assert $p) => $p->where('status.available', false));
    }

    public function test_composer_runs_only_when_the_dependencies_changed(): void
    {
        $this->commit(['composer.lock' => '{"packages":["new/package"]}'], 'Add a package');
        $this->runUpdate();

        $this->assertSame('{"packages":["new/package"]}', file_get_contents($this->live.'/vendor/installed-by-composer.txt'));
    }

    public function test_a_failed_composer_install_puts_the_previous_version_back(): void
    {
        $this->commit(['app/Feature.php' => '<?php // broken', 'composer.lock' => '{"packages":["missing/package"]}', 'FAIL_COMPOSER' => 'yes'], 'Bad dependency');
        $this->app['migrator']->path($this->live.'/database/migrations');

        foreach (['fetch', 'backup', 'pull'] as $step) {
            $this->postJson("/updates/step/{$step}")->assertOk();
        }
        $this->postJson('/updates/step/dependencies')->assertStatus(422)->assertJson(fn ($j) => $j->where('message', fn ($m) => str_contains($m, 'previous version was put back'))->etc());

        $this->assertSame('<?php // version one', file_get_contents($this->live.'/app/Feature.php'));
        $this->assertFalse(app(GitUpdater::class)->isDown());
        $this->assertNull(app(GitUpdater::class)->pending());
    }

    public function test_files_changed_on_the_server_block_the_update_until_replacing_is_confirmed(): void
    {
        file_put_contents($this->live.'/app/Feature.php', '<?php // hand edit');
        $this->commit(['VERSION' => "1.1.0\n"], 'Bump');

        $this->get('/updates?refresh=1')->assertInertia(fn (Assert $p) => $p->where('status.changed_files', ['app/Feature.php']));
        $this->postJson('/updates/step/fetch')->assertStatus(422)->assertJson(fn ($j) => $j->where('message', fn ($m) => str_contains($m, 'app/Feature.php'))->etc());
        $this->assertSame('<?php // hand edit', file_get_contents($this->live.'/app/Feature.php'));

        $this->runUpdate(['discard' => true]);
        $this->assertSame('<?php // version one', file_get_contents($this->live.'/app/Feature.php'));
        $this->assertSame('1.1.0', trim(file_get_contents($this->live.'/VERSION'))); // (Git on Windows may check out CRLF)
    }

    public function test_one_click_rollback_undoes_the_files_and_the_database(): void
    {
        $this->commit(['app/Feature.php' => '<?php // version two', ...$this->migration('probe_b')], 'Version two');
        $this->runUpdate();
        $this->assertTrue(Schema::hasTable('probe_b'));
        $backup = app(GitUpdater::class)->backups()[0];
        $this->assertSame('First version', $backup['subject']);

        $this->postJson("/updates/backups/{$backup['id']}/rollback")->assertOk();

        $this->assertSame('<?php // version one', file_get_contents($this->live.'/app/Feature.php'));
        $this->assertFalse(Schema::hasTable('probe_b'));
        $this->assertSame('APP_KEY=live-secret', file_get_contents($this->live.'/.env'));
        $this->assertSame([], app(GitUpdater::class)->backups());
    }

    public function test_a_zip_install_is_connected_without_changing_files_then_updated(): void
    {
        // Simulate an install made from the zip: same files, no .git.
        $this->rmrf($this->live.'/.git');
        $this->commit(['app/Feature.php' => '<?php // version two'], 'Version two');
        $this->get('/updates')->assertInertia(fn (Assert $p) => $p->where('connected', false));

        SystemSetting::current()->update(['update_token' => 'ghp_secret_token_123']);
        $this->post('/updates/connect')->assertRedirect()->assertSessionHas('success');

        $this->assertDirectoryExists($this->live.'/.git');
        $this->assertSame('<?php // version one', file_get_contents($this->live.'/app/Feature.php'), 'Connecting changes no file.');
        $this->assertStringNotContainsString('ghp_secret_token_123', file_get_contents($this->live.'/.git/config'), 'The token is never written into Git\'s files.');

        $this->runUpdate();
        $this->assertSame('<?php // version two', file_get_contents($this->live.'/app/Feature.php'));
    }

    public function test_steps_run_in_order_and_up_to_date_is_reported(): void
    {
        $this->postJson('/updates/step/pull')->assertStatus(422)->assertJson(['message' => 'The update steps ran out of order. Please start the update again.']);
        $this->postJson('/updates/step/fetch')->assertStatus(422)->assertJson(['message' => 'Central Management is already up to date.']);
    }

    public function test_update_source_is_saved_with_an_encrypted_token(): void
    {
        $this->put('/updates/source', ['repository' => 'https://github.com/my-co/central.git', 'branch' => 'release', 'token' => 'ghp_abc'])->assertRedirect();

        $s = SystemSetting::query()->first();
        $this->assertSame(['my-co/central', 'release', 'ghp_abc'], [$s->update_repository, $s->update_branch, $s->update_token]);
        $this->assertStringNotContainsString('ghp_abc', (string) \DB::table('system_settings')->value('update_token'));
        $this->put('/updates/source', ['repository' => 'not a repo'])->assertSessionHasErrors('repository');
        $this->put('/updates/source', ['branch' => '--upload-pack=evil'])->assertSessionHasErrors('branch');
    }

    public function test_the_update_page_works_during_maintenance(): void
    {
        $this->artisan('down');
        $this->get('/updates')->assertOk();
        $this->get('/installations')->assertStatus(503);
        $this->post('/updates/online')->assertRedirect();
        $this->get('/installations')->assertOk();
    }

    protected function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0666);
            @unlink($path);

            return;
        }
        foreach (glob($path.'/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $item) {
            $this->rmrf($item);
        }
        @rmdir($path);
    }
}
