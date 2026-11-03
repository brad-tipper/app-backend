<?php

namespace BradTipper\AppBackend\Support;

use BradTipper\AppBackend\AppConfig;
use BradTipper\AppBackend\Model\Ticket;
use BradTipper\AppBackend\Model\TicketMessage;
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

    /**
     * Subscription lifecycle, driven by RevenueCat events.
     *
     * @param array<string, mixed> $event the RevenueCat webhook event
     * @return bool whether an email was queued for this event
     */
    public static function subscription(Member $member, array $event): bool
    {
        $name = (string) AppConfig::get('name');
        $type = (string) ($event['type'] ?? '');
        $manage = ['label' => 'Manage subscription', 'url' => self::webUrl((string) AppConfig::get('subscription_path'))];
        $until = isset($event['expiration_at_ms']) ? self::date(gmdate('c', intdiv((int) $event['expiration_at_ms'], 1000))) : null;

        switch ($type) {
            case 'INITIAL_PURCHASE':
                $trial = ($event['period_type'] ?? '') === 'TRIAL';
                $subject = $trial ? "Your {$name} free trial has started" : "Welcome to {$name} Pro";
                $paragraphs = $trial
                    ? ['Your free trial is active and everything in Pro is unlocked.'
                        . ($until ? " Your trial ends on {$until}; after that your subscription starts unless you cancel first." : '')]
                    : ['Thanks for subscribing. Everything in Pro is now unlocked.'];
                $content = ['heading' => $subject, 'paragraphs' => $paragraphs, 'button' => $manage];
                break;
            case 'UNCANCELLATION':
                $content = [
                    'heading' => 'Your subscription is back on',
                    'paragraphs' => ['Your subscription will renew as normal' . ($until ? " on {$until}" : '') . '. Nothing else to do.'],
                    'button' => $manage,
                ];
                $subject = 'Your subscription is back on';
                break;
            case 'CANCELLATION':
                if (($event['cancel_reason'] ?? '') === 'CUSTOMER_SUPPORT') {
                    $subject = 'Your purchase was refunded';
                    $content = [
                        'heading' => $subject,
                        'paragraphs' => ['Your purchase has been refunded and Pro access has ended.'],
                        'footnote' => 'The refund can take a few days to reach your account.',
                    ];
                    break;
                }
                $subject = 'Your subscription was cancelled';
                $content = [
                    'heading' => $subject,
                    'paragraphs' => [
                        'Your subscription will not renew.'
                        . ($until ? " You keep Pro until {$until}." : ' You keep Pro until the end of the current period.'),
                        'You can resubscribe at any time from the subscription screen.',
                    ],
                    'button' => $manage,
                ];
                break;
            case 'BILLING_ISSUE':
                $subject = 'We could not take your payment';
                $content = [
                    'heading' => $subject,
                    'paragraphs' => [
                        'The store could not renew your subscription. Please update your payment method so you do not lose Pro.',
                        'The store will retry for a few days before the subscription ends.',
                    ],
                    'button' => $manage,
                ];
                break;
            case 'EXPIRATION':
                $subject = 'Your subscription has ended';
                $content = [
                    'heading' => $subject,
                    'paragraphs' => [
                        "Pro access has ended and your account is back on the free plan. Your data is safe and still yours.",
                        'Resubscribe whenever you like to get everything back.',
                    ],
                    'button' => ['label' => 'Resubscribe', 'url' => self::webUrl((string) AppConfig::get('subscription_path'))],
                ];
                break;
            default:
                return false;
        }
        self::to($member, $subject, $content);
        return true;
    }

    /** The reporter is told their ticket arrived, and the team is told there is something to read. */
    public static function ticketReceived(Member $member, Ticket $ticket): void
    {
        $label = strtolower($ticket->getTypeLabel());
        self::to($member, "We got your {$label} (#{$ticket->ID})", [
            'heading' => "We got your {$label}",
            'paragraphs' => [
                "#{$ticket->ID}: {$ticket->Title}",
                'We read everything. You can follow progress and add notes any time in the app; we will email you when there is a reply or the status changes.',
            ],
            'button' => ['label' => 'View your ticket', 'url' => self::ticketUrl($ticket)],
        ]);
        self::toSupport($member, $ticket, "New {$label} (#{$ticket->ID}): {$ticket->Title}", $ticket->Description);
    }

    /** The user added a note to an existing ticket. */
    public static function ticketUserNote(Member $member, Ticket $ticket, string $body): void
    {
        self::toSupport($member, $ticket, "Reply on #{$ticket->ID}: {$ticket->Title}", $body);
    }

    /** The team replied (a note that is not internal). */
    public static function ticketReply(Member $member, Ticket $ticket, TicketMessage $message): void
    {
        self::to($member, "Reply to your {$ticket->getTypeLabel()} (#{$ticket->ID})", [
            'heading' => "A reply to #{$ticket->ID}",
            'paragraphs' => array_merge([$ticket->Title], self::paragraphs((string) $message->Body)),
            'footnote' => 'Open the ticket in the app to reply.',
            'button' => ['label' => 'View and reply', 'url' => self::ticketUrl($ticket)],
        ]);
    }

    /** The team moved the ticket along. Moving it back to open says nothing. */
    public static function ticketStatus(Member $member, Ticket $ticket): void
    {
        $says = [
            'in_progress' => 'We are working on it.',
            'planned' => 'We have planned it, and will email you when it ships.',
            'resolved' => 'We have marked it resolved. If it is not, reply in the app and it will reopen.',
            'closed' => 'We have closed this ticket.',
        ];
        if (!isset($says[$ticket->Status])) {
            return;
        }
        self::to($member, "#{$ticket->ID} is now {$ticket->getStatusLabel()}", [
            'heading' => "{$ticket->getTypeLabel()} #{$ticket->ID}: {$ticket->getStatusLabel()}",
            'paragraphs' => [$ticket->Title, $says[$ticket->Status]],
            'button' => ['label' => 'View your ticket', 'url' => self::ticketUrl($ticket)],
        ]);
    }

    private static function toSupport(Member $member, Ticket $ticket, string $subject, string $body): void
    {
        $support = EmailTemplate::supportEmail();
        if ($support === '' || Mailer::isReservedTestAddress((string) $member->Email)) {
            return;
        }
        try {
            Mailer::notify($support, $subject, [
                'heading' => $subject,
                'paragraphs' => array_merge(["From {$member->Email} (user {$member->ID}), status {$ticket->getStatusLabel()}."], self::paragraphs($body)),
                'button' => ['label' => 'Open in admin', 'url' => rtrim((string) AppConfig::get('public_url'), '/') . "/admin/tickets/BradTipper-AppBackend-Model-Ticket/EditForm/field/BradTipper-AppBackend-Model-Ticket/item/{$ticket->ID}/edit"],
            ]);
        } catch (\Throwable $e) {
            // Never fail the caller over a notification.
        }
    }

    /** @return string[] */
    private static function paragraphs(string $text): array
    {
        $parts = preg_split('/\R{2,}/', trim($text)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn ($p) => $p !== ''));
    }

    private static function ticketUrl(Ticket $ticket): string
    {
        return self::webUrl(rtrim((string) AppConfig::get('tickets_path'), '/') . '/' . $ticket->UUID);
    }

    /** @param array<string, mixed> $content */
    private static function to(Member $member, string $subject, array $content): void
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

    private static function webUrl(string $path): string
    {
        return rtrim((string) AppConfig::get('web_url'), '/') . $path;
    }

    private static function date(string $iso): string
    {
        $time = strtotime($iso);
        return $time ? gmdate('j F Y', $time) : $iso;
    }
}
