<?php

namespace BradTipper\AppBackend\Account;

use BradTipper\AppBackend\AppConfig;
use BradTipper\AppBackend\Api\ApiController;
use BradTipper\AppBackend\Api\ApiException;
use BradTipper\AppBackend\Api\Input;
use BradTipper\AppBackend\Auth\ApiMemberExtension;
use BradTipper\AppBackend\Support\Notifications;
use BradTipper\AppBackend\Model\RevenueCatEvent;
use BradTipper\AppBackend\Model\Ticket;
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
            'tickets' => array_map(static fn (Ticket $t) => $t->toApiDetail(), Ticket::get()->filter('OwnerID', $member->ID)->toArray()),
            'revenuecatWebhookEvents' => array_map(static fn (RevenueCatEvent $e) => [
                'id' => $e->EventId,
                'type' => $e->Type,
                'eventAt' => ApiMemberExtension::iso($e->EventAt),
                'environment' => $e->Environment,
                'productId' => $e->ProductId ?: null,
                'outcome' => $e->Outcome,
            ], RevenueCatEvent::get()->filter('AppUserUuid', $member->UUID)->toArray()),
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

}
