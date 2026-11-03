<?php

// Timestamps are stored and exchanged in UTC.
date_default_timezone_set('UTC');

// Some vendor packages (e.g. the Sentry SDK) still emit PHP 8.4 deprecation notices, which
// Silverstripe's dev error handler would otherwise promote to build-breaking errors.
// PHP's own production default already excludes E_DEPRECATED.
error_reporting(error_reporting() & ~E_DEPRECATED);

// Behind the Coolify proxy the request reaches PHP as plain http, so Silverstripe would build
// absolute URLs (the CMS's own redirects, for one) with http://. Each API already states its own
// public origin per environment, so use that as the base URL.
$publicUrl = rtrim((string) \BradTipper\AppBackend\AppConfig::get('public_url'), '/');
if ($publicUrl !== '' && $publicUrl !== 'http://localhost') {
    \SilverStripe\Core\Config\Config::modify()->set(\SilverStripe\Control\Director::class, 'alternate_base_url', $publicUrl);
}
