<?php

namespace BradTipper\AppBackend\Admin;

use ReflectionClass;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;

/**
 * One top-level grid per data type the app defines (every DataObject under the project's app/ folder), each
 * searchable by owner email and UUID, sortable by every summary column, newest first by default.
 * The models list itself, so a new DataObject shows up here without any configuration.
 */
class DataAdmin extends ModelAdmin
{
    private static string $url_segment = 'data';

    private static string $menu_title = 'Data';

    private static string $menu_icon_class = 'font-icon-database';

    private static $menu_priority = -0.4;

    private static int $page_length = 50;

    public function getManagedModels()
    {
        $models = [];
        $appPath = str_replace('\\', '/', BASE_PATH . '/app/');
        foreach (ClassInfo::subclassesFor(DataObject::class, false) as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || is_a($class, SiteTree::class, true)) {
                continue;
            }
            if (!str_starts_with(str_replace('\\', '/', (string) $reflection->getFileName()), $appPath)) {
                continue;
            }
            $models[$class] = ['dataClass' => $class, 'title' => singleton($class)->i18n_plural_name()];
        }
        uasort($models, fn(array $a, array $b) => strcasecmp($a['title'], $b['title']));
        return $models;
    }

    public function getList()
    {
        return parent::getList()->sort('LastEdited', 'DESC');
    }
}
