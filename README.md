# silverstripe-restful-framework

Silverstripe 6 headless RESTful API foundations for apps: JSON controllers, token auth with two-factor, offline-first sync, files, queued jobs and transactional mail. One project per app requires this module and adds its own models and controllers.

Optional modules that build on it:

- `bradtipper/silverstripe-service-desk`: tickets, contact and feedback, CMS admin, public support portal.
- `bradtipper/silverstripe-revenuecat`: RevenueCat webhook, subscription events and emails.
- `bradtipper/silverstripe-openapi-export`: the OpenAPI builder (a dependency of this module).
- `bradtipper/silverstripe-cloudflare-page-cache`: full-page cache with Cloudflare purge.
- `bradtipper/silverstripe-login-screens`: brand the Silverstripe login pages from YAML.

## Install

```sh
composer require bradtipper/silverstripe-restful-framework
```

## What is in it

- `Api/`: `ApiController` (JSON in and out, problem+json errors, method and path routing), `Input` validation, CORS middleware, health.
- `Auth/`: opaque access and rotating refresh tokens, sign up and in, password reset, magic link, email confirmation, TOTP two-factor, profile.
- `Account/`: data export, 30-day account deletion, device push tokens.
- `Sync/`: `SyncedExtension` (UUID ids, soft delete, immutable owner) and `SyncController` (delta fetch, idempotent PUT) for offline-first entities.
- `Files/`: `FileStore` (private storage under the owner's UUID, signed URLs), `UploadsController`, `FilesController`. Subclass the uploads controller to control who may upload what.
- `Plan/`: `PlanLimits` base class: per-tier limits from YAML plus an owned-row counter. Each app subclasses it.
- `Jobs/`: `RecurringJob`, `Scheduler` (self-requeueing scheduled jobs) and `SendEmailJob`, on top of symbiote/queuedjobs.
- `Admin/`: the per-user tabs under Security, Users.
- `Support/`: mailer, email templates, rate limiter, TOTP, Turnstile, secrets.
- `OpenApi/CommonSchemas`: the shared schemas for the export.

Opt-in controllers (`DevicePushTokensController`, `Files\UploadsController`, `Files\FilesController`) are routed in the app's own YAML. Only routed controllers appear in the OpenAPI document.

## Configure

Everything an app sets lives in `BradTipper\RestfulFramework\AppConfig`, in the project's `app/_config/app.yml`. Use `Only: environment` fragments for per-environment values, and keep secrets in a `live` fragment.

```yaml
---
Name: myapp-config
---
BradTipper\RestfulFramework\AppConfig:
  name: MyApp                              # emails and the authenticator entry
  public_url: 'https://myapp.example'      # this API's origin (openapi.json, redirects)
  web_url: 'https://use.myapp.example'     # the web app; default target of emailed links
  redirect_origins:                        # allowed `redirectUrl` origins
    - 'https://use.myapp.example'
  redirect_schemes:                        # native deep-link schemes
    - myapp
  cors_origins:                            # empty means any origin (tokens are bearer, not cookies)
    - 'https://use.myapp.example'
  mail_from_address: 'no-reply@myapp.example'
  mail_from_name: MyApp
  support_email: 'support@myapp.example'   # replies and "contact us"; empty falls back to the sender
  brand_color: '#2f4c63'                   # email buttons and headings
  brand_color_text: '#ffffff'
  brand_logo_url: 'https://myapp.example/brand/apple-touch-icon.png'
  subscription_path: '/subscription'       # web app screens linked from emails
  tickets_path: '/support'
---
Name: myapp-config-dev
Only:
  environment: dev
---
BradTipper\RestfulFramework\AppConfig:
  public_url: 'https://myapp-api.ddev.site'
  web_url: 'http://localhost:8081'
  rate_limit_multiplier: 50                # tests sign in repeatedly
---
Name: myapp-secrets-live
Only:
  environment: live
---
BradTipper\RestfulFramework\AppConfig:
  app_key: '...'                           # base64 key for encrypting secrets
  smtp_password: '...'                     # your app/_config.php builds MAILER_DSN from it
  turnstile_secret_key: '...'              # Cloudflare Turnstile, for web sign-up and contact forms
```

Other keys: `rate_limit_multiplier`, `e2e_captcha_token` and `e2e_seed_secret` (automated tests only, never in live), `privacy_url`, `turnstile_site_key`, and the RevenueCat keys documented in `silverstripe-revenuecat`.

Sessions, password rules and the account deletion grace period have defaults in `_config/restful-framework.yml` and are overridden the same way:

```yaml
BradTipper\RestfulFramework\Auth\SessionService:
  access_ttl: 3600                         # seconds
  refresh_ttl: 5184000
  require_email_confirmation: true
BradTipper\RestfulFramework\Account\AccountController:
  deletion_grace_days: 30
SilverStripe\Security\Member:
  lock_out_after_incorrect_logins: 10
  lock_out_delay_mins: 15
```

Route opt-in controllers:

```yaml
SilverStripe\Control\Director:
  rules:
    'api/v1/device-push-tokens': BradTipper\RestfulFramework\Account\DevicePushTokensController
    'api/v1/uploads//$Action': BradTipper\RestfulFramework\Files\UploadsController
    'api/v1/files//$ID/$OtherID': BradTipper\RestfulFramework\Files\FilesController
```

Make your own model sync (UUID, soft delete, owner):

```yaml
App\Model\Note:
  extensions:
    - BradTipper\RestfulFramework\Sync\SyncedExtension
```

Schedule your own recurring job (a `RecurringJob` subclass):

```yaml
BradTipper\RestfulFramework\Jobs\Scheduler:
  jobs:
    - App\Jobs\SendDigestJob
```

Mail: set `MAILER_DSN` (a real environment variable, or `Environment::setEnv()` in the project's `app/_config.php`). There is no module-level transport config.

## Extension points

Other modules (and your project) hook the Member and account flows:

- `Member::updateCMSFields`: add tabs to Security, Users.
- `Member::updateAccountExport(array &$data)`: add data to the account export.
- `Member::onAccountDeletion()`: clean up before an account is deleted. An exception keeps the account queued for the next run.

## OpenAPI

```sh
vendor/bin/sake tasks:openapi-export --output=openapi.json --title="MyApp API"
```

Each routed controller describes itself with a static `describe(Spec $spec)`; see `silverstripe-openapi-export`.
