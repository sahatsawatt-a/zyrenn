<?php

namespace App\Support\Workflow;

/**
 * Where one step of a run stands. Only the moves listed in next() are
 * allowed; nothing writes a status without asking canBecome() first.
 */
enum NodeStatus: string
{
    // Waiting for the steps before it
    case Pending = 'pending';
    // Ready, and handed to a worker
    case Queued = 'queued';
    case Running = 'running';
    // For a person, a delay, or something outside to answer
    case Waiting = 'waiting';
    // Failed, and to be tried again later
    case RetryWait = 'retry_wait';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    // On a way an If did not take, or after steps that were all skipped
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    /**
     * Where it may go from here. A running step can go back to queued: its
     * worker died, and it is handed to another.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Queued, self::Skipped, self::Cancelled],
            self::Queued => [self::Running, self::Cancelled],
            self::Running => [self::Succeeded, self::Failed, self::Waiting, self::RetryWait, self::Queued, self::Cancelled],
            self::Waiting => [self::Queued, self::Failed, self::Cancelled],
            self::RetryWait => [self::Queued, self::Cancelled],
            self::Succeeded, self::Failed, self::Skipped, self::Cancelled => [],
        };
    }

    public function canBecome(self $status): bool
    {
        return in_array($status, $this->next(), true);
    }

    /**
     * Whether it is over for this run.
     */
    public function isFinal(): bool
    {
        return $this->next() === [];
    }

    /**
     * Whether the steps after it may count it as done: it gave its output, or it was skipped.
     */
    public function letsChildrenRun(): bool
    {
        return $this === self::Succeeded || $this === self::Skipped;
    }
}
