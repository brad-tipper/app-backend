<?php

namespace BradTipper\RestfulFramework\Testing;

use BradTipper\RestfulFramework\AppConfig;
use BradTipper\RestfulFramework\Api\ApiController;
use BradTipper\RestfulFramework\Api\ApiException;
use BradTipper\RestfulFramework\Api\Input;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Environment;

/**
 * `POST /api/v1/_e2e/seed` for the Playwright runner. It does not exist unless the
 * site is in `dev` mode AND E2E_SEED_SECRET is set and presented in `X-E2E-Secret`.
 * Deliberately left out of the OpenAPI document.
 */
class E2eSeedController extends ApiController
{
    protected function dispatch(HTTPRequest $request): array
    {
        $secret = (string) AppConfig::get('e2e_seed_secret');
        if (Environment::getEnv('SS_ENVIRONMENT_TYPE') !== 'dev' || $secret === '') {
            throw ApiException::notFound();
        }
        $this->requireMethod($request, 'POST');
        if (!hash_equals($secret, (string) $request->getHeader('X-E2E-Secret'))) {
            throw ApiException::forbidden();
        }
        $body = json_decode((string) $request->getBody(), true);
        $users = is_array($body['users'] ?? null) ? $body['users'] : [];
        return E2eSeeder::run($users);
    }
}
