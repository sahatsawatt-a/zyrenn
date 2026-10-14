<?php

namespace App\Support\Workflow\Steps;

use App\Events\ChatRoomChanged;
use App\Models\Chat\ChatRoom;
use App\Models\Project;
use App\Models\User;
use App\Support\Live\Live;

/**
 * Change a customer's chat: where it stands, who has taken it, and its
 * tags -- what handing a chat to a person, or closing one that has gone
 * quiet, is made of. Only a project's chats with customers have these.
 */
final class ChatUpdate implements Step
{
    public function run(StepContext $step): StepResult
    {
        $room = ChatSend::room($step, $step->text('room'));

        if ($room->kind !== ChatRoom::CUSTOMER) {
            throw new StepFailed('Only a project\'s chat with a customer has a status, someone it is assigned to, and tags.');
        }

        $status = (string) $step->setting('status');
        $assign = (string) $step->setting('assign');
        $changes = [];

        if (in_array($status, ChatRoom::STATUSES, true)) {
            $changes['status'] = $status;
        }

        if ($assign === 'nobody') {
            $changes['assigned_to'] = null;
        }

        if ($assign === 'person') {
            $changes['assigned_to'] = self::person($room->project, trim($step->text('person')))->id;
        }

        $tags = collect($room->tags ?? [])
            ->merge(ChatRoom::tagsOf($step->text('tag')))
            ->diff(ChatRoom::tagsOf($step->text('untag')))
            ->values()
            ->all();

        if ($tags !== ($room->tags ?? [])) {
            $changes['tags'] = ChatRoom::tagsOf($tags) ?: null;
        }

        $room->forceFill($changes);

        if ($room->isDirty()) {
            $room->save();
            Live::tellEveryone(new ChatRoomChanged($room));
        }

        return new StepResult([
            'room' => $room->ref_id,
            'status' => $room->status,
            'assignee' => $room->assignee?->only(['id', 'name', 'email']),
            'tags' => $room->tags ?? [],
        ]);
    }

    /**
     * Someone who answers for the project, by their email or their id.
     *
     * @throws StepFailed when nobody in the project is that
     */
    private static function person(?Project $project, string $who): User
    {
        $person = $who === '' ? null : $project?->members()
            ->wherePivotIn('role', [Project::OWNER, Project::EDITOR])
            ->where(fn ($query) => ctype_digit($who) ? $query->where('users.id', (int) $who) : $query->where('users.email', mb_strtolower($who)))
            ->first();

        return $person ?? throw new StepFailed($who === ''
            ? 'There is nobody to assign it to.'
            : "\"{$who}\" is not an owner or an editor of the project: give their email.");
    }
}
