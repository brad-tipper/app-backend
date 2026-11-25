<?php

namespace BradTipper\RestfulFramework\Support;

use BradTipper\RestfulFramework\AppConfig;
use SilverStripe\Security\Member;

/**
 * Every notification email the platform sends, in one place so the wording and the
 * set of triggers stay consistent across apps. Each method queues a branded email;
 * none of them throw, because a mail problem must never fail the request that
 * triggered it.
 */
final class Notifications
{
    public static function welcome(Member $member): void
    {
        $name = (string) AppConfig::get('name');
        self::to($member, "Welcome to {$name}", [
            'heading' => "Welcome to {$name}",
            'paragraphs' => [
                'Your account is ready. Thanks for signing up.',
                'If you need anything, just reply to this email.',
            ],
            'button' => ['label' => "Open {$name}", 'url' => self::webUrl('')],
        ]);
    }

    public static function passwordChanged(Member $member): void
    {
        self::to($member, 'Your password was changed', [
            'heading' => 'Your password was changed',
            'paragraphs' => [
                'The password on your account was just changed, and you have been signed out of your other devices.',
            ],
            'footnote' => 'If this was not you, reset your password straight away' . self::contactSuffix() . '.',
            'button' => ['label' => 'Reset password', 'url' => self::webUrl('/')],
        ]);
    }

    public static function mfaEnabled(Member $member): void
    {
        self::to($member, 'Two-factor authentication is on', [
            'heading' => 'Two-factor authentication is on',
            'paragraphs' => ['Your account now asks for a code from your authenticator app when you sign in.'],
            'footnote' => 'If you did not do this, change your password' . self::contactSuffix() . '.',
        ]);
    }

    public static function mfaDisabled(Member $member): void
    {
        self::to($member, 'Two-factor authentication is off', [
            'heading' => 'Two-factor authentication is off',
            'paragraphs' => ['Two-factor authentication was turned off on your account.'],
            'footnote' => 'If you did not do this, change your password' . self::contactSuffix() . '.',
        ]);
    }

    public static function deletionScheduled(Member $member, string $scheduledFor): void
    {
        $date = self::date($scheduledFor);
        self::to($member, 'Your account is scheduled for deletion', [
            'heading' => 'Your account is scheduled for deletion',
            'paragraphs' => [
                "We will permanently delete your account and its data on {$date}.",
                'Changed your mind? Sign in before then and cancel the deletion from your account settings.',
            ],
            'footnote' => 'An active subscription is not cancelled by deleting your account. Cancel it in the store you subscribed through.',
            'button' => ['label' => 'Cancel deletion', 'url' => self::webUrl('/')],
        ]);
    }

    public static function deletionCancelled(Member $member): void
    {
        self::to($member, 'Account deletion cancelled', [
            'heading' => 'Account deletion cancelled',
            'paragraphs' => ['Your account will not be deleted. Everything is just as you left it.'],
        ]);
    }

    /** Sent to the address captured before the member row is removed. */
    public static function accountDeleted(string $email): void
    {
        $name = (string) AppConfig::get('name');
        Mailer::notify($email, 'Your account has been deleted', [
            'heading' => 'Your account has been deleted',
            'paragraphs' => [
                "Your {$name} account and its data have been permanently deleted, as you asked.",
                'Thanks for trying us. You are always welcome back.',
            ],
        ]);
    }

    /** @param array<string, mixed> $content */
    public static function to(Member $member, string $subject, array $content): void
    {
        $email = trim((string) $member->Email);
        if ($email === '') {
            return;
        }
        try {
            Mailer::notify($email, $subject, $content);
        } catch (\Throwable $e) {
            // Never fail the caller over a notification.
        }
    }

    private static function contactSuffix(): string
    {
        $support = EmailTemplate::supportEmail();
        return $support !== '' ? " and contact {$support}" : '';
    }

    public static function webUrl(string $path): string
    {
        return rtrim((string) AppConfig::get('web_url'), '/') . $path;
    }

    public static function date(string $iso): string
    {
        $time = strtotime($iso);
        return $time ? gmdate('j F Y', $time) : $iso;
    }
}
