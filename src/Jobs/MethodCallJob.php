<?php

namespace BradTipper\AppBackend\Jobs;

use SilverStripe\Core\Injector\Injector;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Runs `Class::method(...$args)` as a queued job. Static methods are called
 * directly; otherwise the class is resolved through Injector. Arguments must be
 * serialisable. Create these with Queue::dispatch(), not directly.
 *
 * @property string $target
 * @property string $method
 * @property array<int, mixed> $args
 * @property string $label
 * @property string $nonce
 */
class MethodCallJob extends AbstractQueuedJob
{
    /** @param array<int, mixed> $args */
    public function __construct(?string $target = null, ?string $method = null, array $args = [], ?string $label = null)
    {
        parent::__construct();
        if ($target !== null && $method !== null) {
            $this->target = $target;
            $this->method = $method;
            $this->args = $args;
            $this->label = $label ?? "{$target}::{$method}";
            // Identical calls queued twice must both run.
            $this->nonce = bin2hex(random_bytes(6));
        }
    }

    public function getTitle(): string
    {
        return (string) ($this->label ?: 'Method call');
    }

    public function getJobType(): string
    {
        return QueuedJob::QUEUED;
    }

    public function getSignature(): string
    {
        return md5(static::class . '|' . $this->target . '|' . $this->method . '|' . $this->nonce);
    }

    public function setup(): void
    {
        parent::setup();
        $this->totalSteps = 1;
    }

    public function process(): void
    {
        $target = (string) $this->target;
        $method = (string) $this->method;
        $args = (array) $this->args;

        if (method_exists($target, $method) && (new \ReflectionMethod($target, $method))->isStatic()) {
            $target::$method(...$args);
        } else {
            Injector::inst()->get($target)->$method(...$args);
        }
        $this->currentStep = 1;
        $this->isComplete = true;
    }
}
