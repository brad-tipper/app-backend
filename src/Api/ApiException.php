<?php

namespace BradTipper\AppBackend\Api;

use RuntimeException;

/**
 * Thrown anywhere inside an ApiController; rendered as RFC 9457 problem+json.
 */
class ApiException extends RuntimeException
{
    /**
     * @param array<string, string[]> $errors field => messages
     */
    public function __construct(
        public readonly int $status,
        public readonly string $title,
        public readonly string $errorCode = 'error',
        public readonly array $errors = [],
        ?string $detail = null,
    ) {
        parent::__construct($detail ?? $title, $status);
    }

    public static function badRequest(string $detail, string $code = 'bad_request'): self
    {
        return new self(400, 'Bad request', $code, [], $detail);
    }

    /** @param array<string, string[]> $errors */
    public static function validation(array $errors): self
    {
        return new self(422, 'Validation failed', 'validation_failed', $errors, 'One or more fields are invalid.');
    }

    public static function unauthorized(string $detail = 'Authentication is required.', string $code = 'unauthorized'): self
    {
        return new self(401, 'Unauthorized', $code, [], $detail);
    }

    public static function forbidden(string $detail = 'You do not have access to this resource.', string $code = 'forbidden'): self
    {
        return new self(403, 'Forbidden', $code, [], $detail);
    }

    public static function notFound(string $detail = 'Resource not found.'): self
    {
        return new self(404, 'Not found', 'not_found', [], $detail);
    }

    public static function methodNotAllowed(): self
    {
        return new self(405, 'Method not allowed', 'method_not_allowed', [], 'Method not allowed.');
    }

    public static function conflict(string $detail, string $code = 'conflict'): self
    {
        return new self(409, 'Conflict', $code, [], $detail);
    }

    public static function tooManyRequests(string $detail = 'Too many requests.'): self
    {
        return new self(429, 'Too many requests', 'rate_limited', [], $detail);
    }

    /** The caller is authenticated but their plan does not allow this. */
    public static function planLimit(string $detail): self
    {
        return new self(403, 'Plan limit reached', 'plan_limit', [], $detail);
    }

    public static function internal(): self
    {
        return new self(500, 'Internal server error', 'internal_error', [], 'Something went wrong.');
    }
}
