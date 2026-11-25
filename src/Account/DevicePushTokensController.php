<?php

namespace BradTipper\RestfulFramework\Account;

use BradTipper\RestfulFramework\Api\ApiController;
use BradTipper\RestfulFramework\Api\Input;
use BradTipper\RestfulFramework\Model\DevicePushToken;
use BradTipper\RestfulFramework\OpenApi\Spec;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;

/**
 * Registers (idempotently) the Expo push token of the caller's device. Opt in per app by routing
 * `'api/v1/device-push-tokens'` to it; the app's own jobs read DevicePushToken to send notifications.
 */
class DevicePushTokensController extends ApiController
{
    public static function describe(Spec $spec): void
    {
        $spec->schema('DevicePushTokenInput', Spec::object([
            'token' => Spec::string(),
            'platform' => Spec::enum(DevicePushToken::PLATFORMS),
            'timezone' => Spec::string(null, true),
        ], ['token', 'platform']));
        $spec->operation('put', '/api/v1/device-push-tokens', 'registerDevicePushToken', [
            'tags' => ['Notifications'],
            'summary' => 'Register this device for push notifications',
            'auth' => true,
            'body' => Spec::ref('DevicePushTokenInput'),
            'responses' => [200 => Spec::ref('Ok')],
        ]);
    }

    protected function dispatch(HTTPRequest $request): HTTPResponse
    {
        $this->requireMethod($request, 'PUT');
        $member = $this->requireMember($request);
        $in = Input::fromRequest($request);
        $token = $in->string('token', true, 255);
        $platform = $in->enum('platform', DevicePushToken::PLATFORMS);
        $timezone = $in->string('timezone', false, 64);
        $in->assertValid();

        $row = DevicePushToken::get()->filter(['OwnerID' => $member->ID, 'Token' => $token])->first()
            ?? DevicePushToken::create(['OwnerID' => $member->ID, 'Token' => $token]);
        $row->Platform = $platform;
        $row->Timezone = $timezone ?? '';
        $row->write();
        return $this->json(['ok' => true]);
    }
}
