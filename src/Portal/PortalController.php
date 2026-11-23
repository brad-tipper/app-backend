<?php

namespace BradTipper\AppBackend\Portal;

use BradTipper\AppBackend\AppConfig;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * The public support portal for a project's website.
 *
 *   GET /portal            a standalone, branded page with the sign-in + tickets widget, the contact form and the feedback form
 *   GET /portal/widget.js  the embeddable script (client/portal.js with this project's branding prepended)
 *
 * Embed the widgets anywhere on the site with:
 *   <div data-support-portal></div> <div data-contact-form></div> <div data-feedback-form></div>
 *   <script src="/portal/widget.js" defer></script>
 *
 * The widgets call the same /api/v1 endpoints as the apps (auth, tickets, contact, feedback), so there is no session
 * or cookie on the website: both URLs are plain, cacheable responses.
 */
class PortalController extends Controller
{
    private static array $allowed_actions = ['widget'];

    private static string $url_segment = 'portal';

    public function index(HTTPRequest $request): HTTPResponse
    {
        $name = $this->esc(AppConfig::get('name'));
        $brand = $this->esc($this->brandColor());
        $onBrand = $this->esc($this->brandText());
        $logo = trim((string) AppConfig::get('brand_logo_url'));
        $mark = $logo !== '' ? '<img class="mark" src="' . $this->esc($logo) . '" alt="">' : '';
        $mode = (string) $request->getVar('view');
        $sections = match ($mode) {
            'contact' => '<div data-contact-form></div>',
            'feedback' => '<div data-feedback-form></div>',
            default => '<div data-support-portal></div>',
        };
        $nav = '<nav>'
            . '<a href="/portal"' . ($mode === '' ? ' aria-current="page"' : '') . '>My requests</a>'
            . '<a href="/portal?view=contact"' . ($mode === 'contact' ? ' aria-current="page"' : '') . '>Contact us</a>'
            . '<a href="/portal?view=feedback"' . ($mode === 'feedback' ? ' aria-current="page"' : '') . '>Send feedback</a>'
            . '</nav>';

        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Support · {$name}</title>
<style>
:root{--brand:{$brand};--on-brand:{$onBrand};--bg:#f4f6f8;--fg:#1b2127}
@media (prefers-color-scheme:dark){:root{--bg:#0f1317;--fg:#eef1f4}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
header{background:var(--brand);color:var(--on-brand);padding:16px 20px;display:flex;align-items:center;gap:12px}
header .mark{height:36px;border-radius:8px}header strong{font-size:1.15rem}
nav{display:flex;gap:6px;justify-content:center;flex-wrap:wrap;margin:22px 16px 0}
nav a{color:inherit;text-decoration:none;padding:6px 14px;border-radius:999px;border:1px solid transparent;font-weight:600}
nav a[aria-current=page]{background:var(--brand);color:var(--on-brand)}nav a:hover{border-color:var(--brand)}
main{padding:20px 16px 48px}
</style>
</head>
<body>
<header>{$mark}<strong>{$name} support</strong></header>
{$nav}
<main>{$sections}</main>
<script src="/portal/widget.js" defer></script>
</body>
</html>
HTML;
        return HTTPResponse::create($html, 200)
            ->addHeader('Content-Type', 'text/html; charset=utf-8')
            ->addHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function widget(HTTPRequest $request): HTTPResponse
    {
        $script = (string) @file_get_contents(dirname(__DIR__, 2) . '/client/portal.js');
        $config = [
            'apiBase' => '/api/v1',
            'name' => trim((string) AppConfig::get('name')),
            'brandColor' => $this->brandColor(),
            'brandColorText' => $this->brandText(),
            'logoUrl' => trim((string) AppConfig::get('brand_logo_url')),
            'turnstileSiteKey' => $this->turnstileSiteKey(),
            'privacyUrl' => trim((string) AppConfig::get('privacy_url')),
        ];
        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        return HTTPResponse::create("var APP_PORTAL = {$json};\n{$script}", 200)
            ->addHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->addHeader('X-Content-Type-Options', 'nosniff')
            ->addHeader('Cache-Control', 'public, max-age=300');
    }

    /** The Turnstile site key: the project's website setting when it has one, else AppConfig. */
    private function turnstileSiteKey(): string
    {
        $fromSite = '';
        if (class_exists(SiteConfig::class)) {
            $fromSite = trim((string) (SiteConfig::current_site_config()->TurnstileSiteKey ?? ''));
        }
        return $fromSite !== '' ? $fromSite : trim((string) AppConfig::get('turnstile_site_key'));
    }

    private function brandColor(): string
    {
        return $this->safeColor((string) AppConfig::get('brand_color'), '#2f4c63');
    }

    private function brandText(): string
    {
        return $this->safeColor((string) AppConfig::get('brand_color_text'), '#ffffff');
    }

    /** Only hex colours reach the CSS. */
    private function safeColor(string $value, string $fallback): string
    {
        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) === 1 ? $value : $fallback;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
