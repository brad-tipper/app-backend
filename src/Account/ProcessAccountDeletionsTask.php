<?php

namespace BradTipper\AppBackend\Account;

use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\Member;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Permanently deletes accounts past their scheduled deletion date. Run daily:
 * `sake tasks:process-account-deletions`. Each account's RevenueCat customer is
 * removed first; failures stay queued for the next run.
 */
class ProcessAccountDeletionsTask extends BuildTask
{
    protected static string $commandName = 'process-account-deletions';

    protected string $title = 'Process due account deletions';

    protected static string $description = 'Deletes members whose scheduled deletion date has passed.';

    public function getOptions(): array
    {
        return [new InputOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum accounts per run', '25')];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $limit = max(1, min(100, (int) $input->getOption('limit')));
        $due = Member::get()
            ->filter('ScheduledForDeletionAt:LessThanOrEqual', gmdate('Y-m-d H:i:s'))
            ->exclude('ScheduledForDeletionAt', null)
            ->sort('ScheduledForDeletionAt', 'ASC')
            ->limit($limit);

        $deleted = 0;
        $failed = 0;
        foreach ($due as $member) {
            try {
                $this->deleteRevenueCatCustomer($member->UUID);
                $member->extend('onAccountDeletion');
                $member->delete();
                $deleted++;
            } catch (\Throwable $e) {
                $failed++;
                $output->writeln("Failed {$member->UUID}: {$e->getMessage()}");
            }
        }
        $output->writeln("Processed {$due->count()}; deleted {$deleted}; failed {$failed}.");
        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function deleteRevenueCatCustomer(string $uuid): void
    {
        $key = trim((string) Environment::getEnv('REVENUECAT_V2_SECRET_KEY'));
        $project = trim((string) Environment::getEnv('REVENUECAT_PROJECT_ID'));
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
        if ($status !== 404 && $status !== 202 && ($status < 200 || $status >= 300)) {
            throw new \RuntimeException("RevenueCat customer deletion failed ({$status}).");
        }
    }
}
