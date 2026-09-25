<?php

namespace App\Policies;

use App\Models\DriveFile;
use App\Models\User;

class DriveFilePolicy
{
    /**
     * Determine whether the user can view the file. Drive is private: only the owner can.
     */
    public function view(User $user, DriveFile $file): bool
    {
        return $file->user_id === $user->id;
    }

    /**
     * Determine whether the user can update the file.
     */
    public function update(User $user, DriveFile $file): bool
    {
        return $file->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the file.
     */
    public function delete(User $user, DriveFile $file): bool
    {
        return $file->user_id === $user->id;
    }
}
