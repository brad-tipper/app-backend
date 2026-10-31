<?php

namespace BradTipper\AppBackend\Support;

use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Environment;

final class Mailer
{
    /** Sends a plain transactional email through Silverstripe's configured mailer. */
    public static function send(string $to, string $subject, string $text): void
    {
        $from = (string) (Environment::getEnv('APP_MAIL_FROM') ?: Email::config()->get('admin_email') ?: 'no-reply@localhost');
        $email = Email::create($from, $to, $subject);
        $email->text($text);
        $email->send();
    }
}
