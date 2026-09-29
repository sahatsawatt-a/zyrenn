<?php

namespace App\Policies;

use App\Models\Table\Table;
use App\Models\User;

class TablePolicy
{
    /**
     * Determine whether the user can view the table.
     */
    public function view(User $user, Table $table): bool
    {
        return $table->user_id === $user->id;
    }

    /**
     * Determine whether the user can update the table.
     */
    public function update(User $user, Table $table): bool
    {
        return $table->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the table.
     */
    public function delete(User $user, Table $table): bool
    {
        return $table->user_id === $user->id;
    }
}
