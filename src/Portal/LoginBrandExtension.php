<?php

namespace BradTipper\AppBackend\Portal;

use BradTipper\AppBackend\AppConfig;
use SilverStripe\Core\Extension;
use SilverStripe\View\Requirements;

/**
 * Whitelabels the Silverstripe login pages (/Security/login, lost password, change password, and the CMS login):
 * the project's name and logo replace "Silverstripe", the buttons and links use the brand colour, and the
 * "Silverstripe CMS" footer is hidden. Pure CSS from AppConfig (name, brand_color, brand_color_text, brand_logo_url),
 * so nothing needs overriding per project.
 *
 * The CMS itself (/admin) keeps its own look; only the sign-in pages are branded.
 *
 * @extends Extension<\SilverStripe\Security\Security>
 */
class LoginBrandExtension extends Extension
{
    public function onAfterInit(): void
    {
        Requirements::customCSS(self::css(), 'app-backend-login-brand');
    }

    public static function css(): string
    {
        $brand = self::color((string) AppConfig::get('brand_color'), '#2f4c63');
        $on = self::color((string) AppConfig::get('brand_color_text'), '#ffffff');
        $name = addcslashes(trim((string) AppConfig::get('name')), "\"\\\n\r");
        $logo = trim((string) AppConfig::get('brand_logo_url'));
        $logoCss = preg_match('#^https?://[^\s"\'()]+$#', $logo) === 1
            ? ".app-brand__link::before{content:'';display:block;width:64px;height:64px;margin:0 auto 12px;border-radius:14px;background:url(\"{$logo}\") center/contain no-repeat}"
            : '';

        return <<<CSS
:root{--ab-brand:{$brand};--ab-on:{$on}}
body{background:#f4f6f8}
.app-brand{text-align:center}
.app-brand__link{text-decoration:none}
.app-brand__logo,.app-brand .silverstripe-cms-logo,.app-brand svg{display:none!important}
{$logoCss}
.app-brand__name{font-size:0!important;line-height:0}
.app-brand__name::after{content:"{$name}";font-size:1.5rem;line-height:1.3;color:var(--ab-brand);font-weight:700}
.login-form{border-top:4px solid var(--ab-brand)}
.login-form button,.login-form input[type=submit],.login-form .btn-primary,.login-form .action{background:var(--ab-brand)!important;border-color:var(--ab-brand)!important;color:var(--ab-on)!important}
.login-form a{color:var(--ab-brand)}
.login-form input:focus{outline:2px solid var(--ab-brand);outline-offset:1px;border-color:var(--ab-brand)}
.silverstripe-brand{display:none!important}
CSS;
    }

    private static function color(string $value, string $fallback): string
    {
        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) === 1 ? $value : $fallback;
    }
}
