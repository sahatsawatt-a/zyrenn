<?php

namespace App\Support\Workflow\Steps;

use App\Support\Workflow\NodeTypes;
use Carbon\CarbonInterface;

/**
 * What a step came to: what it gave out and the way the run goes on -- or
 * that it is waiting, for a time or for a person, and what it needs kept
 * until it carries on. A waiting step is run again when it is time; it can
 * tell by StepContext::$waited.
 */
final class StepResult
{
    /**
     * @param  array<string, mixed>  $wait  what a waiting step keeps for when it carries on
     */
    public function __construct(
        public readonly mixed $output = null,
        public readonly string $handle = NodeTypes::OUT,
        public readonly ?string $waitingFor = null,
        public readonly ?CarbonInterface $resumeAt = null,
        public readonly array $wait = [],
    ) {}

    /**
     * Wait: until a time, for something to answer, or both -- whichever comes first.
     *
     * @param  array<string, mixed>  $keep
     */
    public static function wait(string $for, ?CarbonInterface $until = null, array $keep = []): self
    {
        return new self(waitingFor: $for, resumeAt: $until, wait: $keep);
    }

    public function waits(): bool
    {
        return $this->waitingFor !== null;
    }
}
