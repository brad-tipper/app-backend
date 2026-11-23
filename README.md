# app-backend

Shared Silverstripe 6 headless API foundations for the app fleet. Each `{app}-api` project requires this module.

- `Api/` - `ApiController` (JSON in/out, problem+json errors, method/path routing), `Input` validation, CORS middleware, health.
- `Auth/` - opaque access + rotating refresh tokens, sign up/in/out, password reset, magic link, email confirmation, TOTP 2FA, profile.
- `Account/` - tickets (rate limited), data export, 30-day account deletion, RevenueCat webhook, device push tokens.
- `Sync/` - `SyncedExtension` (UUID ids, soft delete, immutable owner) and `SyncController` (delta fetch + idempotent PUT) for offline-first entities.
- `Files/` - `FileStore` (private storage under the owner's UUID, signed URLs), `UploadsController` and `FilesController`. Subclass the uploads controller to customise who may upload what.
- `Plan/` - `PlanLimits` base class: per-tier limits from yml plus an owned-row counter. Each app subclasses it.
- `Jobs/` - `RecurringJob` + `Scheduler` (self-requeueing scheduled jobs) and `SendEmailJob`, on top of symbiote/queuedjobs.
- `Admin/` - the per-user tabs under Security -> Users, plus the Support and Tickets admins. Each app defines its own `ModelAdmin` for its data.

Opt-in controllers (`DevicePushTokensController`, `Files\UploadsController`, `Files\FilesController`) are routed in the app's own yml, e.g. `'api/v1/device-push-tokens': BradTipper\AppBackend\Account\DevicePushTokensController`.

Mail: set `MAILER_DSN` (a real environment variable, or `Environment::setEnv()` in the project's `app/_config.php`). There is no module-level transport config.

Config lives in `_config/app-backend.yml`. See each `{app}-api` README for environment variables.
