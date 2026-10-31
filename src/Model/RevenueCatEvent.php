<?php

namespace BradTipper\AppBackend\Model;

use SilverStripe\ORM\DataObject;

/**
 * One processed RevenueCat webhook delivery; the unique EventId makes the
 * webhook idempotent.
 *
 * @property string $EventId
 * @property string $AppUserUuid
 * @property string $Type
 * @property string $EventAt
 * @property string $Environment
 * @property string $ProductId
 * @property string $EntitlementIds
 * @property string $Outcome
 * @property string $AppliedTier
 */
class RevenueCatEvent extends DataObject
{
    private static string $table_name = 'AppRevenueCatEvent';

    private static array $db = [
        'EventId' => 'Varchar(128)',
        'AppUserUuid' => 'Varchar(36)',
        'Type' => 'Varchar(64)',
        'EventAt' => 'Datetime',
        'Environment' => 'Varchar(32)',
        'ProductId' => 'Varchar(255)',
        'EntitlementIds' => 'Text',
        'Outcome' => 'Varchar(32)',
        'AppliedTier' => 'Varchar(8)',
    ];

    private static array $indexes = [
        'EventId' => ['type' => 'unique', 'columns' => ['EventId']],
        'AppUserUuid' => true,
    ];

    private static string $default_sort = 'Created DESC';
}
