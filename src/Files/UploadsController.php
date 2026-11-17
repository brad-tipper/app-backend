<?php

namespace BradTipper\AppBackend\Files;

use BradTipper\AppBackend\Api\ApiController;
use BradTipper\AppBackend\Api\ApiException;
use BradTipper\AppBackend\OpenApi\Spec;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\Member;

/**
 * Private file uploads. Opt in per app by routing `'api/v1/uploads//$Action'` to it (with FilesController on
 * `api/v1/files`). By default each account has its own area; subclass and override the hooks below for anything
 * more specific (a Pro-only file type, files that belong to a workspace).
 *
 *   POST   /api/v1/uploads            raw file bytes (Content-Type = the file type) -> {path, size_bytes, mime_type}
 *   GET    /api/v1/uploads/url?path=  a one-hour signed URL for the file
 *   DELETE /api/v1/uploads?path=      removes the file
 *
 * The upload is a raw body, so it is not in openapi.json; the app posts it with the shared API client.
 */
class UploadsController extends ApiController
{
    private const URL_TTL = 3600;

    public static function describe(Spec $spec): void
    {
        $spec->schema('SignedFileUrl', Spec::object([
            'url' => Spec::string('uri'),
            'expires_at' => Spec::string('date-time'),
        ]));
        $spec->operation('get', '/api/v1/uploads/url', 'getUploadUrl', [
            'tags' => ['Uploads'],
            'summary' => 'A short-lived signed URL for an uploaded file',
            'auth' => true,
            'query' => ['path' => ['schema' => Spec::string(), 'required' => true]],
            'responses' => [200 => Spec::ref('SignedFileUrl')],
        ]);
        $spec->operation('delete', '/api/v1/uploads', 'deleteUpload', [
            'tags' => ['Uploads'],
            'summary' => 'Delete an uploaded file',
            'auth' => true,
            'query' => ['path' => ['schema' => Spec::string(), 'required' => true]],
            'responses' => [204 => null],
        ]);
    }

    /**
     * The UUID of the area an upload is stored under, after checking the caller may write there. Default:
     * the caller's own area.
     */
    protected function scopeForUpload(HTTPRequest $request, Member $member): string
    {
        return (string) $member->UUID;
    }

    /**
     * What this upload may be. `types` limits the mime types (null: any FileStore type), `max_bytes` the size,
     * and `prefix` is put on the stored file name.
     *
     * @return array{types: string[]|null, max_bytes: int, prefix: string}
     */
    protected function uploadRules(HTTPRequest $request, Member $member): array
    {
        return ['types' => null, 'max_bytes' => FileStore::maxBytes(), 'prefix' => ''];
    }

    /** Last word on an upload (a plan limit, say). Throw an ApiException to refuse it. */
    protected function checkUpload(Member $member, string $mime, string $extension): void
    {
    }

    /** Throws "not found" unless the caller may read (or, with `$write`, delete) the stored file at `$path`. */
    protected function authorisePath(Member $member, string $path, bool $write): void
    {
        if (!FileStore::belongsTo($path, (string) $member->UUID) || !FileStore::exists($path)) {
            throw ApiException::notFound('File not found.');
        }
    }

    protected function dispatch(HTTPRequest $request): HTTPResponse
    {
        $member = $this->requireMember($request);
        $action = (string) $request->param('Action');
        $method = strtoupper($request->httpMethod());

        if ($action === '' && $method === 'POST') {
            return $this->store($request, $member);
        }

        $path = (string) $request->getVar('path');
        if ($action === 'url' && $method === 'GET') {
            $this->authorisePath($member, $path, false);
            return $this->json(FileStore::signedUrl($path, self::URL_TTL));
        }
        if ($action === '' && $method === 'DELETE') {
            $this->authorisePath($member, $path, true);
            FileStore::delete($path);
            return $this->noContent();
        }
        throw $action === '' || $action === 'url' ? ApiException::methodNotAllowed() : ApiException::notFound();
    }

    private function store(HTTPRequest $request, Member $member): HTTPResponse
    {
        $scope = $this->scopeForUpload($request, $member);
        $this->limit("upload:{$member->ID}", 120, 3600);
        $rules = $this->uploadRules($request, $member);

        $mime = strtolower(trim(explode(';', (string) $request->getHeader('Content-Type'))[0]));
        $extension = FileStore::extensionFor($mime);
        if ($extension === null || ($rules['types'] !== null && !in_array($mime, $rules['types'], true))) {
            throw ApiException::badRequest('That file type is not allowed here.', 'unsupported_type');
        }
        $this->checkUpload($member, $mime, $extension);

        $bytes = (string) $request->getBody();
        if ($bytes === '') {
            throw ApiException::badRequest('The file is empty.', 'empty_file');
        }
        if (strlen($bytes) > $rules['max_bytes']) {
            throw new ApiException(413, 'Payload too large', 'file_too_large', [], sprintf('Files can be at most %s MB.', round($rules['max_bytes'] / 1048576, 1) + 0));
        }
        if (FileStore::scopeBytes($scope) + strlen($bytes) > FileStore::maxScopeBytes()) {
            throw new ApiException(413, 'Payload too large', 'storage_quota_exceeded', [], 'Storage is full. Delete some files to upload more.');
        }
        $path = FileStore::store($scope, $bytes, $extension, $rules['prefix']);
        return $this->json(['path' => $path, 'size_bytes' => strlen($bytes), 'mime_type' => $mime], 201);
    }
}
