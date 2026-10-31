<?php

namespace BradTipper\AppBackend\Auth;

use BradTipper\AppBackend\Api\Uuid;
use BradTipper\AppBackend\Support\Secrets;
use BradTipper\AppBackend\Sync\SyncedExtension;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;

/**
 * The "profile" half of an app user: public UUID, preferences, subscription
 * state (written only by the RevenueCat webhook), 2FA and deletion scheduling.
 *
 * @property Member|ApiMemberExtension $owner
 * @property string $UUID
 * @property string $Timezone
 * @property string $Country
 * @property string $Units
 * @property string $DisplayDateFormat
 * @property string $PreferencesJson
 * @property string $SubscriptionTier
 * @property string $SubscriptionStatus
 * @property string $SubscriptionEventAt
 * @property string $TotpSecret
 * @property string $TotpPendingSecret
 * @property bool $TotpEnabled
 * @property string $RecoveryCodes
 * @property string $EmailConfirmedAt
 * @property string $DeletionRequestedAt
 * @property string $ScheduledForDeletionAt
 */
class ApiMemberExtension extends Extension
{
    private static array $db = [
        'UUID' => 'Varchar(36)',
        'Timezone' => 'Varchar(64)',
        'Country' => 'Varchar(2)',
        'Units' => "Enum('metric,imperial','metric')",
        'DisplayDateFormat' => 'Varchar(16)',
        'PreferencesJson' => 'Text',
        'SubscriptionTier' => "Enum('free,pro','free')",
        'SubscriptionStatus' => "Enum('unsubscribed,active','unsubscribed')",
        'SubscriptionEventAt' => 'Datetime',
        'TotpSecret' => 'Text',
        'TotpPendingSecret' => 'Text',
        'TotpEnabled' => 'Boolean',
        'RecoveryCodes' => 'Text',
        'EmailConfirmedAt' => 'Datetime',
        'DeletionRequestedAt' => 'Datetime',
        'ScheduledForDeletionAt' => 'Datetime',
    ];

    private static array $defaults = [
        'Timezone' => 'Pacific/Auckland',
        'DisplayDateFormat' => 'en-NZ',
        'Units' => 'metric',
    ];

    private static array $indexes = [
        'UUID' => true,
    ];

    public function onBeforeWrite(): void
    {
        if (!$this->owner->UUID) {
            $this->owner->UUID = Uuid::v4();
        }
        if ($this->owner->Email) {
            $this->owner->Email = strtolower(trim($this->owner->Email));
        }
    }

    public function onBeforeDelete(): void
    {
        $id = $this->owner->ID;
        ApiToken::get()->filter('MemberID', $id)->removeAll();
        AuthChallenge::get()->filter('MemberID', $id)->removeAll();

        // Cascade to everything the user owns (the old ON DELETE CASCADE).
        foreach (ClassInfo::subclassesFor(DataObject::class, false) as $class) {
            if (DataObject::singleton($class)->hasExtension(SyncedExtension::class)) {
                $class::get()->filter('OwnerID', $id)->removeAll();
            }
        }
    }

    public static function findByUuid(string $uuid): ?Member
    {
        return Member::get()->filter('UUID', strtolower($uuid))->first();
    }

    public static function findByEmail(string $email): ?Member
    {
        return Member::get()->filter('Email', strtolower(trim($email)))->first();
    }

    public function isPro(): bool
    {
        return $this->owner->SubscriptionStatus === 'active';
    }

    /** Display name as the app shows it (first + last). */
    public function apiName(): string
    {
        return trim(trim((string) $this->owner->FirstName) . ' ' . trim((string) $this->owner->Surname));
    }

    /** Splits "First Last Names" into the Member's two name fields. */
    public function setApiName(string $name): void
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [''];
        $this->owner->FirstName = $parts[0] ?? '';
        $this->owner->Surname = $parts[1] ?? '';
    }

    /** @return array<string, mixed> */
    public function preferences(): array
    {
        $decoded = json_decode((string) $this->owner->PreferencesJson ?: '{}', true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $preferences */
    public function setPreferences(array $preferences): void
    {
        $this->owner->PreferencesJson = json_encode($preferences, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Applies a subscription state change unless a newer event has already been
     * applied. The only code path allowed to change subscription fields.
     */
    public function applySubscriptionEvent(string $status, int $eventTimestamp): bool
    {
        if (!in_array($status, ['active', 'unsubscribed'], true)) {
            throw new \InvalidArgumentException('Invalid subscription status.');
        }
        $current = $this->owner->SubscriptionEventAt;
        if ($current && strtotime($current . ' UTC') >= $eventTimestamp) {
            return false;
        }
        $this->owner->SubscriptionStatus = $status;
        $this->owner->SubscriptionTier = $status === 'active' ? 'pro' : 'free';
        $this->owner->SubscriptionEventAt = gmdate('Y-m-d H:i:s', $eventTimestamp);
        $this->owner->write();
        return true;
    }

    public function totpSecretPlain(): ?string
    {
        return $this->owner->TotpSecret ? Secrets::decrypt($this->owner->TotpSecret) : null;
    }

    /** The public representation of a user, shared by every endpoint. @return array<string, mixed> */
    public function toApiUser(): array
    {
        $member = $this->owner;
        $name = $this->apiName();
        $prefs = $this->preferences();
        $user = [
            'id' => $member->UUID,
            'email' => $member->Email,
            'name' => $name,
            'firstName' => trim((string) $member->FirstName),
            'lastName' => trim((string) $member->Surname),
            'country' => $member->Country ?: null,
            'timezone' => $member->Timezone ?: 'Pacific/Auckland',
            'units' => $member->Units ?: 'metric',
            'dateFormat' => $member->DisplayDateFormat ?: 'en-NZ',
            'preferences' => $prefs === [] ? new \stdClass() : $prefs,
            'subscriptionTier' => $member->SubscriptionTier === 'pro' ? 'pro' : 'free',
            'subscriptionStatus' => $member->SubscriptionStatus === 'active' ? 'active' : 'unsubscribed',
            'subscriptionEventAt' => self::iso($member->SubscriptionEventAt),
            'mfaEnabled' => (bool) $member->TotpEnabled,
            'emailConfirmed' => (bool) $member->EmailConfirmedAt,
            'deletionScheduledFor' => self::iso($member->ScheduledForDeletionAt),
            'createdAt' => self::iso($member->Created),
        ];
        $member->extend('updateApiUser', $user);
        return $user;
    }

    /** Converts a stored UTC datetime string to ISO-8601, or null. */
    public static function iso(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        $time = strtotime($value . ' UTC');
        return $time ? gmdate(DATE_ATOM, $time) : null;
    }

    public static function now(): string
    {
        return DBDatetime::now()->getValue();
    }
}
