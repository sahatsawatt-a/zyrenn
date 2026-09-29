<?php

namespace App\Policies;

use App\Models\Board\Board;
use App\Models\User;

class BoardPolicy
{
    /**
     * Determine whether the user can view the board.
     */
    public function view(User $user, Board $board): bool
    {
        return $board->user_id === $user->id;
    }

    /**
     * Determine whether the user can update the board.
     */
    public function update(User $user, Board $board): bool
    {
        return $board->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the board.
     */
    public function delete(User $user, Board $board): bool
    {
        return $board->user_id === $user->id;
    }
}
