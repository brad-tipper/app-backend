<?php

namespace BradTipper\AppBackend\Support;

use SilverStripe\Core\Environment;

/** Cloudflare Turnstile server-side verification. */
final class Turnstile
{
    public static function verify(?string $token, string $remoteIp): bool
    {
        $secret = trim((string) Environment::getEnv('TURNSTILE_SECRET_KEY'));
        if ($secret === '') {
            // Unconfigured: only acceptable outside live environments.
            return Environment::getEnv('SS_ENVIRONMENT_TYPE') !== 'live';
        }
        if (!$token) {
            return false;
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => $remoteIp]),
            'timeout' => 8,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
        $decoded = $body ? json_decode($body, true) : null;
        return is_array($decoded) && ($decoded['success'] ?? false) === true;
    }
}
