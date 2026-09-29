<?php

namespace App\Models\Concerns;

use App\Models\Owner;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A thing owned by a user (user_id) or by a project (project_id), never both,
 * and made by someone (created_by), who may since have gone.
 *
 * @property int|null $user_id
 * @property int|null $project_id
 * @property int|null $created_by
 */
trait BelongsToOwner
{
    protected static function bootBelongsToOwner(): void
    {
        // A user's own things are made by that user
        static::creating(function (self $model) {
            $model->created_by ??= $model->user_id;
        });
    }

    /**
     * The user it belongs to, when it is someone's own.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The project it belongs to, when it is shared.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Who made it.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whoever it belongs to.
     */
    public function owner(): Owner
    {
        return $this->user_id !== null ? $this->user : $this->project;
    }

    /**
     * Give it to an owner, as made by $by.
     */
    public function ownedBy(Owner $owner, User $by): static
    {
        $this->setAttribute($owner->ownerColumn(), $owner->getKey());
        $this->created_by = $by->id;

        return $this;
    }
}
