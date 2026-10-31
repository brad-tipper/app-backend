<?php

namespace BradTipper\AppBackend\Api;

use SilverStripe\Control\HTTPRequest;

/**
 * Small typed reader/validator for JSON request bodies. Each reader records a
 * field error instead of throwing, so one response can report every problem.
 */
final class Input
{
    /** @var array<string, string[]> */
    private array $errors = [];

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public static function fromRequest(HTTPRequest $request): self
    {
        $body = trim((string) $request->getBody());
        if ($body === '') {
            return new self([]);
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw ApiException::badRequest('Request body must be a JSON object.', 'invalid_json');
        }
        return new self($decoded);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function raw(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function string(string $key, bool $required = true, int $max = 255, int $min = 0): ?string
    {
        $value = $this->data[$key] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->fail($key, 'This field is required.');
            }
            return null;
        }
        if (!is_string($value)) {
            $this->fail($key, 'Must be a string.');
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            if ($required) {
                $this->fail($key, 'This field is required.');
            }
            return null;
        }
        if (mb_strlen($value) > $max) {
            $this->fail($key, "Must be at most {$max} characters.");
        }
        if (mb_strlen($value) < $min) {
            $this->fail($key, "Must be at least {$min} characters.");
        }
        return $value;
    }

    /** Passwords are never trimmed. */
    public function password(string $key, bool $required = true): ?string
    {
        $value = $this->data[$key] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->fail($key, 'This field is required.');
            }
            return null;
        }
        if (!is_string($value) || strlen($value) > 1024) {
            $this->fail($key, 'Invalid value.');
            return null;
        }
        return $value;
    }

    public function email(string $key, bool $required = true): ?string
    {
        $value = $this->string($key, $required, 254);
        if ($value === null) {
            return null;
        }
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->fail($key, 'Must be a valid email address.');
            return null;
        }
        return strtolower($value);
    }

    public function bool(string $key, bool $required = false): ?bool
    {
        if (!array_key_exists($key, $this->data) || $this->data[$key] === null) {
            if ($required) {
                $this->fail($key, 'This field is required.');
            }
            return null;
        }
        if (!is_bool($this->data[$key])) {
            $this->fail($key, 'Must be a boolean.');
            return null;
        }
        return $this->data[$key];
    }

    public function int(string $key, bool $required = false, ?int $min = null, ?int $max = null): ?int
    {
        if (!array_key_exists($key, $this->data) || $this->data[$key] === null) {
            if ($required) {
                $this->fail($key, 'This field is required.');
            }
            return null;
        }
        $value = $this->data[$key];
        if (!is_int($value)) {
            $this->fail($key, 'Must be an integer.');
            return null;
        }
        if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
            $this->fail($key, 'Out of range.');
        }
        return $value;
    }

    /** @param string[] $allowed */
    public function enum(string $key, array $allowed, bool $required = true): ?string
    {
        $value = $this->data[$key] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->fail($key, 'This field is required.');
            }
            return null;
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $this->fail($key, 'Must be one of: ' . implode(', ', $allowed) . '.');
            return null;
        }
        return $value;
    }

    public function uuid(string $key, bool $required = true): ?string
    {
        $value = $this->string($key, $required, 36);
        if ($value === null) {
            return null;
        }
        if (!Uuid::isValid($value)) {
            $this->fail($key, 'Must be a UUID.');
            return null;
        }
        return strtolower($value);
    }

    /** A calendar date, `YYYY-MM-DD`. */
    public function date(string $key, bool $required = true): ?string
    {
        $value = $this->string($key, $required, 10);
        if ($value === null) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$parsed || $parsed->format('Y-m-d') !== $value) {
            $this->fail($key, 'Must be a date in YYYY-MM-DD format.');
            return null;
        }
        return $value;
    }

    /** A time of day, `HH:MM` or `HH:MM:SS`; returned as `HH:MM:SS`. */
    public function time(string $key, bool $required = false): ?string
    {
        $value = $this->string($key, $required, 8);
        if ($value === null) {
            return null;
        }
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $value, $m)) {
            $this->fail($key, 'Must be a time in HH:MM format.');
            return null;
        }
        return sprintf('%s:%s:%s', $m[1], $m[2], $m[3] ?? '00');
    }

    /** @return array<string, mixed>|null */
    public function object(string $key, bool $required = false): ?array
    {
        $value = $this->data[$key] ?? null;
        if ($value === null) {
            if ($required) {
                $this->fail($key, 'This field is required.');
            }
            return null;
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->fail($key, 'Must be an object.');
            return null;
        }
        return $value;
    }

    public function fail(string $key, string $message): void
    {
        $this->errors[$key][] = $message;
    }

    public function assertValid(): void
    {
        if ($this->errors) {
            throw ApiException::validation($this->errors);
        }
    }
}
