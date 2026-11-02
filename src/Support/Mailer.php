<?php

namespace BradTipper\AppBackend\Support;

use BradTipper\AppBackend\AppConfig;
use BradTipper\AppBackend\Jobs\Queue;
use SilverStripe\Control\Email\Email;

final class Mailer
{
    /**
     * Queues a plain transactional email. It is delivered by a queued job straight
     * away, so a mail server hiccup is retried and never fails the API request.
     */
    public static function send(string $to, string $subject, string $text): void
    {
        Queue::dispatch(self::class, 'deliver', [$to, $subject, $text], Queue::NOW, 0, "Email: {$subject}");
    }

    /**
     * Queues an email in the app-branded layout (see EmailTemplate for `$content`).
     *
     * @param array<string, mixed> $content
     */
    public static function notify(string $to, string $subject, array $content): void
    {
        Queue::dispatch(self::class, 'deliverBranded', [$to, $subject, $content], Queue::NOW, 0, "Email: {$subject}");
    }

    /** The job body: hands the message to Silverstripe's configured mailer. */
    public static function deliver(string $to, string $subject, string $text): void
    {
        $email = self::message($to, $subject);
        $email->text($text);
        $email->send();
    }

    /** @param array<string, mixed> $content */
    public static function deliverBranded(string $to, string $subject, array $content): void
    {
        $rendered = EmailTemplate::render($content);
        $email = self::message($to, $subject);
        $email->html($rendered['html']);
        $email->text($rendered['text']);
        $support = EmailTemplate::supportEmail();
        if ($support !== '') {
            $email->setReplyTo($support);
        }
        $email->send();
    }

    private static function message(string $to, string $subject): Email
    {
        $address = (string) AppConfig::get('mail_from_address');
        $name = (string) AppConfig::get('mail_from_name');
        return Email::create($name !== '' ? [$address => $name] : $address, $to, $subject);
    }
}
