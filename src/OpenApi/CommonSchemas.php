<?php

namespace BradTipper\AppBackend\OpenApi;

use BradTipper\AppBackend\Auth\ApiMemberExtension;

/** Schemas shared by every app's API. */
final class CommonSchemas
{
    public static function register(Spec $spec): void
    {
        $s = static fn (?string $format = null, bool $nullable = false, array $extra = []) => Spec::string($format, $nullable, $extra);

        $spec->schema('Problem', Spec::object([
            'type' => Spec::string(),
            'title' => Spec::string(),
            'status' => Spec::integer(),
            'detail' => Spec::string(),
            'code' => Spec::string(),
            'errors' => ['type' => 'object', 'additionalProperties' => Spec::arrayOf(Spec::string())],
        ], ['title', 'status', 'detail', 'code']));

        $spec->schema('Ok', Spec::object(['ok' => Spec::boolean()]));
        $spec->schema('Health', Spec::object(['status' => Spec::string(), 'time' => $s('date-time')]));

        $spec->schema('User', Spec::object([
            'id' => $s('uuid'),
            'email' => $s('email'),
            'name' => Spec::string(),
            'firstName' => Spec::string(),
            'lastName' => Spec::string(),
            'country' => $s(null, true),
            'timezone' => Spec::string(),
            'units' => Spec::enum(['metric', 'imperial']),
            'dateFormat' => Spec::string(),
            'preferences' => Spec::map(),
            'subscriptionTier' => Spec::enum(['free', 'pro']),
            'subscriptionStatus' => Spec::enum(['active', 'unsubscribed']),
            'subscriptionEventAt' => $s('date-time', true),
            'mfaEnabled' => Spec::boolean(),
            'emailConfirmed' => Spec::boolean(),
            'deletionScheduledFor' => $s('date-time', true),
            'createdAt' => $s('date-time', true),
        ]));

        $spec->schema('Session', Spec::object([
            'accessToken' => Spec::string(),
            'refreshToken' => Spec::string(),
            'expiresAt' => $s('date-time'),
            'user' => Spec::ref('User'),
        ]));

        $spec->schema('AuthResult', Spec::object([
            'session' => Spec::nullable(Spec::ref('Session')),
            'mfaRequired' => Spec::boolean(),
            'mfaToken' => $s(null, true),
            'confirmationRequired' => Spec::boolean(),
        ]));
    }

    /** Keeps the listed class referenced so autoload tools see the dependency. */
    public static function userSchemaSource(): string
    {
        return ApiMemberExtension::class;
    }
}
