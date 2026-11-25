<?php

namespace BradTipper\RestfulFramework\Files;

use BradTipper\RestfulFramework\AppConfig;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;

/**
 * Private file storage for apps that take uploads. Files live outside the web root, grouped by the UUID of
 * whatever owns them (`{scope-uuid}/{name}`: the account, or for team apps the workspace), and are only
 * reachable through short-lived signed URLs (see FilesController). In production `storage_dir` must be a
 * persistent volume.
 *
 * Each app tunes it in its own yml:
 *
 *   BradTipper\RestfulFramework\Files\FileStore:
 *     storage_dir: '.storage'
 *     max_bytes: 52428800
 *     types:
 *       'application/pdf': pdf
 */
final class FileStore
{
    use Configurable;

    /** Absolute directory, or relative to the project root. */
    private static string $storage_dir = '.storage';

    /** Largest single file. */
    private static int $max_bytes = 26214400;

    /** Most one scope (an account or workspace) may keep on disk in total. */
    private static int $max_scope_bytes = 1073741824;

    /** Allowed uploads: mime type => extension. Apps add to this (PDFs, documents). @var array<string, string> */
    private static array $types = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'image/avif' => 'avif',
    ];

    /** Files whose name starts with this may be served without a signature from the public `logos/` route. */
    public const PUBLIC_PREFIX = 'logo-';

    public static function maxBytes(): int
    {
        return (int) self::config()->get('max_bytes');
    }

    public static function maxScopeBytes(): int
    {
        return (int) self::config()->get('max_scope_bytes');
    }

    /** @return array<string, string> mime type => extension */
    public static function types(): array
    {
        return (array) self::config()->get('types');
    }

    public static function extensionFor(string $mime): ?string
    {
        return self::types()[strtolower(trim(explode(';', $mime)[0]))] ?? null;
    }

    public static function mimeFor(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = array_search($extension, self::types(), true);
        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    /** True for `{uuid}/{file}` with no traversal. */
    public static function isValidPath(string $path): bool
    {
        return (bool) preg_match('#^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/[A-Za-z0-9][A-Za-z0-9._-]{0,127}$#', $path);
    }

    public static function belongsTo(string $path, string $scopeUuid): bool
    {
        return self::isValidPath($path) && str_starts_with($path, strtolower($scopeUuid) . '/');
    }

    public static function store(string $scopeUuid, string $bytes, string $extension, string $prefix = ''): string
    {
        $path = strtolower($scopeUuid) . '/' . $prefix . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
        $full = self::fullPath($path);
        if (!is_dir(dirname($full)) && !mkdir(dirname($full), 0775, true) && !is_dir(dirname($full))) {
            throw new \RuntimeException('Unable to create the storage directory.');
        }
        if (file_put_contents($full, $bytes) === false) {
            throw new \RuntimeException('Unable to write the file.');
        }
        return $path;
    }

    /** Bytes a scope currently has on disk (used for the storage cap). */
    public static function scopeBytes(string $scopeUuid): int
    {
        $dir = dirname(self::fullPath(strtolower($scopeUuid) . '/x'));
        $total = 0;
        foreach (glob($dir . '/*') ?: [] as $file) {
            $total += (int) @filesize($file);
        }
        return $total;
    }

    public static function exists(string $path): bool
    {
        return self::isValidPath($path) && is_file(self::fullPath($path));
    }

    /** The configured storage directory as an absolute path, without a trailing slash. */
    public static function root(): string
    {
        $dir = (string) self::config()->get('storage_dir');
        $absolute = preg_match('#^([A-Za-z]:)?[/\\\\]#', $dir) === 1;
        return rtrim($absolute ? $dir : Director::baseFolder() . '/' . $dir, '/\\');
    }

    public static function fullPath(string $path): string
    {
        return self::root() . '/' . $path;
    }

    public static function delete(string $path): void
    {
        if (self::exists($path)) {
            @unlink(self::fullPath($path));
        }
    }

    /** Removes everything a scope stored (account or workspace deletion). */
    public static function deleteScope(string $scopeUuid): void
    {
        $dir = dirname(self::fullPath(strtolower($scopeUuid) . '/x'));
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    public static function sign(string $path, int $expires): string
    {
        return hash_hmac('sha256', $path . '|' . $expires, self::key());
    }

    public static function verify(string $path, int $expires, string $signature): bool
    {
        return $expires >= time() && hash_equals(self::sign($path, $expires), $signature);
    }

    /** A signed, expiring URL for a stored file. @return array{url: string, expires_at: string} */
    public static function signedUrl(string $path, int $ttl = 3600): array
    {
        $expires = time() + $ttl;
        $base = rtrim((string) AppConfig::get('public_url'), '/');
        return [
            'url' => sprintf('%s/api/v1/files/%s?exp=%d&sig=%s', $base, $path, $expires, self::sign($path, $expires)),
            'expires_at' => gmdate(DATE_ATOM, $expires),
        ];
    }

    private static function key(): string
    {
        $configured = trim((string) AppConfig::get('app_key'));
        if ($configured !== '') {
            return 'app-files|' . $configured;
        }
        if (Environment::getEnv('SS_ENVIRONMENT_TYPE') === 'live') {
            throw new \RuntimeException('app_key is required in live environments.');
        }
        return 'app-files|dev|' . Environment::getEnv('SS_DATABASE_NAME') . '|' . Environment::getEnv('SS_DATABASE_PASSWORD');
    }
}
