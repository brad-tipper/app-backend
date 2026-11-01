<?php

namespace BradTipper\AppBackend\Account;

use BradTipper\AppBackend\AppConfig;
use BradTipper\AppBackend\Jobs\RecurringJob;
use SilverStripe\Core\Environment;
use SilverStripe\Security\Member;

/**
 * Daily: permanently deletes accounts past their 30-day deletion grace period.
 * The RevenueCat customer goes first; a failure leaves the account queued for tomorrow.
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
                self::deleteRevenueCatCustomer($member->UUID);
                $member->extend('onAccountDeletion');
                $member->delete();
                $deleted++;
            } catch (\Throwable $e) {
                $this->addMessage("Could not delete {$member->UUID}: {$e->getMessage()}", 'ERROR');
            }
        }
        $this->addMessage("Deleted {$deleted} of {$due->count()} due accounts.");
    }

    private static function deleteRevenueCatCustomer(string $uuid): void
    {
        if (!AppConfig::get('revenuecat_enabled')) {
            return;
        }
        $key = trim((string) AppConfig::secret('revenuecat_v2_secret_key'));
        $project = trim((string) AppConfig::get('revenuecat_project_id'));
        if ($key === '' || $project === '') {
            // A paying customer must never be orphaned in live, so retry instead.
            if (Environment::getEnv('SS_ENVIRONMENT_TYPE') === 'live') {
                throw new \RuntimeException('RevenueCat account deletion is not configured.');
            }
            return;
        }
        $context = stream_context_create(['http' => [
            'method' => 'DELETE',
            'header' => "Authorization: Bearer {$key}\r\nAccept: application/json",
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        @file_get_contents('https://api.revenuecat.com/v2/projects/' . rawurlencode($project) . '/customers/' . rawurlencode($uuid), false, $context);
        $status = (int) (preg_match('/ (\d{3}) /', $http_response_header[0] ?? '', $m) ? $m[1] : 0);
        if ($status !== 404 && ($status < 200 || $status >= 300)) {
            throw new \RuntimeException("RevenueCat customer deletion failed ({$status}).");
        }
    }
}
