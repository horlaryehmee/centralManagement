<?php

namespace App\Services;

use App\Models\Backup;
use App\Models\Event;
use App\Models\Installation;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Website backups: handing the request to the website, receiving its upload in
 * resumable chunks, checking it, and keeping it encrypted at rest.
 *
 * Encryption: libsodium secretstream (XChaCha20-Poly1305) in 1 MiB chunks, with a
 * key derived from APP_KEY. A changed or truncated file fails to decrypt instead of
 * producing a damaged backup. Losing APP_KEY means losing access to stored backups.
 */
class BackupService
{
    /** Plaintext bytes per encrypted chunk. */
    protected const CRYPT_CHUNK = 1048576;

    protected const MAGIC = "CMBK1\n";

    public function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(config('central.backups_disk'));
    }

    /** The "make a backup" instruction for the website's next check-in, if one is waiting. */
    public function instructionFor(Installation $installation): ?array
    {
        if (! $installation->supports('backup')) {
            return null;
        }
        $this->expireStale($installation);
        $backup = $installation->backups()->active()->oldest('id')->first();

        return $backup ? ['type' => 'backup', 'target' => $backup->id, 'include_files' => $backup->include_files] : null;
    }

    /** The website picked it up: how to send it. */
    public function accept(Backup $backup): array
    {
        if (! $backup->isActive()) {
            throw new ApiException('not_deliverable', 'This backup is no longer wanted.', 409);
        }
        if ($backup->status === 'requested') {
            $backup->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();
            Event::log('backup.delivered', 'The website is creating the backup.', ['installation' => $backup->installation, 'user' => null]);
        }

        return [
            'target' => $backup->id,
            'include_files' => $backup->include_files,
            'chunk_size' => $this->chunkSize(),
            'max_size' => (int) config('central.backup_max_mb') * 1048576,
            'received' => $backup->received_bytes,
        ];
    }

    /**
     * Append one chunk. Idempotent: a chunk sent again after a lost answer is
     * accepted without writing twice. Returns the number of bytes now received.
     */
    public function appendChunk(Backup $backup, int $offset, string $bytes): int
    {
        if (! in_array($backup->status, ['delivered', 'uploading'], true)) {
            throw new ApiException('not_deliverable', 'Accept the backup before uploading it.', 409);
        }
        $len = strlen($bytes);
        if ($len === 0 || $len > $this->chunkSize()) {
            throw new ApiException('bad_chunk', 'The chunk is empty or too big.', 422);
        }
        $max = (int) config('central.backup_max_mb') * 1048576;
        if ($offset + $len > $max) {
            $this->fail($backup, 'The backup is larger than the update server accepts ('.config('central.backup_max_mb').' MB).');
            throw new ApiException('too_large', 'The backup is too large for the update server.', 413);
        }

        $disk = $this->disk();
        $path = $disk->path($backup->partialPath());
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        clearstatcache(true, $path);
        $have = is_file($path) ? filesize($path) : 0;

        if ($offset > $have) {
            // A chunk went missing: tell the website where to continue from.
            throw new ApiException('offset_mismatch', 'Continue from byte '.$have.'.', 409, ['received' => $have]);
        }
        if ($offset + $len <= $have) {
            return $have; // already have this chunk
        }

        $handle = fopen($path, 'c+b');
        if (! $handle || ! flock($handle, LOCK_EX)) {
            throw new ApiException('storage', 'The update server could not store the backup.', 500);
        }
        try {
            ftruncate($handle, $offset);
            fseek($handle, $offset);
            if (fwrite($handle, $bytes) !== $len) {
                throw new ApiException('storage', 'The update server ran out of space for the backup.', 507);
            }
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $received = $offset + $len;
        $backup->forceFill(['status' => 'uploading', 'received_bytes' => $received])->save();

        return $received;
    }

    /** The website says it's all sent: check it, encrypt it, keep it. */
    public function complete(Backup $backup, int $size, string $sha256, array $contents): Backup
    {
        $disk = $this->disk();
        $partial = $disk->path($backup->partialPath());
        clearstatcache(true, $partial);
        if (! is_file($partial) || filesize($partial) !== $size) {
            return $this->fail($backup, 'The backup did not arrive completely ('.(is_file($partial) ? filesize($partial) : 0).' of '.$size.' bytes).');
        }
        if (! hash_equals(strtolower($sha256), hash_file('sha256', $partial))) {
            return $this->fail($backup, 'The backup arrived damaged (checksum mismatch).');
        }

        $final = 'backups/'.$backup->installation->uuid.'/'.$backup->id.'-'.now()->format('Ymd-His').'.zip'.($this->canEncrypt() ? '.enc' : '');
        $finalPath = $disk->path($final);
        if (! is_dir(dirname($finalPath))) {
            mkdir(dirname($finalPath), 0700, true);
        }
        try {
            $this->canEncrypt() ? $this->encryptFile($partial, $finalPath) : rename($partial, $finalPath);
        } catch (\Throwable $e) {
            @unlink($finalPath);
            report($e);

            return $this->fail($backup, 'The update server could not store the backup.');
        }
        @unlink($partial);

        $backup->forceFill([
            'status' => 'completed', 'size' => $size, 'sha256' => strtolower($sha256), 'path' => $final,
            'encrypted' => $this->canEncrypt(), 'contents' => $this->cleanContents($contents), 'completed_at' => now(), 'error' => null,
        ])->save();
        Event::log('backup.completed', 'Backup received ('.self::humanBytes($size).').', ['installation' => $backup->installation, 'user' => null]);
        $this->prune($backup->installation);

        return $backup;
    }

    public function fail(Backup $backup, string $error): Backup
    {
        $this->disk()->delete($backup->partialPath());
        $backup->forceFill(['status' => 'failed', 'error' => mb_substr($error, 0, 500)])->save();
        Event::log('backup.failed', 'Backup failed: '.$error, ['installation' => $backup->installation, 'user' => null, 'level' => 'warning']);

        return $backup;
    }

    /** Download the original zip (decrypted on the fly; a tampered file stops with an error). */
    public function download(Backup $backup): StreamedResponse
    {
        $disk = $this->disk();
        if ($backup->status !== 'completed' || ! $backup->path || ! $disk->exists($backup->path)) {
            abort(404);
        }
        $path = $disk->path($backup->path);
        $name = str($backup->installation->domain ?: $backup->installation->name)->slug().'-backup-'.$backup->completed_at->format('Y-m-d-His').'.zip';

        return response()->streamDownload(function () use ($backup, $path) {
            if (! $backup->encrypted) {
                readfile($path);

                return;
            }
            $this->decryptFile($path, fn (string $plain) => print $plain);
        }, $name, ['Content-Type' => 'application/zip', 'Content-Length' => (string) $backup->size, 'Cache-Control' => 'private, no-store']);
    }

    public function delete(Backup $backup): void
    {
        if ($backup->path) {
            $this->disk()->delete($backup->path);
        }
        $this->disk()->delete($backup->partialPath());
        $backup->delete();
    }

    /** Requests never picked up, and uploads that stopped, don't block new backups forever. */
    public function expireStale(Installation $installation): void
    {
        $installation->backups()->where('status', 'requested')->where('created_at', '<', now()->subHours((int) config('central.backup_pickup_hours')))
            ->get()->each(fn (Backup $b) => $b->forceFill(['status' => 'expired', 'error' => 'The website did not pick up the request. Is it connected and is its cron job running?'])->save());
        $installation->backups()->whereIn('status', ['delivered', 'uploading'])->where('updated_at', '<', now()->subHours((int) config('central.backup_stall_hours')))
            ->get()->each(fn (Backup $b) => $this->fail($b, 'The website stopped sending the backup.'));
    }

    /** Keep the newest N completed backups per website. */
    public function prune(Installation $installation): void
    {
        $keep = max(1, (int) config('central.backups_keep'));
        $installation->backups()->where('status', 'completed')->latest('id')->skip($keep)->take(1000)->get()->each(fn (Backup $b) => $this->delete($b));
    }

    /** Largest chunk the website should send, within this server's PHP request limit. */
    public function chunkSize(): int
    {
        $limit = self::iniBytes((string) ini_get('post_max_size'));
        $chunk = (int) config('central.backup_chunk_mb') * 1048576;

        return $limit > 0 ? max(262144, min($chunk, (int) ($limit * 0.8))) : $chunk;
    }

    public function canEncrypt(): bool
    {
        return function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push');
    }

    protected function key(): string
    {
        $appKey = (string) config('app.key');
        $raw = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7)) : $appKey;

        return hash_hkdf('sha256', $raw, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, 'central-management backups v1');
    }

    protected function encryptFile(string $from, string $to): void
    {
        $in = fopen($from, 'rb');
        $out = fopen($to, 'wb');
        if (! $in || ! $out) {
            throw new RuntimeException('Could not open the backup files.');
        }
        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key());
            fwrite($out, self::MAGIC.$header);
            $size = filesize($from);
            $done = 0;
            do {
                $plain = (string) fread($in, self::CRYPT_CHUNK);
                $done += strlen($plain);
                $tag = $done >= $size ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain, '', $tag);
                fwrite($out, pack('N', strlen($cipher)).$cipher);
            } while ($done < $size);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /** @param  callable(string): void  $write */
    public function decryptFile(string $path, callable $write): void
    {
        $in = fopen($path, 'rb');
        try {
            $magic = fread($in, strlen(self::MAGIC));
            $header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if ($magic !== self::MAGIC || strlen((string) $header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new RuntimeException('Not an encrypted backup.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key());
            while (true) {
                $lenBytes = fread($in, 4);
                if ($lenBytes === '' || $lenBytes === false) {
                    throw new RuntimeException('The backup file is incomplete.');
                }
                $len = unpack('N', $lenBytes)[1];
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, (string) fread($in, $len));
                if ($result === false) {
                    throw new RuntimeException('The backup file was changed or damaged.');
                }
                [$plain, $tag] = $result;
                $write($plain);
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    return;
                }
            }
        } finally {
            fclose($in);
        }
    }

    protected function cleanContents(array $contents): array
    {
        return [
            'database' => (bool) ($contents['database'] ?? false),
            'files' => (int) ($contents['files'] ?? 0),
            'version' => mb_substr((string) ($contents['version'] ?? ''), 0, 40),
        ];
    }

    public static function humanBytes(int $bytes): string
    {
        return $bytes >= 1073741824 ? round($bytes / 1073741824, 1).' GB' : ($bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB');
    }

    protected static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '0') {
            return 0;
        }
        $n = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $n * 1073741824,
            'M' => $n * 1048576,
            'K' => $n * 1024,
            default => $n,
        };
    }
}
