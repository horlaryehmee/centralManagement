<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\Installation;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BackupFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central.backups_disk' => 'local', 'filesystems.disks.local.root' => $this->tmp.'/storage']);
    }

    protected function admin(): User
    {
        return User::query()->firstOrCreate(['email' => 'admin@central.test'], ['name' => 'Admin', 'password' => bcrypt('password')]);
    }

    /** A raw (binary) body signed exactly like the agent does. */
    protected function signedRaw(Installation $i, string $secret, string $path, string $body, array $headers = []): TestResponse
    {
        $ts = (string) time();
        $nonce = Str::random(32);
        $sig = hash_hmac('sha256', implode("\n", ['POST', ltrim($path, '/'), $ts, $nonce, hash('sha256', $body)]), $secret);

        return $this->call('POST', $path, [], [], [], $this->transformHeadersToServerVars([
            'X-Installation-Id' => $i->uuid, 'X-Timestamp' => $ts, 'X-Nonce' => $nonce, 'X-Signature' => $sig,
            'Accept' => 'application/json', 'Content-Type' => 'application/octet-stream', ...$headers,
        ]), $body);
    }

    protected function heartbeatWith(Installation $i, string $secret, array $capabilities = ['backup']): TestResponse
    {
        return $this->signed($i, $secret, 'POST', '/api/v1/heartbeat', ['version' => '1.3.1', 'php' => '8.3.0', 'modules' => [], 'capabilities' => $capabilities]);
    }

    public function test_a_backup_is_requested_uploaded_in_chunks_stored_encrypted_and_downloaded(): void
    {
        [$i, $secret] = $this->connectedInstallation();
        $this->actingAs($this->admin());

        // Old agents (no "backup" capability): no button, no instruction.
        $this->heartbeatWith($i, $secret, [])->assertJson(['instructions' => []]);
        $this->post("/installations/{$i->id}/backups", ['include_files' => true])->assertSessionHas('error');
        $this->assertSame(0, Backup::count());

        $this->heartbeatWith($i, $secret)->assertOk();
        $this->post("/installations/{$i->id}/backups", ['include_files' => false])->assertSessionHas('success');
        $this->post("/installations/{$i->id}/backups")->assertSessionHas('error'); // one at a time
        $backup = Backup::sole();

        $this->heartbeatWith($i, $secret)->assertJson(['instructions' => [['type' => 'backup', 'target' => $backup->id, 'include_files' => false]]]);
        $accept = $this->signed($i, $secret, 'POST', "/api/v1/backups/{$backup->id}/accept")->assertOk()->json();
        $this->assertSame('delivered', $backup->fresh()->status);
        $this->assertGreaterThan(0, $accept['chunk_size']);

        // A 2.5 MB "zip" in three chunks; one chunk sent twice, one sent too far ahead.
        $data = random_bytes(2_500_000);
        $chunks = str_split($data, 1_000_000);
        $this->signedRaw($i, $secret, "/api/v1/backups/{$backup->id}/chunk", $chunks[0], ['X-Offset' => '0'])->assertJson(['received' => 1_000_000]);
        $this->signedRaw($i, $secret, "/api/v1/backups/{$backup->id}/chunk", $chunks[0], ['X-Offset' => '0'])->assertJson(['received' => 1_000_000]);
        $this->signedRaw($i, $secret, "/api/v1/backups/{$backup->id}/chunk", $chunks[2], ['X-Offset' => '2000000'])->assertStatus(409)->assertJson(['error' => 'offset_mismatch', 'received' => 1_000_000]);
        $this->signedRaw($i, $secret, "/api/v1/backups/{$backup->id}/chunk", $chunks[1], ['X-Offset' => '1000000'])->assertJson(['received' => 2_000_000]);
        $this->signedRaw($i, $secret, "/api/v1/backups/{$backup->id}/chunk", $chunks[2], ['X-Offset' => '2000000'])->assertJson(['received' => 2_500_000]);

        $this->signed($i, $secret, 'POST', "/api/v1/backups/{$backup->id}/complete", ['result' => 'completed', 'size' => 2_500_000, 'sha256' => hash('sha256', $data), 'contents' => ['database' => true, 'files' => 0, 'version' => '1.3.1']])
            ->assertOk()->assertJson(['ok' => true, 'status' => 'completed']);

        $backup->refresh();
        $this->assertSame('completed', $backup->status);
        $this->assertTrue($backup->encrypted);
        $stored = file_get_contents(app(BackupService::class)->disk()->path($backup->path));
        $this->assertStringNotContainsString(substr($data, 1000, 64), $stored, 'Stored encrypted, not as-is.');

        // Download gives back the exact original bytes.
        $response = $this->get("/backups/{$backup->id}/download")->assertOk();
        $this->assertSame(hash('sha256', $data), hash('sha256', $response->streamedContent()));

        // Shown on the installation page; no more instructions.
        $this->get("/installations/{$i->id}")->assertInertia(fn ($p) => $p->where('backups.supported', true)->where('backups.items.0.status', 'completed'));
        $this->heartbeatWith($i, $secret)->assertJson(['instructions' => []]);
    }

    public function test_damaged_uploads_fail_and_other_sites_cannot_touch_a_backup(): void
    {
        [$i, $secret] = $this->connectedInstallation();
        [$other, $otherSecret] = $this->connectedInstallation();
        $this->heartbeatWith($i, $secret);
        $backup = $i->backups()->create(['include_files' => true]);

        $this->signed($other, $otherSecret, 'POST', "/api/v1/backups/{$backup->id}/accept")->assertNotFound();
        $this->signed($i, $secret, 'POST', "/api/v1/backups/{$backup->id}/accept")->assertOk();
        $this->signedRaw($i, $secret, "/api/v1/backups/{$backup->id}/chunk", 'hello world', ['X-Offset' => '0'])->assertOk();
        $this->signedRaw($other, $otherSecret, "/api/v1/backups/{$backup->id}/chunk", 'evil', ['X-Offset' => '11'])->assertNotFound();
        // Tampered body vs signature.
        $this->signedRaw($i, $secret, "/api/v1/backups/{$backup->id}/chunk", 'x', ['X-Offset' => '11', 'X-Signature' => str_repeat('0', 64)])->assertStatus(401);

        $this->signed($i, $secret, 'POST', "/api/v1/backups/{$backup->id}/complete", ['result' => 'completed', 'size' => 11, 'sha256' => str_repeat('a', 64)])
            ->assertJson(['ok' => false, 'status' => 'failed']);
        $this->assertStringContainsString('checksum', $backup->fresh()->error);
        $this->signed($i, $secret, 'POST', "/api/v1/backups/{$backup->id}/accept")->assertStatus(409);
    }

    public function test_requests_the_website_never_picks_up_expire(): void
    {
        [$i, $secret] = $this->connectedInstallation();
        $this->heartbeatWith($i, $secret);
        $backup = $i->backups()->create(['include_files' => true]);
        $backup->forceFill(['created_at' => now()->subHours(25)])->saveQuietly();

        $this->heartbeatWith($i, $secret)->assertJson(['instructions' => []]);
        $this->assertSame('expired', $backup->fresh()->status);
    }

    public function test_old_backups_are_pruned_and_can_be_deleted(): void
    {
        config(['central.backups_keep' => 2]);
        [$i, $secret] = $this->connectedInstallation();
        $this->heartbeatWith($i, $secret);
        $service = app(BackupService::class);

        foreach (range(1, 3) as $n) {
            $b = $i->backups()->create(['include_files' => false]);
            $service->accept($b);
            $service->appendChunk($b, 0, "backup {$n}");
            $service->complete($b->fresh('installation'), strlen("backup {$n}"), hash('sha256', "backup {$n}"), []);
        }
        $this->assertSame(2, $i->backups()->where('status', 'completed')->count());

        $this->actingAs($this->admin());
        $latest = $i->backups()->latest('id')->first();
        $path = $service->disk()->path($latest->path);
        $this->delete("/backups/{$latest->id}")->assertSessionHas('success');
        $this->assertFileDoesNotExist($path);
        $this->assertSame(1, $i->backups()->count());
    }
}
