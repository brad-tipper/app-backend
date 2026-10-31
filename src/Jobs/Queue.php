<?php

namespace BradTipper\AppBackend\Jobs;

use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Turns a function call into a queued job:
 *
 *   Queue::dispatch(Mailer::class, 'deliver', [$to, $subject, $body], Queue::NOW);
 *   Queue::dispatch(PushSender::class, 'send', [$userId], delaySeconds: 3600);
 *
 * Use it for anything slow, retryable or that should not hold up the response.
 */
final class Queue
{
    /** Run straight away (still recorded and retryable as a job). */
    public const NOW = QueuedJob::IMMEDIATE;

    /** Picked up by the next queue run (the cron / daemon). */
    public const LATER = QueuedJob::QUEUED;

    /** For long-running work, kept out of the way of the normal queue. */
    public const LARGE = QueuedJob::LARGE;

    /**
     * @param array<int, mixed> $args serialisable arguments
     * @return int the QueuedJobDescriptor ID
     */
    public static function dispatch(
        string $class,
        string $method,
        array $args = [],
        string $type = self::LATER,
        int $delaySeconds = 0,
        ?string $title = null,
    ): int {
        $job = new MethodCallJob($class, $method, $args, $title);
        $startAfter = $delaySeconds > 0 ? gmdate('Y-m-d H:i:s', time() + $delaySeconds) : null;
        $service = QueuedJobService::singleton();

        if ($type === self::NOW && $startAfter === null) {
            // Silverstripe only auto-runs "immediate" jobs in CLI, so run it inline here.
            // It is still stored as a job: a failure leaves a Broken record to inspect/restart.
            $id = (int) $service->queueJob($job, null, 0, self::LATER);
            try {
                $service->runJob($id);
            } catch (\Throwable $e) {
                error_log(sprintf('[queue] %s failed: %s', $job->getTitle(), $e->getMessage()));
            }
            return $id;
        }

        return (int) $service->queueJob($job, $startAfter, 0, $type === self::NOW ? self::LATER : $type);
    }
}
