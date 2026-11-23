<?php

namespace BradTipper\AppBackend;

use SilverStripe\Core\Config\Configurable;

/**
 * Non-secret, per-app settings. Set them in the project's `app/_config/app.yml`
 * (use `Only: environment: dev|live` fragments for per-environment values).
 * Secrets (below) are also set in yml, in `Only: environment: live` fragments that
 * the private repo commits; only database details stay in `.env`.
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

    /** Email branding: the button/heading colour, the text colour on it, and a PNG logo URL. */
    private static string $brand_color = '#2f4c63';

    private static string $brand_color_text = '#ffffff';

    private static string $brand_logo_url = '';

    /** Turnstile site key for the portal widgets (a website's SiteConfig TurnstileSiteKey wins when it has one). */
    private static string $turnstile_site_key = '';

    /** Privacy policy URL linked from the portal contact and feedback consent line. */
    private static string $privacy_url = '';

    /** Path of the web app's subscription screen, linked from billing emails. */
    private static string $subscription_path = '/subscription';

    /** Path of the web app's support (tickets) screen; a ticket's id is appended for emailed links. */
    private static string $tickets_path = '/support';

    /** Where replies and "contact us" links go; empty falls back to the sender address. */
    private static string $support_email = '';

    /** Scales every rate limit (tests sign the same users in repeatedly; dev sets this high). */
    private static int $rate_limit_multiplier = 1;

    /** False for apps with no paid tier: account deletion then skips the RevenueCat customer. */
    private static bool $revenuecat_enabled = true;

    /** RevenueCat project id (used to delete a customer with their account). */
    private static string $revenuecat_project_id = '';

    /** Secrets. Empty by default; set in yml (live values in a `live` fragment). */
    private static string $app_key = '';

    /** Read by each project's app/_config.php to build its MAILER_DSN; app-backend itself never uses it. */
    private static string $smtp_password = '';

    private static string $turnstile_secret_key = '';

    /** Automated tests present this in place of a Turnstile token. Never honoured in live; set only in preview/dev fragments. */
    private static string $e2e_captcha_token = '';

    private static string $revenuecat_webhook_secret = '';

    private static string $revenuecat_v2_secret_key = '';

    private static string $e2e_seed_secret = '';

    /** Cloudflare API token scoped to Cache Purge on this site's zone (see Support\CloudflarePurge). Live only. */
    private static string $cloudflare_purge_token = '';

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
