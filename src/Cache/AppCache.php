<?php

namespace BradTipper\AppBackend\Cache;

use BradTipper\AppBackend\Support\CloudflarePurge;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;

/**
 * The app's PSR-16 cache (Silverstripe's own cache service, `Psr\SimpleCache\CacheInterface.appBackend`, which
 * is filesystem backed under silverstripe-cache unless a project swaps the factory) plus the one thing a plain
 * cache lacks: cheap, total invalidation.
 *
 * Every entry is keyed with the current *generation* of a scope (`site`, `taxa`, ...). Bumping the scope makes
 * every entry in it unreachable at once; they simply expire later. That is how edits bust the public pages and
 * how imports bust shared API data, without tracking individual keys.
 *
 *   AppCache::remember('taxa', 'search:' . $q, 300, fn () => $expensiveQuery());
 *   AppCache::bump('taxa');   // after the data changes
 */
final class AppCache
{
    use Configurable;

    /** Master switch for the page cache (PageCacheMiddleware). Enabled for `live` in app-backend.yml. */
    private static bool $enabled = false;

    public static function store(): CacheInterface
    {
        return Injector::inst()->get(CacheInterface::class . '.appBackend');
    }

    public static function enabled(): bool
    {
        return (bool) self::config()->get('enabled');
    }

    /** The scope's current generation; a new one is created if the scope has none (or it expired). */
    public static function generation(string $scope): string
    {
        $store = self::store();
        $key = 'gen.' . self::slug($scope);
        $generation = $store->get($key);
        if (!is_string($generation) || $generation === '') {
            $generation = self::newGeneration();
            $store->set($key, $generation);
        }
        return $generation;
    }

    /** Invalidates everything cached under the scope. Safe to call from CLI tasks and queued jobs. */
    public static function bump(string $scope): void
    {
        self::store()->set('gen.' . self::slug($scope), self::newGeneration());
        if ($scope === BustsSiteCache::SCOPE) {
            // The public pages changed, so the Cloudflare edge copies are stale too.
            CloudflarePurge::schedule();
        }
    }

    /** The full cache key for a name inside a scope (PSR-16 keys may not contain `{}()/\@:`). */
    public static function key(string $scope, string $name): string
    {
        return self::slug($scope) . '.' . self::generation($scope) . '.' . md5($name);
    }

    /**
     * Returns the cached value, computing and storing it on a miss. A callback returning null is not cached.
     *
     * @template T
     * @param callable(): T $compute
     * @return T
     */
    public static function remember(string $scope, string $name, int $ttl, callable $compute): mixed
    {
        $store = self::store();
        $key = self::key($scope, $name);
        $hit = $store->get($key);
        if ($hit !== null) {
            return $hit;
        }
        $value = $compute();
        if ($value !== null) {
            $store->set($key, $value, $ttl);
        }
        return $value;
    }

    private static function slug(string $scope): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $scope);
    }

    private static function newGeneration(): string
    {
        return bin2hex(random_bytes(6));
    }
}
