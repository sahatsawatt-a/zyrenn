<?php

namespace App\Policies;

use App\Models\Table\TableFolder;
use App\Models\User;

class TableFolderPolicy
{
    /**
     * Determine whether the user can view the table folder.
     */
    public function view(User $user, TableFolder $folder): bool
    {
        return $folder->user_id === $user->id;
    }

    /**
     * Determine whether the user can update the table folder.
     */
    public function update(User $user, TableFolder $folder): bool
    {
        return $folder->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the table folder.
     */
    public function delete(User $user, TableFolder $folder): bool
    {
        return $folder->user_id === $user->id;
    }
}
