<?php

namespace App\Policies;

use App\Models\Chat\AiConnection;
use App\Models\Chat\ChatRoom;
use App\Models\User;

/**
 * Chat rooms and the connections they answer through.
 *
 * Everyone in a room may read it and talk in it. One's own room is set up,
 * renamed and deleted by its owner, since its agent answers with their key;
 * a group, by whoever started it, while they are still in its project. A
 * group is joined by anyone in its project, and left by anyone in it. A
 * direct room is never renamed or deleted: it is the two of them. A
 * connection holds a personal key, so it is its owner's alone.
 */
class ChatPolicy
{
    public function view(User $user, ChatRoom|AiConnection $thing): bool
    {
        return $thing instanceof ChatRoom ? $thing->includes($user) : $thing->user_id === $user->id;
    }

    /**
     * Say something in the room.
     */
    public function post(User $user, ChatRoom $room): bool
    {
        return $room->includes($user);
    }

    public function update(User $user, ChatRoom|AiConnection $thing): bool
    {
        return $this->manages($user, $thing);
    }

    public function delete(User $user, ChatRoom|AiConnection $thing): bool
    {
        return $this->manages($user, $thing);
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

    private function manages(User $user, ChatRoom|AiConnection $thing): bool
    {
        if ($thing instanceof AiConnection) {
            return $thing->user_id === $user->id;
        }

        return match ($thing->kind) {
            ChatRoom::PERSONAL => $thing->user_id === $user->id,
            ChatRoom::GROUP => $thing->created_by === $user->id && $thing->inProject($user),
            default => false,
        };
    }
}
