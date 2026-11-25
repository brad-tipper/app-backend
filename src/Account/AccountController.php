<?php

namespace BradTipper\RestfulFramework\Account;

use BradTipper\RestfulFramework\AppConfig;
use BradTipper\RestfulFramework\Api\ApiController;
use BradTipper\RestfulFramework\Api\ApiException;
use BradTipper\RestfulFramework\Api\Input;
use BradTipper\RestfulFramework\Auth\ApiMemberExtension;
use BradTipper\RestfulFramework\Support\Notifications;
use BradTipper\RestfulFramework\OpenApi\Spec;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;

/** Data export and the 30-day account deletion request. */
class AccountController extends ApiController
{
    use Configurable;

    private static int $deletion_grace_days = 30;

    protected function routes(): array
    {
        return [
            'GET export' => 'doExport',
            'GET deletion' => 'doDeletionStatus',
            'POST deletion' => 'doRequestDeletion',
            'DELETE deletion' => 'doCancelDeletion',
        ];
    }

    protected function doExport(HTTPRequest $request): array
    {
        $member = $this->requireMember($request);
        $this->limit("account-export:{$member->UUID}", 5, 3600);

        $data = [
            'exportedAt' => gmdate(DATE_ATOM),
            'dataController' => (string) AppConfig::get('name'),
            'user' => $member->toApiUser(),
        ];
        $member->extend('updateAccountExport', $data);
        return $data;
    }

    protected function doDeletionStatus(HTTPRequest $request): array
    {
        return $this->deletionPayload($this->requireMember($request));
    }

    protected function doRequestDeletion(HTTPRequest $request): array
    {
        $member = $this->requireMember($request);
        $in = Input::fromRequest($request);
        $confirmation = $in->string('confirmation', true, 16);
        $email = $in->email('email', false);
        $in->assertValid();

        if ($confirmation !== 'DELETE') {
            throw ApiException::badRequest('Type DELETE to confirm.', 'confirmation_required');
        }
        if ($member->Email && strtolower((string) $email) !== strtolower($member->Email)) {
            throw ApiException::badRequest('That email address does not match this account.', 'email_mismatch');
        }

        // Re-requesting keeps the original schedule rather than pushing it back.
        if (!$member->ScheduledForDeletionAt) {
            $days = (int) self::config()->get('deletion_grace_days');
            $member->DeletionRequestedAt = gmdate('Y-m-d H:i:s');
            $member->ScheduledForDeletionAt = gmdate('Y-m-d H:i:s', strtotime("+{$days} days"));
            $member->write();
            Notifications::deletionScheduled($member, (string) $member->ScheduledForDeletionAt . ' UTC');
        }
        return $this->deletionPayload($member);
    }

    protected function doCancelDeletion(HTTPRequest $request): array
    {
        $member = $this->requireMember($request);
        $wasScheduled = (bool) $member->ScheduledForDeletionAt;
        $member->DeletionRequestedAt = null;
        $member->ScheduledForDeletionAt = null;
        $member->write();
        if ($wasScheduled) {
            Notifications::deletionCancelled($member);
        }
        return $this->deletionPayload($member);
    }

    /** @return array<string, mixed> */
    private function deletionPayload(\SilverStripe\Security\Member $member): array
    {
        return [
            'requestedAt' => ApiMemberExtension::iso($member->DeletionRequestedAt),
            'scheduledFor' => ApiMemberExtension::iso($member->ScheduledForDeletionAt),
        ];
    }

    public static function describe(Spec $spec): void
    {
        $spec->schema('DeletionStatus', Spec::object([
            'requestedAt' => Spec::string('date-time', true),
            'scheduledFor' => Spec::string('date-time', true),
        ]));
        $spec->schema('DeletionRequest', Spec::object([
            'confirmation' => Spec::string(),
            'email' => Spec::string('email'),
        ], ['confirmation']));
        $spec->schema('AccountExport', Spec::map());

        $tag = ['Account'];
        $spec->operation('get', '/api/v1/account/export', 'exportAccount', [
            'tags' => $tag, 'summary' => 'Export all of the user\'s data', 'auth' => true,
            'responses' => [200 => Spec::ref('AccountExport')],
        ]);
        $spec->operation('get', '/api/v1/account/deletion', 'getAccountDeletion', [
            'tags' => $tag, 'summary' => 'Current deletion request, if any', 'auth' => true,
            'responses' => [200 => Spec::ref('DeletionStatus')],
        ]);
        $spec->operation('post', '/api/v1/account/deletion', 'requestAccountDeletion', [
            'tags' => $tag, 'summary' => 'Schedule the account for deletion', 'auth' => true,
            'body' => Spec::ref('DeletionRequest'),
            'responses' => [200 => Spec::ref('DeletionStatus')],
        ]);
        $spec->operation('delete', '/api/v1/account/deletion', 'cancelAccountDeletion', [
            'tags' => $tag, 'summary' => 'Cancel a scheduled deletion', 'auth' => true,
            'responses' => [200 => Spec::ref('DeletionStatus')],
        ]);
    }
}
