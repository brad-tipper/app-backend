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
use SilverStripe\Security\DefaultAdminService;
use SilverStripe\Security\Member;

/** Resets all user data and creates fixture users. Used by the dev-only task and endpoint. */
final class E2eSeeder
{
    /**
     * @param array<int, array<string, mixed>> $users
     * @return array<string, array<string, mixed>> keyed by email
     */
    public static function run(array $users): array
    {
        self::reset();
        $results = [];
        foreach ($users as $spec) {
            $results[strtolower($spec['email'])] = self::createUser($spec);
        }
        return $results;
    }

    private static function reset(): void
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

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private static function createUser(array $spec): array
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
