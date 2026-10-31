<?php

namespace BradTipper\AppBackend\Testing;

use BradTipper\AppBackend\Auth\ApiToken;
use BradTipper\AppBackend\Auth\AuthChallenge;
use BradTipper\AppBackend\Model\ContactSubmission;
use BradTipper\AppBackend\Model\FeedbackSubmission;
use BradTipper\AppBackend\Model\RevenueCatEvent;
use BradTipper\AppBackend\Support\RateLimitBucket;
use BradTipper\AppBackend\Support\Secrets;
use BradTipper\AppBackend\Support\Totp;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\DefaultAdminService;
use SilverStripe\Security\Member;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Resets the database to a known state for Playwright:
 *
 *   sake tasks:e2e-seed --yes --users='[{"email":"a@x.test","password":"Password123!","name":"A B","tier":"pro","totp":true,"preferences":{}}]'
 *
 * Refuses to run outside a dev environment. Prints one line,
 * `E2E_SEED_RESULT {json}`, with the TOTP secrets and recovery codes it made.
 */
class E2eSeedTask extends BuildTask
{
    protected static string $commandName = 'e2e-seed';

    protected string $title = 'Reset data and seed e2e users (dev only)';

    protected static string $description = 'Deletes all users and their data, then creates the given fixture users.';

    public function getOptions(): array
    {
        return [
            new InputOption('users', null, InputOption::VALUE_REQUIRED, 'JSON array of users to create', '[]'),
            new InputOption('yes', null, InputOption::VALUE_NONE, 'Confirm that all data will be deleted'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        if (Environment::getEnv('SS_ENVIRONMENT_TYPE') !== 'dev' || !$input->getOption('yes')) {
            $output->writeln('Refusing to run: needs SS_ENVIRONMENT_TYPE=dev and --yes.');
            return Command::FAILURE;
        }
        $users = json_decode((string) $input->getOption('users'), true);
        if (!is_array($users)) {
            $output->writeln('--users must be a JSON array.');
            return Command::FAILURE;
        }

        $this->reset();

        $results = [];
        foreach ($users as $spec) {
            $results[$spec['email']] = $this->createUser($spec);
        }
        $output->writeln('E2E_SEED_RESULT ' . json_encode($results, JSON_UNESCAPED_SLASHES));
        return Command::SUCCESS;
    }

    private function reset(): void
    {
        $admin = DefaultAdminService::singleton()->findOrCreateDefaultAdmin();
        // Deleting a member cascades to everything it owns (see ApiMemberExtension).
        foreach (Member::get()->exclude('ID', $admin->ID) as $member) {
            $member->delete();
        }
        foreach ([ApiToken::class, AuthChallenge::class, ContactSubmission::class, FeedbackSubmission::class, RevenueCatEvent::class, RateLimitBucket::class] as $class) {
            $class::get()->removeAll();
        }
    }

    /** @param array<string, mixed> $spec @return array<string, mixed> */
    private function createUser(array $spec): array
    {
        $member = Member::create();
        $member->Email = strtolower($spec['email']);
        $member->Password = $spec['password'];
        $member->setApiName($spec['name'] ?? '');
        $member->EmailConfirmedAt = gmdate('Y-m-d H:i:s');
        if (!empty($spec['preferences'])) {
            $member->setPreferences($spec['preferences']);
        }
        $member->write();
        $member->extend('onAfterApiSignUp');

        $result = ['id' => $member->UUID];
        if (($spec['tier'] ?? 'free') === 'pro') {
            // Same code path as the RevenueCat webhook.
            $member->applySubscriptionEvent('active', time());
        }
        if (!empty($spec['totp'])) {
            $secret = Totp::generateSecret();
            $codes = [];
            $hashes = [];
            for ($i = 0; $i < 10; $i++) {
                $raw = strtoupper(bin2hex(random_bytes(5)));
                $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
                $hashes[] = hash('sha256', $raw);
            }
            $member->TotpSecret = Secrets::encrypt($secret);
            $member->TotpEnabled = true;
            $member->RecoveryCodes = json_encode($hashes);
            $member->write();
            $result['totpSecret'] = $secret;
            $result['recoveryCodes'] = $codes;
        }
        return $result;
    }
}
