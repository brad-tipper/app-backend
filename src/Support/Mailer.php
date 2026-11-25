<?php

namespace BradTipper\RestfulFramework\Support;

use BradTipper\RestfulFramework\AppConfig;
use BradTipper\RestfulFramework\Jobs\SendEmailJob;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Environment;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

final class Mailer
{
    /**
     * Queues a plain transactional email. It is delivered by a queued job straight
     * away, so a mail server hiccup is retried and never fails the API request.
     */
    public static function send(string $to, string $subject, string $text): void
    {
        self::queue(new SendEmailJob($to, $subject, $text));
    }

    /**
     * Queues an email in the app-branded layout (see EmailTemplate for `$content`).
     *
     * @param array<string, mixed> $content
     */
    public static function notify(string $to, string $subject, array $content): void
    {
        self::queue(new SendEmailJob($to, $subject, null, $content));
    }

    /**
     * Stores the job and runs it now. Silverstripe only auto-runs immediate jobs in CLI, so a web
     * request runs it inline; a failure leaves a Broken job behind to inspect or restart.
     */
    private static function queue(SendEmailJob $job): void
    {
        $service = QueuedJobService::singleton();
        $id = (int) $service->queueJob($job, null, 0, QueuedJob::QUEUED);
        try {
            $service->runJob($id);
        } catch (\Throwable $e) {
            error_log(sprintf('[mail] %s failed: %s', $job->getTitle(), $e->getMessage()));
        }
    }

    /** The job body: hands the message to Silverstripe's configured mailer. */
    public static function deliver(string $to, string $subject, string $text): void
    {
        if (self::isReservedTestAddress($to)) {
            return;
        }
        $email = self::message($to, $subject);
        $email->text($text);
        $email->send();
    }

    /** @param array<string, mixed> $content */
    public static function deliverBranded(string $to, string $subject, array $content): void
    {
        if (self::isReservedTestAddress($to)) {
            return;
        }
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

    /**
     * Addresses on the reserved test domains (RFC 2606) can never be delivered. Outside local
     * development, where Mailpit catches everything, skip them instead of bouncing off the real
     * mail provider, which is what automated sign-ups against a preview would otherwise do.
     */
    public static function isReservedTestAddress(string $to): bool
    {
        if (Environment::getEnv('SS_ENVIRONMENT_TYPE') === 'dev') {
            return false;
        }
        return (bool) preg_match('/@(?:[^@]+\.)?(?:example\.(?:com|net|org|test)|test|invalid|localhost)$/i', $to);
    }

    private static function message(string $to, string $subject): Email
    {
        $address = (string) AppConfig::get('mail_from_address');
        $name = (string) AppConfig::get('mail_from_name');
        return Email::create($name !== '' ? [$address => $name] : $address, $to, $subject);
    }
}
