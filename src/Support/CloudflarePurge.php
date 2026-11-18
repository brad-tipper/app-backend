<?php

namespace BradTipper\AppBackend\Support;

use BradTipper\AppBackend\AppConfig;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;

/**
 * Purges the site's whole Cloudflare cache when the page cache is bumped (any content write/publish/delete, or a
 * flush). Cloudflare holds public pages for as long as the origin allows (see PageCacheMiddleware), so without this an
 * edit would stay invisible at the edge.
 *
 * One request is sent per PHP request, from a shutdown function (after the response is out), and only in live mode with
 * a zone id (`CloudflarePurge.zone_id`) and a Cache Purge token (`AppConfig.cloudflare_purge_token`). It never throws.
 */
final class CloudflarePurge
{
    use Configurable;

    /** The Cloudflare zone of the site's domain. Set per project in app/_config/cache.yml. */
    private static string $zone_id = '';

    private static bool $registered = false;

    /** Queue a purge-everything call for the end of this request (once, however many times it is asked for). */
    public static function schedule(): void
    {
        if (self::$registered) {
            return;
        }
        try {
            if (!Director::isLive() || self::zoneId() === '' || trim((string) AppConfig::get('cloudflare_purge_token')) === '') {
                return;
            }
            self::$registered = true;
            register_shutdown_function([self::class, 'send']);
        } catch (\Throwable) {
            // Purging is best effort.
        }
    }

    /** @internal Runs as a shutdown function. */
    public static function send(): void
    {
        try {
            $token = trim((string) AppConfig::get('cloudflare_purge_token'));
            if (!function_exists('curl_init') || $token === '' || self::zoneId() === '') {
                return;
            }
            $ch = curl_init('https://api.cloudflare.com/client/v4/zones/' . rawurlencode(self::zoneId()) . '/purge_cache');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => '{"purge_everything":true}',
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 8,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable) {
            // Never let a purge failure surface.
        }
    }

    private static function zoneId(): string
    {
        return trim((string) self::config()->get('zone_id'));
    }
}
