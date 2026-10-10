<?php

namespace App\Models\Workflow;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A workflow's draft as it was when it was frozen: published, to run on its
 * own, or a test, to be tried from the editor. A run names the version it
 * ran, so a version is never changed once written.
 *
 * @property int $id
 * @property int $workflow_id
 * @property int $number
 * @property string $kind
 * @property int $revision
 * @property int $schema_version
 * @property array<string, mixed> $definition
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
class WorkflowVersion extends Model
{
    public const PUBLISHED = 'published';

    public const TEST = 'test';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['definition' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A workflow version is never changed once written.'));
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
