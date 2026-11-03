<?php

namespace BradTipper\AppBackend\Sync;

use BradTipper\AppBackend\Api\Uuid;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddExistingAutocompleter;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\ReadonlyField;
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

    /**
     * Admin: the owner and UUID are fixed once a row exists, and rows are linked to their parent from the parent's
     * own grid rather than picked from every user's data.
     */
    public function updateCMSFields(FieldList $fields): void
    {
        $record = $this->owner;
        $fields->removeByName(['DeletedAt']);
        if ($record->isInDB()) {
            if ($fields->dataFieldByName('UUID')) {
                $fields->replaceField('UUID', ReadonlyField::create('UUID', 'UUID'));
            }
            if ($fields->dataFieldByName('OwnerID')) {
                $owner = $record->Owner();
                $fields->replaceField('OwnerID', ReadonlyField::create(
                    'OwnerLabel',
                    'Owner',
                    $owner->exists() ? "{$owner->Email} (user {$owner->ID})" : ''
                ));
            }
        } else {
            $fields->removeByName(['UUID']);
        }
        foreach ($fields->dataFields() as $field) {
            if ($field instanceof GridField) {
                $config = $field->getConfig();
                $config->removeComponentsByType(GridFieldAddExistingAutocompleter::class);
                $config->removeComponentsByType(GridFieldAddNewButton::class);
            }
        }
    }

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
