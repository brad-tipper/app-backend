<?php

namespace BradTipper\AppBackend\Cache;

use SilverStripe\Core\Extension;

/**
 * Put this on anything that appears on the public website (pages, blocks, site settings, navigation items):
 * any write, delete or publish bumps the `site` generation, so the next visitor gets a freshly rendered page
 * (and AppCache::bump purges the Cloudflare edge cache). app-backend applies it to SiteTree, Elemental blocks and
 * SiteConfig; a project adds it to its own block item models in website.yml.
 *
 * @extends Extension<\SilverStripe\ORM\DataObject>
 */
class BustsSiteCache extends Extension
{
    public const SCOPE = 'site';

    public function onAfterWrite(): void
    {
        AppCache::bump(self::SCOPE);
    }

    public function onAfterDelete(): void
    {
        AppCache::bump(self::SCOPE);
    }

    public function onAfterPublish(): void
    {
        AppCache::bump(self::SCOPE);
    }

    public function onAfterUnpublish(): void
    {
        AppCache::bump(self::SCOPE);
    }

    public function onAfterRevertToLive(): void
    {
        AppCache::bump(self::SCOPE);
    }

    public function onAfterArchive(): void
    {
        AppCache::bump(self::SCOPE);
    }
}
