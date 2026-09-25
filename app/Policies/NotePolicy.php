<?php

namespace App\Policies;

use App\Models\Note;
use App\Models\User;

class NotePolicy
{
    /**
     * Determine whether the user can view the note.
     */
    public function view(User $user, Note $note): bool
    {
        return $note->user_id === $user->id;
    }

    /**
     * Determine whether the user can update the note.
     */
    public function update(User $user, Note $note): bool
    {
        return $note->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the note.
     */
    public function delete(User $user, Note $note): bool
    {
        return $note->user_id === $user->id;
    }
}
