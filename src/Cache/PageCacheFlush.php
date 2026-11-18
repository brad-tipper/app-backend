<?php

namespace BradTipper\AppBackend\Cache;

use SilverStripe\Core\Flushable;

/** A flush (?flush=1, `sake ... --flush`, every deploy) drops every stored page and purges the Cloudflare edge cache too. */
class PageCacheFlush implements Flushable
{
    public static function flush(): void
    {
        try {
            AppCache::bump(BustsSiteCache::SCOPE);
        } catch (\Throwable) {
            // The cache store may not be available this early; the flush must still go ahead.
        }
    }
}
