<?php

namespace App\Events;

use App\Models\Owner;
use App\Models\Project;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Something listed in a space changed -- made, renamed, moved, deleted -- by
 * someone else, a workflow, a job or MCP: told to whoever has a page of that
 * kind open in the space, so the page asks for its list again. Only the kind
 * goes out, never what changed, so nobody learns of what they can't see.
 *
 * Kinds: notes, boards, tables, trips, workflows, drive, tickets, product,
 * places, browser_suites (Listed::KINDS).
 */
class ListChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(public Owner $owner, public string $kind) {}

    /** A project's members, or the one whose own things these are. */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->owner instanceof Project
            ? "projects.{$this->owner->ref_id}"
            : "App.Models.User.{$this->owner->getKey()}");
    }

    public function broadcastAs(): string
    {
        return 'listed.changed';
    }

    /**
     * @return array{kind: string}
     */
    public function broadcastWith(): array
    {
        return ['kind' => $this->kind];
    }
}
