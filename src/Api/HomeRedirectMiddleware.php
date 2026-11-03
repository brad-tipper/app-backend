<?php

namespace BradTipper\AppBackend\Api;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPMiddleware;

/**
 * The API has no public home page. Anyone who lands on `/` by accident is sent to the
 * login screen (and on to the CMS once signed in). A middleware rather than a route,
 * because the CMS claims the root URL for its own home page.
 */
class HomeRedirectMiddleware implements HTTPMiddleware
{
    public function process(HTTPRequest $request, callable $delegate)
    {
        if (trim($request->getURL(), '/') === '' && in_array(strtoupper($request->httpMethod()), ['GET', 'HEAD'], true)) {
            $response = HTTPResponse::create('', 302);
            $response->addHeader('Location', '/Security/login?BackURL=%2Fadmin');
            return $response;
        }
        return $delegate($request);
    }
}
