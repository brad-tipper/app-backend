<?php

namespace BradTipper\AppBackend;

use SilverStripe\Core\Config\Configurable;

/**
 * Non-secret, per-app settings. Set them in the project's `app/_config/app.yml`
 * (use `Only: environment: dev|live` fragments for per-environment values).
 * Secrets never live here - they are environment variables.
 */
final class AppConfig
{
    use Configurable;

    /** Display name, used in emails and the 2FA authenticator entry. */
    private static string $name = 'App';

    /** The web app's origin; the default target for emailed links. */
    private static string $web_url = 'http://localhost:4173';

    /** Origins a client may name as `redirectUrl` (deep links / web builds). @var string[] */
    private static array $redirect_origins = [];

    /** URL schemes (native deep links such as `myapp`) a client may use as `redirectUrl`. @var string[] */
    private static array $redirect_schemes = [];

    /** Browser origins allowed by CORS. Empty means any origin (tokens are bearer, not cookies). @var string[] */
    private static array $cors_origins = [];

    /** Public base URL of this API, written into openapi.json. */
    private static string $public_url = 'http://localhost';

    /** Sender of transactional email. */
    private static string $mail_from_address = 'no-reply@localhost';

    private static string $mail_from_name = '';

    /** Scales every rate limit (tests sign the same users in repeatedly; dev sets this high). */
    private static int $rate_limit_multiplier = 1;

    /** False for apps with no paid tier: account deletion then skips the RevenueCat customer. */
    private static bool $revenuecat_enabled = true;

    /** RevenueCat project id (used to delete a customer with their account). */
    private static string $revenuecat_project_id = '';

    public static function get(string $key): mixed
    {
        return self::config()->get($key);
    }

    /** @return string[] */
    public static function list(string $key): array
    {
        return array_values(array_filter((array) self::config()->get($key), static fn ($v) => $v !== '' && $v !== null));
    }
}
