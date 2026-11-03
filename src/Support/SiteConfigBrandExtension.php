<?php

namespace BradTipper\AppBackend\Support;

use BradTipper\AppBackend\AppConfig;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Extension;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Names the site after the app instead of Silverstripe's "Your Site Name", and says which
 * environment this is: "CardGrip API" in production, "CardGrip API (dev)" on the preview,
 * "CardGrip API (local)" on a developer machine. Runs on every `db:build`, so a database
 * copied between environments is renamed on the next deploy.
 *
 * @extends Extension<\SilverStripe\SiteConfig\SiteConfig>
 */
class SiteConfigBrandExtension extends Extension
{
    public static function siteTitle(): string
    {
        $suffix = match (Environment::getEnv('SS_ENVIRONMENT_TYPE')) {
            'live' => '',
            'test' => ' (dev)',
            default => ' (local)',
        };
        return trim((string) AppConfig::get('name')) . ' API' . $suffix;
    }

    public function onAfterBuild(): void
    {
        $config = SiteConfig::current_site_config();
        $title = self::siteTitle();
        if ($config->Title !== $title) {
            $config->Title = $title;
            $config->write();
        }
    }
}
