<?php

namespace BradTipper\AppBackend\Auth;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * A single-use, expiring secret sent to a user: password reset, magic link,
 * email confirmation, or the half-finished sign-in of an MFA user.
 *
 * @property string $Type
 * @property string $TokenHash
 * @property int $ExpiresAt
 * @property int $UsedAt
 * @property int $Attempts
 * @property int $MemberID
 * @method Member Member()
 */
class AuthChallenge extends DataObject
{
    public const RESET = 'reset';
    public const MAGIC = 'magic';
    public const CONFIRM = 'confirm';
    public const MFA = 'mfa';

    private static string $table_name = 'AppAuthChallenge';

    private static array $db = [
        'Type' => 'Varchar(16)',
        'TokenHash' => 'Varchar(64)',
        'ExpiresAt' => 'Int',
        'UsedAt' => 'Int',
        'Attempts' => 'Int',
    ];

    private static array $has_one = [
        'Member' => Member::class,
    ];

    private static array $summary_fields = [
        'Created' => 'Sent',
        'Type' => 'Type',
        'ExpiresAtNice' => 'Expires',
        'UsedAtNice' => 'Used',
        'Attempts' => 'Attempts',
        'Member.Email' => 'User',
    ];

    private static array $searchable_fields = ['Type', 'Member.Email'];

    private static string $default_sort = 'Created DESC';

    private static array $indexes = [
        'TokenHash' => ['type' => 'unique', 'columns' => ['TokenHash']],
        'ExpiresAt' => true,
    ];

    public function canCreate($member = null, $context = [])
    {
        return false;
    }

    public function canEdit($member = null)
    {
        return false;
    }

    public function getExpiresAtNice(): string
    {
        return $this->ExpiresAt ? gmdate('Y-m-d H:i', (int) $this->ExpiresAt) . ' UTC' : '';
    }

    public function getUsedAtNice(): string
    {
        return $this->UsedAt ? gmdate('Y-m-d H:i', (int) $this->UsedAt) . ' UTC' : '';
    }

    /** Creates a challenge and returns the plaintext token (shown to the user once). */
    public static function issue(Member $member, string $type, int $ttlSeconds): string
    {
        // A fresh request supersedes older unused ones of the same type.
        self::get()->filter(['MemberID' => $member->ID, 'Type' => $type, 'UsedAt' => 0])->removeAll();
        self::get()->filter('ExpiresAt:LessThan', time() - 86400)->removeAll();

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        self::create([
            'Type' => $type,
            'TokenHash' => hash('sha256', $token),
            'ExpiresAt' => time() + $ttlSeconds,
            'MemberID' => $member->ID,
        ])->write();
        return $token;
    }

    /** Finds a live (unused, unexpired) challenge without consuming it. */
    public static function find(string $type, string $token): ?self
    {
        /** @var self|null $challenge */
        $challenge = self::get()->filter(['Type' => $type, 'TokenHash' => hash('sha256', $token), 'UsedAt' => 0])->first();
        if (!$challenge || $challenge->ExpiresAt < time() || !$challenge->Member()->exists()) {
            return null;
        }
        return $challenge;
    }

    /** Finds and consumes in one step. */
    public static function consume(string $type, string $token): ?self
    {
        $challenge = self::find($type, $token);
        $challenge?->markUsed();
        return $challenge;
    }

    public function markUsed(): void
    {
        $this->UsedAt = time();
        $this->write();
    }
}
