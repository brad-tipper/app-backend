<?php

namespace BradTipper\AppBackend\Auth;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * One signed-in device/session. Only SHA-256 hashes of the opaque access and
 * refresh tokens are stored.
 *
 * @property string $AccessHash
 * @property string $RefreshHash
 * @property int $AccessExpires
 * @property int $RefreshExpires
 * @property string $UserAgent
 * @property bool $MfaVerified
 * @property int $MemberID
 * @method Member Member()
 */
class ApiToken extends DataObject
{
    private static string $table_name = 'AppApiToken';

    private static array $db = [
        'AccessHash' => 'Varchar(64)',
        'RefreshHash' => 'Varchar(64)',
        'AccessExpires' => 'Int',
        'RefreshExpires' => 'Int',
        'UserAgent' => 'Varchar(255)',
        'MfaVerified' => 'Boolean',
    ];

    private static array $has_one = [
        'Member' => Member::class,
    ];

    private static array $indexes = [
        'AccessHash' => ['type' => 'unique', 'columns' => ['AccessHash']],
        'RefreshHash' => ['type' => 'unique', 'columns' => ['RefreshHash']],
        'RefreshExpires' => true,
    ];
}
