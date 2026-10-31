<?php

// Timestamps are stored and exchanged in UTC.
date_default_timezone_set('UTC');

// Some vendor packages (e.g. the Sentry SDK) still emit PHP 8.4 deprecation notices, which
// Silverstripe's dev error handler would otherwise promote to build-breaking errors.
// PHP's own production default already excludes E_DEPRECATED.
error_reporting(error_reporting() & ~E_DEPRECATED);
