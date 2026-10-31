<?php

namespace BradTipper\AppBackend\Sync;

use BradTipper\AppBackend\Api\Uuid;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * Makes a DataObject safe for an offline-first client: a client-generated UUID
 * is the public id, rows are soft-deleted, and the owner can never change.
 * Replaces the handle_times / handle_soft_delete / protect_owner SQL triggers.
 *
 * @property DataObject|SyncedExtension $owner
 * @property string $UUID
 * @property bool $Deleted
 * @property string $DeletedAt
 * @property int $OwnerID
 * @method Member Owner()
 */
class SyncedExtension extends Extension
{
    private static array $db = [
        'UUID' => 'Varchar(36)',
        'Deleted' => 'Boolean',
        'DeletedAt' => 'Datetime',
    ];

    private static array $has_one = [
        'Owner' => Member::class,
    ];

    private static array $indexes = [
        'UUID' => ['type' => 'unique', 'columns' => ['UUID']],
    ];

    public function onBeforeWrite(): void
    {
        $record = $this->owner;
        if (!$record->UUID) {
            $record->UUID = Uuid::v4();
        }
        if ($record->isChanged('Deleted', DataObject::CHANGE_VALUE)) {
            $record->DeletedAt = $record->Deleted ? gmdate('Y-m-d H:i:s') : null;
        }
        // Ownership is immutable once a row exists.
        if ($record->isInDB()) {
            $changed = $record->getChangedFields();
            if (isset($changed['OwnerID']) && (int) $changed['OwnerID']['before'] > 0) {
                $record->OwnerID = (int) $changed['OwnerID']['before'];
            }
        }
    }
}
