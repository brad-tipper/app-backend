<?php

namespace BradTipper\AppBackend\Api;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\DB;

class HealthController extends ApiController
{
    protected function dispatch(HTTPRequest $request): array
    {
        $this->requireMethod($request, 'GET');
        DB::query('SELECT 1');
        return ['status' => 'ok', 'time' => gmdate(DATE_ATOM)];
    }
}
