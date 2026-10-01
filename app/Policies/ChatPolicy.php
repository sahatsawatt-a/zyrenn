<?php

namespace App\Policies;

use App\Models\Chat\AiConnection;
use App\Models\Chat\ChatRoom;
use App\Models\User;

/**
 * Chat rooms and the connections they answer through: the user's own, theirs
 * alone. Not ContentPolicy, which is about things a project can share -- a
 * connection holds a personal key and so can't be.
 */
class ChatPolicy
{
    public function view(User $user, ChatRoom|AiConnection $thing): bool
    {
        return $thing->user_id === $user->id;
    }

    public function update(User $user, ChatRoom|AiConnection $thing): bool
    {
        return $thing->user_id === $user->id;
    }

    public function delete(User $user, ChatRoom|AiConnection $thing): bool
    {
        return $thing->user_id === $user->id;
    }
}
