<?php

namespace BradTipper\AppBackend\Jobs;

use BradTipper\AppBackend\Support\Mailer;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Delivers one transactional email. Queue it with Mailer::send() / Mailer::notify(): a mail server
 * problem then leaves a Broken job to inspect and restart instead of failing the API request.
 *
 * @property string $to
 * @property string $subject
 * @property string $text
 * @property array<string, mixed> $content
 * @property string $nonce
 */
class SendEmailJob extends AbstractQueuedJob
{
    /**
     * @param array<string, mixed>|null $content branded layout content (see EmailTemplate); null sends `$text` as plain text
     */
    public function __construct(?string $to = null, ?string $subject = null, ?string $text = null, ?array $content = null)
    {
        parent::__construct();
        if ($to !== null) {
            $this->to = $to;
            $this->subject = (string) $subject;
            $this->text = (string) $text;
            $this->content = $content;
            // Identical emails queued twice must both be sent, so each job gets its own signature.
            $this->nonce = bin2hex(random_bytes(8));
        }
    }

    public function getTitle(): string
    {
        return 'Email: ' . $this->subject;
    }

    public function getJobType(): string
    {
        return QueuedJob::QUEUED;
    }

    public function getSignature(): string
    {
        return md5(static::class . $this->nonce);
    }

    public function setup(): void
    {
        parent::setup();
        $this->totalSteps = 1;
    }

    public function process(): void
    {
        if (is_array($this->content)) {
            Mailer::deliverBranded($this->to, $this->subject, $this->content);
        } else {
            Mailer::deliver($this->to, $this->subject, $this->text);
        }
        $this->currentStep = 1;
        $this->isComplete = true;
    }
}
