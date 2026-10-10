<?php

namespace App\Support\Workflow;

/**
 * Where a run of a workflow stands. Only the moves listed in next() are
 * allowed; nothing writes a status without asking canBecome() first.
 */
enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    // For a person, a delay, or something outside to answer
    case Waiting = 'waiting';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Where it may go from here.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Queued => [self::Running, self::Failed, self::Cancelled],
            self::Running => [self::Waiting, self::Succeeded, self::Failed, self::Cancelled],
            self::Waiting => [self::Running, self::Failed, self::Cancelled],
            self::Succeeded, self::Failed, self::Cancelled => [],
        };
    }

    public function canBecome(self $status): bool
    {
        return in_array($status, $this->next(), true);
    }

    /**
     * Whether it is over: nothing more will happen in it.
     */
    public function isFinal(): bool
    {
        return $this->next() === [];
    }
}
