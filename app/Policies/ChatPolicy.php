<?php

namespace App\Policies;

use App\Models\Chat\ChatRoom;
use App\Models\Project;
use App\Models\User;

/**
 * Chat rooms.
 *
 * Everyone in a room may read it and talk in it. One's own room is set up,
 * renamed and deleted by its owner, since its agent answers with their key;
 * a group, by whoever started it, while they are still in its project. A
 * group is joined by anyone in its project, and left by anyone in it. A
 * direct room is never renamed or deleted: it is the two of them. A project's
 * chat with a customer is read by everyone in the project; its owners and
 * editors answer in it and look after it, and only an owner deletes it.
 */
class ChatPolicy
{
    public function view(User $user, ChatRoom $room): bool
    {
        return $room->includes($user);
    }

    /**
     * Say something in the room.
     */
    public function post(User $user, ChatRoom $room): bool
    {
        return $room->kind === ChatRoom::CUSTOMER ? $room->answeredBy($user) : $room->includes($user);
    }

    public function update(User $user, ChatRoom $room): bool
    {
        return $this->manages($user, $room);
    }

    public function delete(User $user, ChatRoom $room): bool
    {
        return $room->kind === ChatRoom::CUSTOMER
            ? $room->project?->roleOf($user) === Project::OWNER
            : $this->manages($user, $room);
    }

    /**
     * Join a group of one's project.
     */
    public function join(User $user, ChatRoom $room): bool
    {
        return $room->inProject($user) && ! $room->members()->whereKey($user->id)->exists();
    }

    /**
     * Leave a group one is in.
     */
    public function leave(User $user, ChatRoom $room): bool
    {
        return $room->kind === ChatRoom::GROUP && $room->members()->whereKey($user->id)->exists();
    }

    private function manages(User $user, ChatRoom $room): bool
    {
        return match ($room->kind) {
            ChatRoom::PERSONAL => $room->user_id === $user->id,
            ChatRoom::GROUP => $room->created_by === $user->id && $room->inProject($user),
            ChatRoom::CUSTOMER => $room->answeredBy($user),
            default => false,
        };
    }
}
