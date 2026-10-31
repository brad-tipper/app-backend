<?php

namespace BradTipper\AppBackend\Jobs;

use SilverStripe\Core\Extension;

/** Hooks Scheduler::ensure() into `dev/build` via QueuedJobDescriptor's default records. */
class SchedulerExtension extends Extension
{
    public function onRequireDefaultRecords(): void
    {
        try {
            Scheduler::ensure();
        } catch (\Throwable $e) {
            error_log('[scheduler] ' . $e->getMessage());
        }
    }
}
