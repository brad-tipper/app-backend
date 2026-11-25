<?php

namespace BradTipper\RestfulFramework\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * An Expo push token for one of a person's devices. Written through `PUT /api/v1/device-push-tokens`
 * (DevicePushTokensController), not delta-synced; removed with the account.
 *
 * @property string $Token
 * @property string $Platform
 * @property string $Timezone
 * @property int $OwnerID
 */
class DevicePushToken extends DataObject
{
    public const PLATFORMS = ['ios', 'android', 'web'];

    private static string $table_name = 'AppDevicePushToken';

    private static array $db = [
        'Token' => 'Varchar(255)',
        'Platform' => "Enum('ios,android,web','ios')",
        'Timezone' => 'Varchar(64)',
    ];

    private static array $has_one = [
        'Owner' => Member::class,
    ];

    private static array $indexes = [
        'OwnerToken' => ['type' => 'unique', 'columns' => ['OwnerID', 'Token']],
    ];

    private static array $summary_fields = ['Platform', 'Owner.Email'];
}
