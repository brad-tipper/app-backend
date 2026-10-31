<?php

namespace BradTipper\AppBackend\Jobs;

use SilverStripe\Core\Config\Configurable;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Makes sure every configured RecurringJob has a run waiting in the queue.
 * Called on `dev/build` (via SchedulerExtension) and by the heartbeat.
 */
final class Scheduler
{
    use Configurable;

    /** @var array<int, class-string<RecurringJob>> */
    private static array $jobs = [];

    public static function ensure(): void
    {
        $live = [QueuedJob::STATUS_NEW, QueuedJob::STATUS_INIT, QueuedJob::STATUS_RUN, QueuedJob::STATUS_WAIT];
        foreach ((array) self::config()->get('jobs') as $class) {
            $exists = QueuedJobDescriptor::get()
                ->filter(['Implementation' => $class, 'JobStatus' => $live])
                ->exists();
            if (!$exists) {
                // First run soon after a deploy; the job then keeps to its own schedule.
                $class::schedule(30);
            }
        }
    }
}
