<?php

namespace App\Services;

use RuntimeException;

/**
 * Ed25519 signing of release manifests (PHP's built-in sodium extension).
 * The secret key lives in a file outside public/ (config central.signing_key_path);
 * installations receive only the public key and refuse anything not signed with it.
 */
class Signer
{
    protected ?string $keypair = null;

    public function hasKey(): bool
    {
        return is_file(config('central.signing_key_path'));
    }

    /** Create the key pair once. Refuses to overwrite an existing key (that would orphan every installation). */
    public function generate(): void
    {
        $path = config('central.signing_key_path');
        if (is_file($path)) {
            throw new RuntimeException('A signing key already exists. Installations trust it; it is never replaced automatically.');
        }
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, base64_encode(sodium_crypto_sign_keypair()));
        @chmod($path, 0600);
        $this->keypair = null;
    }

    public function publicKey(): string
    {
        return base64_encode(sodium_crypto_sign_publickey($this->keypair()));
    }

    /** Short ID of the public key, shown on both sides so they can be compared. */
    public function keyId(): string
    {
        return substr(hash('sha256', sodium_crypto_sign_publickey($this->keypair())), 0, 16);
    }

    public function fingerprint(): string
    {
        return implode(':', str_split(strtoupper(substr(hash('sha256', sodium_crypto_sign_publickey($this->keypair())), 0, 32)), 4));
    }

    public function sign(string $message): string
    {
        return base64_encode(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($this->keypair())));
    }

    public function verify(string $message, string $signature): bool
    {
        $raw = base64_decode($signature, true);

        return $raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_BYTES
            && sodium_crypto_sign_verify_detached($raw, $message, sodium_crypto_sign_publickey($this->keypair()));
    }

    protected function keypair(): string
    {
        if ($this->keypair !== null) {
            return $this->keypair;
        }
        $path = config('central.signing_key_path');
        if (! is_file($path)) {
            throw new RuntimeException('No signing key yet. Run: php artisan central:keys');
        }
        $pair = base64_decode(trim((string) file_get_contents($path)), true);
        if ($pair === false || strlen($pair) !== SODIUM_CRYPTO_SIGN_KEYPAIRBYTES) {
            throw new RuntimeException('The signing key file is damaged.');
        }

        return $this->keypair = $pair;
    }
}
