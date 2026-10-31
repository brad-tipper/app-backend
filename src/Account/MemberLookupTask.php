<?php

namespace BradTipper\AppBackend\Account;

use BradTipper\AppBackend\Auth\ApiMemberExtension;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * `sake tasks:member-id --email=a@b.test` prints the user id the app hands RevenueCat as
 * `app_user_id`. Used by the comp-access script to grant promotional entitlements.
 */
class MemberLookupTask extends BuildTask
{
    protected static string $commandName = 'member-id';

    protected string $title = 'Look up a user id by email';

    protected static string $description = 'Prints "email<TAB>id" for each --email given (comma separated).';

    public function getOptions(): array
    {
        return [new InputOption('email', null, InputOption::VALUE_REQUIRED, 'Email address(es), comma separated')];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $missing = 0;
        foreach (array_filter(array_map('trim', explode(',', (string) $input->getOption('email')))) as $email) {
            $member = ApiMemberExtension::findByEmail($email);
            if ($member) {
                $output->writeln("{$member->Email}\t{$member->UUID}");
            } else {
                $output->writeln("{$email}\tNOT FOUND");
                $missing++;
            }
        }
        return $missing > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
