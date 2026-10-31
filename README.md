# app-backend

Shared Silverstripe 6 headless API foundations for the app fleet. Each `{app}-api` project requires this module.

- `Api/` - `ApiController` (JSON in/out, problem+json errors, method/path routing), `Input` validation, CORS middleware, health.
- `Auth/` - opaque access + rotating refresh tokens, sign up/in/out, password reset, magic link, email confirmation, TOTP 2FA, profile.
- `Account/` - contact/feedback (Turnstile + rate limit), data export, 30-day account deletion, RevenueCat webhook.
- `Sync/` - `SyncedExtension` (UUID ids, soft delete, immutable owner) and `SyncController` (delta fetch + idempotent PUT) for offline-first entities.
- `OpenApi/` - a small builder; every controller has `describe(Spec $spec)`. `sake tasks:openapi-export --output=openapi.json` writes the contract hey-api generates the client from.

Config lives in `_config/app-backend.yml`. See each `{app}-api` README for environment variables.
