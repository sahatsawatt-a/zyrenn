<?php

namespace App\Policies;

use App\Models\Drive\DriveFolder;
use App\Models\User;

class DriveFolderPolicy
{
    /**
     * Determine whether the user can view the folder. Drive is private: only the owner can.
     */
    public function view(User $user, DriveFolder $folder): bool
    {
        return $folder->user_id === $user->id;
    }

    /**
     * Determine whether the user can update the folder.
     */
    public function update(User $user, DriveFolder $folder): bool
    {
        return $folder->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the folder.
     */
    public function delete(User $user, DriveFolder $folder): bool
    {
        return $folder->user_id === $user->id;
    }
}
