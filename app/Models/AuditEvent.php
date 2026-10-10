<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing someone did that its owner may want to look back on: a workflow
 * published, switched on, run by hand; a connection added or changed.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $project_id
 * @property int|null $actor_id
 * @property string $action
 * @property string $subject_type
 * @property string $subject_ref
 * @property string|null $subject_name
 * @property array<string, mixed>|null $details
 * @property Carbon $created_at
 */
class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * As a page shows it.
     *
     * @return array<string, mixed>
     */
    public function shown(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'subject' => ['type' => $this->subject_type, 'ref' => $this->subject_ref, 'name' => $this->subject_name],
            'details' => $this->details ?? [],
            'by' => $this->actor_id === null ? null : ($this->actor->name ?? 'Deleted user'),
            'at' => $this->created_at->toIso8601String(),
        ];
    }
}
