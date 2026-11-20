<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GithubSource;
use App\Models\Release;
use App\Services\GithubImporter;
use App\Support\Installer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubImportTest extends TestCase
{
    use RefreshDatabase;

    protected function fakeGithub(array $releases, array $zips): void
    {
        Http::swap(new HttpFactory);
        Http::fake(function ($request) use ($releases, $zips) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (preg_match('~/releases/assets/(\d+)$~', $path, $m)) {
                return Http::response(file_get_contents($zips[$m[1]]), 200, ['Content-Type' => 'application/octet-stream']);
            }
            if (preg_match('~/repos/acme/catalog/releases/(\d+)$~', $path, $m)) {
                return Http::response(collect($releases)->firstWhere('id', (int) $m[1]));
            }
            if (str_ends_with($path, '/repos/acme/catalog/releases')) {
                return Http::response($releases);
            }

            return Http::response(['message' => 'Not Found'], 404);
        });
    }

    protected function ghRelease(int $id, string $tag, ?string $zip, array $extra = []): array
    {
        return [
            'id' => $id, 'tag_name' => $tag, 'name' => "Release {$tag}", 'body' => "Notes for {$tag}", 'draft' => false, 'prerelease' => false,
            'published_at' => now()->toIso8601String(), 'html_url' => "https://github.com/acme/catalog/releases/tag/{$tag}",
            'assets' => $zip ? [
                ['name' => "catalog-{$tag}.zip.sha256", 'size' => 64, 'url' => "https://api.github.com/repos/acme/catalog/releases/assets/9{$id}"],
                ['name' => "catalog-{$tag}.zip", 'size' => filesize($zip), 'url' => "https://api.github.com/repos/acme/catalog/releases/assets/{$id}", 'digest' => 'sha256:'.hash_file('sha256', $zip)],
            ] : [],
        ] + $extra;
    }

    public function test_releases_are_listed_and_imported_with_their_notes(): void
    {
        $zip = $this->coreZip('1.2.0');
        $this->fakeGithub([$this->ghRelease(11, 'v1.2.0', $zip), $this->ghRelease(10, 'v1.1.0', null)], [11 => $zip]);
        $source = GithubSource::create(['repository' => 'acme/catalog', 'type' => 'core', 'token' => 'ghp_secret']);

        $list = app(GithubImporter::class)->list($source);
        $this->assertSame(['v1.2.0', 'v1.1.0'], array_column($list, 'tag'));
        $this->assertSame('catalog-v1.2.0.zip', $list[0]['asset']['name']); // the .sha256 file is skipped
        $this->assertNotNull($list[1]['problem']);

        $release = app(GithubImporter::class)->import($source, 11);
        $this->assertSame(['core', '1.2.0', 'draft', 'Notes for v1.2.0'], [$release->type, $release->version, $release->status, $release->notes]);
        $this->assertSame(11, $release->github_release_id);
        $this->assertSame($release->id, app(GithubImporter::class)->list($source)[0]['release_id']);
        // Importing again is a no-op.
        $this->assertSame($release->id, app(GithubImporter::class)->import($source, 11)->id);

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer ghp_secret'));
    }

    public function test_auto_publish_signs_the_imported_release(): void
    {
        $zip = $this->coreZip('1.3.0');
        $this->fakeGithub([$this->ghRelease(12, 'v1.3.0', $zip)], [12 => $zip]);
        $source = GithubSource::create(['repository' => 'acme/catalog', 'type' => 'core', 'auto_publish' => true]);

        $release = app(GithubImporter::class)->import($source, 12);
        $this->assertSame('published', $release->status);
        $this->assertNotEmpty($release->signature);
    }

    public function test_mismatched_tag_wrong_type_and_damaged_downloads_are_refused(): void
    {
        $zip = $this->coreZip('1.4.0');
        $other = $this->coreZip('9.9.9');
        $this->fakeGithub([
            $this->ghRelease(13, 'v1.5.0', $zip),                                   // tag says 1.5.0, package says 1.4.0
            array_replace($this->ghRelease(14, 'v1.4.0', $zip), []),
        ], [13 => $zip, 14 => $other]);                                             // asset 14 serves different bytes than its digest
        $core = GithubSource::create(['repository' => 'acme/catalog', 'type' => 'core']);
        $moduleRepo = GithubSource::create(['repository' => 'acme/catalog', 'type' => 'module']);

        foreach ([[$core, 13, 'says version 1.5.0'], [$core, 14, "does not match GitHub's checksum"]] as [$src, $id, $msg]) {
            try {
                app(GithubImporter::class)->import($src, $id);
                $this->fail('Should have been refused');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertStringContainsString($msg, collect($e->errors())->flatten()->implode(' '));
            }
        }

        $this->fakeGithub([$this->ghRelease(15, 'v1.4.0', $zip)], [15 => $zip]);
        try {
            app(GithubImporter::class)->import($moduleRepo, 15);
            $this->fail('A core package in a module source should be refused');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('type "core"', collect($e->errors())->flatten()->implode(' '));
        }
        $this->assertSame(0, Release::count());
    }

    public function test_sync_imports_everything_new_and_records_problems(): void
    {
        $a = $this->coreZip('2.0.0');
        $b = $this->coreZip('2.1.0');
        $this->fakeGithub([$this->ghRelease(21, 'v2.1.0', $b), $this->ghRelease(20, 'v2.0.0', $a)], [20 => $a, 21 => $b]);
        $source = GithubSource::create(['repository' => 'acme/catalog', 'type' => 'core', 'auto_import' => true]);

        $this->artisan('central:github-sync')->assertSuccessful();
        $this->assertSame(['2.0.0', '2.1.0'], Release::orderBy('id')->pluck('version')->all());
        $this->assertNotNull($source->fresh()->last_checked_at);
        $this->assertSame([], app(GithubImporter::class)->sync($source)['imported']);
    }

    public function test_the_github_page_connects_a_repository(): void
    {
        $this->actingAs($this->admin());
        $this->fakeGithub([], []);
        $this->post('/github', ['repository' => 'https://github.com/acme/catalog', 'type' => 'core', 'token' => 'ghp_x', 'auto_import' => true])->assertRedirect();
        $source = GithubSource::first();
        $this->assertSame('acme/catalog', $source->repository);
        $this->assertStringNotContainsString('ghp_x', (string) \DB::table('github_sources')->value('token'));
        $this->get("/github?source={$source->id}")->assertOk();

        $this->post('/github', ['repository' => 'acme/missing', 'type' => 'core'])->assertSessionHasErrors(['repository']);
        $this->post('/github', ['repository' => 'acme/catalog', 'type' => 'extension'])->assertSessionHasErrors(['client_id']);
        $this->post('/github', ['repository' => 'acme/catalog', 'type' => 'extension', 'client_id' => Client::create(['name' => 'X'])->id])->assertRedirect();
    }

    public function test_installer_env_writer_quotes_values_safely(): void
    {
        $env = Installer::setValues("APP_NAME=Old\nDB_PASSWORD=\n", ['APP_NAME' => 'My "Shop" #1', 'DB_PASSWORD' => 'p@ss$word\\x', 'NEW_KEY' => true]);
        $this->assertStringContainsString('APP_NAME="My \"Shop\" #1"', $env);
        $this->assertStringContainsString('DB_PASSWORD="p@ss\$word\\\\x"', $env);
        $this->assertStringContainsString("NEW_KEY=true\n", $env);

        // Round trip through the same parser Laravel uses.
        $parsed = \Dotenv\Dotenv::parse($env);
        $this->assertSame('My "Shop" #1', $parsed['APP_NAME']);
        $this->assertSame('p@ss$word\\x', $parsed['DB_PASSWORD']);
    }
}
