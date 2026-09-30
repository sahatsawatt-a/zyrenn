<?php

namespace App\Models;

use App\Models\Concerns\HasRefId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Someone's place in a project, and their role there.
 *
 * @property int $id
 * @property string $ref_id
 * @property int $project_id
 * @property int $user_id
 * @property string $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Membership extends Pivot
{
    use HasRefId;

    protected $table = 'project_user';

    public $incrementing = true;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
