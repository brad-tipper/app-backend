<?php

namespace BradTipper\AppBackend\Api;

use BradTipper\AppBackend\Auth\SessionService;
use BradTipper\AppBackend\Support\RateLimiter;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Member;

/**
 * Base for every JSON endpoint. It bypasses Silverstripe's action/allowed_actions
 * machinery (which renders HTML errors) and routes by HTTP method + path itself,
 * so every failure comes back as problem+json.
 */
abstract class ApiController extends Controller
{
    private ?Member $authenticatedMember = null;

    private bool $memberResolved = false;

    public function handleRequest(HTTPRequest $request): HTTPResponse
    {
        $this->setRequest($request);
        $this->setResponse(HTTPResponse::create());
        $this->pushCurrent();
        try {
            $result = $this->dispatch($request);
            if ($result instanceof HTTPResponse) {
                return $result;
            }
            return $this->json($result ?? ['ok' => true]);
        } catch (ApiException $e) {
            return $this->problem($e);
        } catch (\Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->error($e->getMessage(), ['exception' => $e]);
            error_log(sprintf('[api] %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
            return $this->problem(ApiException::internal());
        } finally {
            $this->popCurrent();
        }
    }

    /**
     * Map of `"METHOD path"` => handler method name, used by the default dispatch().
     *
     * @return array<string, string>
     */
    protected function routes(): array
    {
        return [];
    }

    /** @return HTTPResponse|array<mixed>|null */
    protected function dispatch(HTTPRequest $request): HTTPResponse|array|null
    {
        $path = $this->routePath($request);
        $routes = $this->routes();
        $key = strtoupper($request->httpMethod()) . ' ' . $path;
        if (isset($routes[$key])) {
            return $this->{$routes[$key]}($request);
        }
        foreach (array_keys($routes) as $candidate) {
            if (str_ends_with($candidate, ' ' . $path)) {
                throw ApiException::methodNotAllowed();
            }
        }
        throw ApiException::notFound();
    }

    protected function routePath(HTTPRequest $request): string
    {
        $parts = [$request->param('Action'), $request->param('ID'), $request->param('OtherID')];
        return implode('/', array_filter($parts, static fn ($part) => $part !== null && $part !== ''));
    }

    /** @param array<mixed>|object $data */
    protected function json(array|object $data, int $status = 200): HTTPResponse
    {
        $response = HTTPResponse::create(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            $status,
        );
        $response->addHeader('Content-Type', 'application/json');
        $response->addHeader('Cache-Control', 'no-store');
        return $response;
    }

    protected function noContent(): HTTPResponse
    {
        $response = HTTPResponse::create('', 204);
        $response->addHeader('Cache-Control', 'no-store');
        return $response;
    }

    protected function problem(ApiException $e): HTTPResponse
    {
        $body = [
            'type' => 'about:blank',
            'title' => $e->title,
            'status' => $e->status,
            'detail' => $e->getMessage(),
            'code' => $e->errorCode,
        ];
        if ($e->errors) {
            $body['errors'] = $e->errors;
        }
        $response = HTTPResponse::create(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $e->status);
        $response->addHeader('Content-Type', 'application/problem+json');
        $response->addHeader('Cache-Control', 'no-store');
        if ($e->status === 401) {
            $response->addHeader('WWW-Authenticate', 'Bearer');
        }
        return $response;
    }

    protected function requireMethod(HTTPRequest $request, string ...$methods): void
    {
        if (!in_array(strtoupper($request->httpMethod()), $methods, true)) {
            throw ApiException::methodNotAllowed();
        }
    }

    protected function bearerToken(HTTPRequest $request): ?string
    {
        $header = $request->getHeader('Authorization');
        if ($header && preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return $m[1];
        }
        return null;
    }

    protected function currentMember(HTTPRequest $request): ?Member
    {
        if (!$this->memberResolved) {
            $token = $this->bearerToken($request);
            $this->authenticatedMember = $token ? SessionService::memberForAccessToken($token) : null;
            $this->memberResolved = true;
        }
        return $this->authenticatedMember;
    }

    protected function requireMember(HTTPRequest $request): Member
    {
        $member = $this->currentMember($request);
        if (!$member) {
            throw ApiException::unauthorized();
        }
        return $member;
    }

    /** Behind Cloudflare the real client address is in CF-Connecting-IP. */
    protected function clientIp(HTTPRequest $request): string
    {
        $cf = $request->getHeader('CF-Connecting-IP');
        if ($cf && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }
        return $request->getIP() ?: 'unknown';
    }

    protected function limit(string $key, int $max, int $windowSeconds): void
    {
        if (!RateLimiter::hit($key, $max, $windowSeconds)) {
            throw ApiException::tooManyRequests();
        }
    }
}
