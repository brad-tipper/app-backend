<?php

namespace BradTipper\AppBackend\Jobs;

use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * A job that runs forever on a schedule: when it finishes it queues its own next
 * run. One live instance per class (the signature is the class name). List the
 * class under `BradTipper\AppBackend\Jobs\Scheduler: jobs:` and it is queued
 * on `dev/build`, so a fresh deploy starts the chain.
 */
abstract class RecurringJob extends AbstractQueuedJob
{
    /** Do the work. Exceptions are recorded; the schedule carries on. */
    abstract protected function run(): void;

    /** Seconds until the next run, measured from now. */
    abstract public static function secondsUntilNextRun(): int;

    public function getJobType(): string
    {
        return QueuedJob::QUEUED;
    }

    public function getSignature(): string
    {
        return md5(static::class);
    }

    public function setup(): void
    {
        parent::setup();
        $this->totalSteps = 1;
    }

    public function process(): void
    {
        try {
            $this->run();
        } catch (\Throwable $e) {
            $this->addMessage(sprintf('%s: %s', $e::class, $e->getMessage()), 'ERROR');
            error_log(sprintf('[job] %s failed: %s', static::class, $e->getMessage()));
        }
        $this->currentStep = 1;
        $this->isComplete = true;
    }

    public function afterComplete(): void
    {
        parent::afterComplete();
        static::schedule();
    }

    /** Queues the next run (a no-op when one is already waiting). */
    public static function schedule(?int $delaySeconds = null): void
    {
        $delay = $delaySeconds ?? static::secondsUntilNextRun();
        QueuedJobService::singleton()->queueJob(new static(), gmdate('Y-m-d H:i:s', time() + $delay), 0);
    }

    /** Seconds from now until the next `HH:MM` UTC. */
    protected static function secondsUntilUtc(string $hhmm): int
    {
        $target = strtotime(gmdate('Y-m-d') . " {$hhmm}:00 UTC");
        if ($target <= time()) {
            $target += 86400;
        }
        return $target - time();
    }
}
