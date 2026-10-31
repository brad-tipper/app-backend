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

    /** The job body: hands the message to Silverstripe's configured mailer. */
    public static function deliver(string $to, string $subject, string $text): void
    {
        $address = (string) AppConfig::get('mail_from_address');
        $name = (string) AppConfig::get('mail_from_name');
        $email = Email::create($name !== '' ? [$address => $name] : $address, $to, $subject);
        $email->text($text);
        $email->send();
    }
}
