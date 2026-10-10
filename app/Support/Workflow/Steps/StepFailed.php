<?php

namespace App\Support\Workflow\Steps;

use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

/**
 * A step that could not do its work, and why, in words for whoever looks at
 * the run. Retryable when trying again later could go differently -- a
 * service busy or down for a moment -- and then the step is tried again,
 * after $retryAfter seconds when the service said how long, up to
 * Engine::MAX_ATTEMPTS tries in all.
 */
class StepFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /**
     * A send that broke off. One that never connected (no such host, nothing
     * answering) never went out, and is tried again; one that broke off
     * after connecting may have gone out, and is not.
     */
    public static function unreached(ConnectionException $e, string $service): self
    {
        $neverLeft = preg_match('/cURL error (6|7|35)\b/', $e->getMessage()) === 1;

        return $neverLeft
            ? new self("Could not reach {$service}.", retryable: true)
            : new self("{$service} did not answer in time, so it may have gone out; it is not sent again unasked.");
    }
}
