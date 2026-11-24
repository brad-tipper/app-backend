<?php

namespace BradTipper\AppBackend\Account;

use BradTipper\AppBackend\Jobs\RecurringJob;
use BradTipper\AppBackend\Support\Notifications;
use SilverStripe\Security\Member;

/**
 * Daily: permanently deletes accounts past their 30-day deletion grace period.
 * Modules hook `onAccountDeletion` on the member (for example to remove an external billing customer); an exception there leaves the account queued for tomorrow.
 */
class ProcessAccountDeletionsJob extends RecurringJob
{
    private const BATCH = 25;

    public static function secondsUntilNextRun(): int
    {
        return self::secondsUntilUtc('03:15');
    }

    public function getTitle(): string
    {
        return 'Process due account deletions';
    }

    protected function run(): void
    {
        $due = Member::get()
            ->filter('ScheduledForDeletionAt:LessThanOrEqual', gmdate('Y-m-d H:i:s'))
            ->exclude('ScheduledForDeletionAt', null)
            ->sort('ScheduledForDeletionAt', 'ASC')
            ->limit(self::BATCH);

        $deleted = 0;
        foreach ($due as $member) {
            try {
                $email = trim((string) $member->Email);
                $member->extend('onAccountDeletion');
                $member->delete();
                if ($email !== '') {
                    Notifications::accountDeleted($email);
                }
                $deleted++;
            } catch (\Throwable $e) {
                $this->addMessage("Could not delete {$member->UUID}: {$e->getMessage()}", 'ERROR');
            }
        }
        $this->addMessage("Deleted {$deleted} of {$due->count()} due accounts.");
    }
}
