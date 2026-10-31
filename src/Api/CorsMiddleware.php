<?php

namespace BradTipper\AppBackend\Api;

use BradTipper\AppBackend\AppConfig;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPMiddleware;

/**
 * CORS for /v1/*. Bearer tokens (no cookies) mean `*` is safe, but a comma
 * separated API_CORS_ORIGINS list narrows it for production.
 */
class CorsMiddleware implements HTTPMiddleware
{
    public function process(HTTPRequest $request, callable $delegate)
    {
        $url = ltrim($request->getURL(), '/');
        if ($url !== 'v1' && !str_starts_with($url, 'v1/')) {
            return $delegate($request);
        }

        $response = strtoupper($request->httpMethod()) === 'OPTIONS'
            ? HTTPResponse::create('', 204)
            : $delegate($request);

        $this->decorate($request, $response);
        return $response;
    }

    private function decorate(HTTPRequest $request, HTTPResponse $response): void
    {
        $allowed = AppConfig::list('cors_origins');
        $origin = $request->getHeader('Origin');
        if ($allowed === [] || in_array('*', $allowed, true)) {
            $response->addHeader('Access-Control-Allow-Origin', '*');
        } elseif ($origin && in_array($origin, $allowed, true)) {
            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Vary', 'Origin');
        }
        $response->addHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->addHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept');
        $response->addHeader('Access-Control-Max-Age', '600');
    }
}
