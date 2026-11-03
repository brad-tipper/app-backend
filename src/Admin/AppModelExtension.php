<?php

namespace BradTipper\AppBackend\Admin;

use ReflectionClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;

/**
 * Sensible admin grids for every DataObject the project defines under app/ without per-class configuration: a
 * class that sets its own summary_fields / searchable_fields keeps them, otherwise the first few short columns
 * (and the owner's email where rows have an owner) are shown, and every column is searchable.
 *
 * @property DataObject|AppModelExtension $owner
 */
class AppModelExtension extends Extension
{
    /** @var array<class-string, bool> */
    private static array $appClasses = [];

    /** @param array<string, string> $fields */
    public function updateSummaryFields(array &$fields): void
    {
        if (!$this->isAppClass()) {
            return;
        }
        $record = $this->owner;
        $schema = DataObject::getSchema();
        $relationColumns = [];
        foreach (array_keys($record->hasOne()) as $relation) {
            $relationColumns[$relation . 'ID'] = true;
        }
        if (!$record->config()->get('summary_fields')) {
            $fields = [];
            $skip = ['ID', 'ClassName', 'Created', 'LastEdited', 'UUID', 'Deleted', 'DeletedAt'];
            foreach ($schema->databaseFields($record::class) as $name => $spec) {
                if (in_array($name, $skip, true) || isset($relationColumns[$name]) || preg_match('/Hash|Token|Secret|Password/i', $name) || preg_match('/^(Text|HTMLText|HTMLVarchar|JSON|MultiEnum)/i', (string) $spec)) {
                    continue;
                }
                $fields[$name] = $name;
                if (count($fields) >= 6) {
                    break;
                }
            }
            foreach (array_keys($record->hasOne()) as $relation) {
                if ($relation !== 'Owner' && count($fields) < 8) {
                    $fields[$relation . 'ID'] = $relation;
                }
            }
        }
        if ($schema->hasOneComponent($record::class, 'Owner')) {
            $fields['Owner.Email'] ??= 'Owner';
        }
        if ($record->hasField('Deleted')) {
            $fields['Deleted'] ??= 'Deleted';
        }
        $fields['LastEdited'] ??= 'Last edited';
    }

    /** @param array<string, array<string, mixed>> $fields */
    public function updateSearchableFields(array &$fields): void
    {
        if (!$this->isAppClass()) {
            return;
        }
        $record = $this->owner;
        unset($fields['LastEdited'], $fields['Created']);
        $schema = DataObject::getSchema();
        foreach (array_keys($fields) as $name) {
            if ($name !== 'OwnerID' && str_ends_with((string) $name, 'ID') && isset($record->hasOne()[substr((string) $name, 0, -2)])) {
                unset($fields[$name]);
            }
        }
        if (DataObject::getSchema()->hasOneComponent($record::class, 'Owner')) {
            $fields['Owner.Email'] = ['title' => 'Owner email', 'filter' => 'PartialMatchFilter'];
        }
        if ($record->hasField('UUID')) {
            $fields['UUID'] = ['title' => 'UUID', 'filter' => 'PartialMatchFilter'];
        }
        if ($record->hasField('Deleted')) {
            $fields['Deleted'] = ['title' => 'Deleted', 'filter' => 'ExactMatchFilter'];
        }
    }

    private function isAppClass(): bool
    {
        $class = $this->owner::class;
        return self::$appClasses[$class] ??= !is_a($class, SiteTree::class, true)
            && str_starts_with(
                str_replace('\\', '/', (string) (new ReflectionClass($class))->getFileName()),
                str_replace('\\', '/', BASE_PATH . '/app/')
            );
    }
}
