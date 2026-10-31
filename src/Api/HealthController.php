<?php

namespace BradTipper\AppBackend\Api;

use BradTipper\AppBackend\OpenApi\Spec;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\DB;

class HealthController extends ApiController
{
    public static function describe(Spec $spec): void
    {
        $spec->operation('get', '/v1/health', 'getHealth', [
            'tags' => ['System'],
            'summary' => 'Liveness and database check',
            'responses' => [200 => Spec::ref('Health')],
        ]);
    }

    protected function dispatch(HTTPRequest $request): array
    {
        $this->requireMethod($request, 'GET');
        DB::query('SELECT 1');
        return ['status' => 'ok', 'time' => gmdate(DATE_ATOM)];
    }
}
