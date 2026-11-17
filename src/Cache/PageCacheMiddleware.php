<?php

namespace BradTipper\AppBackend\Cache;

use Innoweb\CookieConsent\CookieConsent;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\Core\Config\Configurable;

/**
 * Full-page cache for the public website. An anonymous GET of a page is rendered once, stored in the app's
 * PSR-16 cache and served straight from it until something on the site changes (BustsSiteCache bumps the
 * `site` generation, which makes every stored page unreachable at once).
 *
 * It only ever stores a response that is safe to share: status 200, HTML/XML/text, no cookie set, no CSRF
 * token in the body, and a request with no login/session cookie, no `Authorization` header and no query
 * string (apart from tracking parameters). The API (`api/v1/`), the CMS and Silverstripe's own routes are never
 * touched. Pages that contain a form carry a session-bound token, so they are rendered normally every time.
 *
 * Responses also get `Cache-Control: public, max-age=N` so browsers and any proxy in
 * front can reuse them briefly. Anything this does not cache is marked `X-App-Cache: BYPASS`.
 */
class PageCacheMiddleware implements HTTPMiddleware
{
    use Configurable;

    /** How long a browser may reuse a page without asking. Edits show up within this time. */
    private static int $browser_max_age = 60;

    /** Longest a stored page lives even if nothing changes. */
    private static int $ttl = 86400;

    private static int $max_body_bytes = 2097152;

    /** First URL segments that are never cached. @var string[] */
    private static array $excluded_prefixes = ['admin', 'Security', 'dev', 'api', 'graphql', '_resources', 'assets', '__'];

    /** Query parameters that do not change the page (analytics). A request with any other parameter is not cached. @var string[] */
    private static array $ignored_query_params = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid', 'ref',
    ];

    /** Cookie-consent groups the page output depends on (see Page.ss); each combination is cached separately. @var string[] */
    private static array $consent_groups = ['Analytics', 'Marketing', 'Preferences'];

    /** Request cookies that mean "a person is signed in or has a session": never served from the cache. */
    private const SESSION_COOKIE = '/^(PHPSESSID|SECSESSID|alc_enc|.*sess.*)$/i';

    private const CACHEABLE_TYPES = '#^(text/html|text/plain|application/xml|text/xml|application/ld\+json|application/json)\b#i';

    public function process(HTTPRequest $request, callable $delegate)
    {
        if (!AppCache::enabled() || !$this->isCacheableRequest($request)) {
            return $delegate($request);
        }

        $store = AppCache::store();
        $key = AppCache::key(BustsSiteCache::SCOPE, $this->requestFingerprint($request));

        $hit = $store->get($key);
        if (is_array($hit) && isset($hit['body'], $hit['headers'])) {
            $response = HTTPResponse::create($hit['body'], 200);
            foreach ($hit['headers'] as $name => $value) {
                $response->addHeader($name, $value);
            }
            $response->addHeader('X-App-Cache', 'HIT');
            $this->allowSharing();
            return $response;
        }

        $response = $delegate($request);
        if ($this->isStorableResponse($response)) {
            $headers = [];
            foreach ($response->getHeaders() as $name => $value) {
                if (!in_array(strtolower($name), ['set-cookie', 'date', 'age', 'etag', 'cache-control', 'expires', 'last-modified', 'x-app-cache', 'vary'], true)) {
                    $headers[$name] = $value;
                }
            }
            $store->set($key, ['body' => $response->getBody(), 'headers' => $headers], (int) self::config()->get('ttl'));
            $response->addHeader('X-App-Cache', 'MISS');
            $this->allowSharing();
        } else {
            $response->addHeader('X-App-Cache', 'BYPASS');
        }
        return $response;
    }

    private function isCacheableRequest(HTTPRequest $request): bool
    {
        if (strtoupper($request->httpMethod()) !== 'GET' || $request->getHeader('Authorization')) {
            return false;
        }
        $segment = strtolower((string) strtok(ltrim($request->getURL(), '/'), '/'));
        foreach ((array) self::config()->get('excluded_prefixes') as $prefix) {
            if ($segment === strtolower((string) $prefix)) {
                return false;
            }
        }
        if (array_diff_key($request->getVars(), array_flip((array) self::config()->get('ignored_query_params')))) {
            return false;
        }
        foreach (array_keys($_COOKIE) as $name) {
            if (preg_match(self::SESSION_COOKIE, (string) $name)) {
                return false;
            }
        }
        return true;
    }

    /** What makes one cached page different from another: host, path and the consent choices the output depends on. */
    private function requestFingerprint(HTTPRequest $request): string
    {
        $path = '/' . trim($request->getURL(), '/');
        $consent = '';
        if (class_exists(CookieConsent::class)) {
            // Read the choice the way the cookie-consent module does (CookieConsent::check() needs a current controller).
            $raw = $_COOKIE[CookieConsent::config()->get('cookie_name')]
                ?? urldecode((string) $request->getHeader((string) CookieConsent::config()->get('header_name')));
            $granted = explode(',', (string) $raw);
            foreach ((array) self::config()->get('consent_groups') as $group) {
                $consent .= in_array($group, $granted, true) ? '1' : '0';
            }
        }
        return strtolower((string) $request->getHeader('Host')) . '|' . $path . '|' . $consent;
    }

    private function isStorableResponse(HTTPResponse $response): bool
    {
        if ($response->getStatusCode() !== 200 || !preg_match(self::CACHEABLE_TYPES, (string) $response->getHeader('Content-Type'))) {
            return false;
        }
        $body = (string) $response->getBody();
        if ($body === '' || strlen($body) > (int) self::config()->get('max_body_bytes') || str_contains($body, 'name="SecurityID"')) {
            return false;
        }
        // A cookie (a started session, CSRF state) makes the response personal.
        foreach (headers_list() as $header) {
            if (stripos($header, 'Set-Cookie:') === 0) {
                return false;
            }
        }
        return true;
    }

    private function allowSharing(): void
    {
        HTTPCacheControlMiddleware::singleton()->publicCache(false, (int) self::config()->get('browser_max_age'));
    }
}
