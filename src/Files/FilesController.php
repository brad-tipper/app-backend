<?php

namespace BradTipper\AppBackend\Files;

use BradTipper\AppBackend\Api\ApiController;
use BradTipper\AppBackend\Api\ApiException;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;

/**
 * Serves a stored file. Opt in per app by routing `'v1/files//$ID/$OtherID'` to it.
 *
 *   GET /v1/files/{scope-uuid}/{name}?exp=&sig=   private files, with a signature from /v1/uploads/url
 *   GET /v1/logos/{scope-uuid}/{name}             public files named `logo-*` (they appear in emails); route it too if needed
 *
 * Not described in openapi.json.
 */
class FilesController extends ApiController
{
    protected function dispatch(HTTPRequest $request): HTTPResponse
    {
        $this->requireMethod($request, 'GET');
        // Silverstripe splits ".png" off the last URL segment into the request extension.
        $extension = $request->getExtension();
        $path = (string) $request->param('ID') . '/' . (string) $request->param('OtherID') . ($extension ? '.' . $extension : '');
        $public = str_contains((string) $request->getURL(), 'logos/');

        if (!FileStore::isValidPath($path) || !FileStore::exists($path)) {
            throw ApiException::notFound('File not found.');
        }
        if ($public) {
            if (!str_starts_with(basename($path), FileStore::PUBLIC_PREFIX)) {
                throw ApiException::notFound('File not found.');
            }
            $ttl = 3600;
        } else {
            $expires = (int) $request->getVar('exp');
            if (!FileStore::verify($path, $expires, (string) $request->getVar('sig'))) {
                throw ApiException::notFound('File not found.');
            }
            $ttl = max(0, min(3600, $expires - time()));
        }
        $response = HTTPResponse::create((string) file_get_contents(FileStore::fullPath($path)), 200);
        $response->addHeader('Content-Type', FileStore::mimeFor($path));
        $response->addHeader('Cache-Control', ($public ? 'public' : 'private') . ', max-age=' . $ttl);
        $response->addHeader('X-Content-Type-Options', 'nosniff');
        $response->addHeader('Content-Disposition', 'inline');
        $response->addHeader('Cross-Origin-Resource-Policy', 'cross-origin');
        return $response;
    }
}
