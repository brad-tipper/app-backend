# app-backend

Shared Silverstripe 6 headless API foundations for the app fleet. Each `{app}-api` project requires this module.

- `Api/` - `ApiController` (JSON in/out, problem+json errors, method/path routing), `Input` validation, CORS middleware, health.
- `Auth/` - opaque access + rotating refresh tokens, sign up/in/out, password reset, magic link, email confirmation, TOTP 2FA, profile.
- `Account/` - contact/feedback/tickets (Turnstile + rate limit), data export, 30-day account deletion, RevenueCat webhook, device push tokens.
- `Sync/` - `SyncedExtension` (UUID ids, soft delete, immutable owner) and `SyncController` (delta fetch + idempotent PUT) for offline-first entities.
- `Files/` - `FileStore` (private storage under the owner's UUID, signed URLs), `UploadsController` and `FilesController`. Subclass the uploads controller to customise who may upload what.
- `Plan/` - `PlanLimits` base class: per-tier limits from yml plus an owned-row counter. Each app subclasses it.
- `Jobs/` - `RecurringJob` + `Scheduler` (self-requeueing scheduled jobs) and `SendEmailJob`, on top of symbiote/queuedjobs.
- `Admin/` - the per-user tabs under Security -> Users, plus the Support and Tickets admins. Each app defines its own `ModelAdmin` for its data.
- `OpenApi/` - a small builder; every routed controller has `describe(Spec $spec)`. `sake tasks:openapi-export --output=openapi.json` writes the contract hey-api generates the client from.

Opt-in controllers (`DevicePushTokensController`, `Files\UploadsController`, `Files\FilesController`) are routed in the app's own yml, e.g. `'api/v1/device-push-tokens': BradTipper\AppBackend\Account\DevicePushTokensController`. Only routed controllers appear in the app's OpenAPI document.

Mail: set `MAILER_DSN` (a real environment variable, or `Environment::setEnv()` in the project's `app/_config.php`). There is no module-level transport config.

Config lives in `_config/app-backend.yml`. See each `{app}-api` README for environment variables.

## Support portal and login branding

- `/portal` is a standalone, branded page for the project's website: customers sign in with their app email and password (two-factor supported) to see their tickets, read replies, add notes, close or reopen, and open new requests. `/portal?view=contact` and `/portal?view=feedback` show the contact and feedback forms for visitors. Nothing here uses a session or cookie, so the pages stay cacheable; the widgets call the same `/api/v1` endpoints as the apps (tokens live in `sessionStorage`).
- Embed the widgets elsewhere on the site with `<div data-support-portal></div>`, `<div data-contact-form></div>` or `<div data-feedback-form></div>` plus `<script src="/portal/widget.js" defer></script>`. The script is `client/portal.js` with the project's branding prepended by `PortalController`.
- Branding comes from `AppConfig`: `name`, `brand_color`, `brand_color_text`, `brand_logo_url`, `privacy_url`, and `turnstile_site_key` (a website's `SiteConfig.TurnstileSiteKey` wins when it has one; with neither, the Turnstile check is skipped, which the API only allows outside live).
- The Silverstripe login pages are branded by the separate `bradtipper/silverstripe-login-screens` module (YAML colours and logo); install and configure it per project.
