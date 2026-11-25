<?php

namespace BradTipper\RestfulFramework\Plan;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * What free and Pro accounts may do, enforced on the server because an offline-first client cannot be
 * trusted to be the only gate. Each app subclasses this, sets its `tiers` in yml and adds a count method
 * per limited thing:
 *
 *   class PlanLimits extends \BradTipper\RestfulFramework\Plan\PlanLimits
 *   {
 *       public static function listCount(Member $member, ?string $excludeUuid = null): int
 *       {
 *           return self::countOwned(TaskList::class, $member, $excludeUuid);
 *       }
 *   }
 *
 *   App\Services\PlanLimits:
 *     tiers:
 *       free: { max_lists: 1 }
 *       pro: { max_lists: null }
 */
abstract class PlanLimits
{
    use Configurable;

    /** @var array<string, array<string, mixed>> tier name (`free`, `pro`) => limits */
    private static array $tiers = [];

    /** @return array<string, mixed> the limits for the member's current tier */
    public static function forMember(Member $member): array
    {
        return (array) static::config()->get('tiers')[$member->isPro() ? 'pro' : 'free'];
    }

    /**
     * How many live (not soft-deleted) rows of a synced class the account owns, optionally ignoring one
     * (the row being saved).
     *
     * @param class-string<DataObject> $class
     */
    protected static function countOwned(string $class, Member|int $member, ?string $excludeUuid = null): int
    {
        $rows = $class::get()->filter(['OwnerID' => $member instanceof Member ? $member->ID : $member, 'Deleted' => false]);
        return $excludeUuid ? $rows->exclude('UUID', $excludeUuid)->count() : $rows->count();
    }
}
