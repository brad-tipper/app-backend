<?php

namespace BradTipper\AppBackend\Support;

use SilverStripe\Core\Environment;

/**
 * Symmetric encryption for values stored at rest (TOTP secrets). The key comes
 * from APP_KEY (32 random bytes, base64). In dev a key is derived from the
 * database credentials so local setups work with no configuration.
 */
final class Secrets
{
    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
        return $plain === false ? null : $plain;
    }

    private static function key(): string
    {
        $configured = (string) Environment::getEnv('APP_KEY');
        if ($configured !== '') {
            $key = base64_decode($configured, true);
            if ($key !== false && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $key;
            }
            throw new \RuntimeException('APP_KEY must be 32 random bytes, base64 encoded.');
        }
        if (Environment::getEnv('SS_ENVIRONMENT_TYPE') === 'live') {
            throw new \RuntimeException('APP_KEY is required in live environments.');
        }
        return hash('sha256', 'dev-key|' . Environment::getEnv('SS_DATABASE_NAME') . '|' . Environment::getEnv('SS_DATABASE_PASSWORD'), true);
    }
}
