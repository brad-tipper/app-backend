<?php

namespace BradTipper\AppBackend\Support;

use BradTipper\AppBackend\AppConfig;
use SilverStripe\Core\Extension;
use SilverStripe\View\Requirements;

/**
 * Brands Silverstripe's login screens with the app's colour and logo (the same
 * AppConfig keys the emails use), through the login form's own CSS variables.
 *
 * @extends Extension<\SilverStripe\Security\Security>
 */
class LoginBrandExtension extends Extension
{
    public function onAfterInit(): void
    {
        $color = EmailTemplate::safeColor((string) AppConfig::get('brand_color'), '#2f4c63');
        $onColor = EmailTemplate::safeColor((string) AppConfig::get('brand_color_text'), '#ffffff');
        $logo = trim((string) AppConfig::get('brand_logo_url'));
        $logo = str_starts_with($logo, 'https://') ? $logo : '';

        $css = ":root,body{--color-button-primary-background:{$color};--color-button-primary-hover-background:{$color};"
            . "--color-button-primary-text:{$onColor};--color-button-primary-hover-text:{$onColor};}"
            . ".app-brand{background:{$color};width:390px;max-width:100%;margin:0 auto;box-sizing:border-box;border-radius:12px 12px 0 0;text-align:center;}"
            . ".app-brand__link,.app-brand__name{color:{$onColor};text-decoration:none;}"
            . ".login-form input.action,.login-form .btn-toolbar .action{background:{$color};border-color:{$color};color:{$onColor};}"
            . ".login-form input:focus{outline-color:{$color};}";
        if ($logo !== '') {
            $css .= ".app-brand__link{display:inline-flex;align-items:center;gap:12px;}"
                . ".app-brand__link::before{content:'';width:36px;height:36px;border-radius:8px;"
                . "background:url('{$logo}') center/cover no-repeat;}";
            Requirements::insertHeadTags('<link rel="icon" href="' . htmlspecialchars($logo, ENT_QUOTES) . '">', 'brand-icon');
        }
        Requirements::customCSS($css, 'brand-login');
    }
}
