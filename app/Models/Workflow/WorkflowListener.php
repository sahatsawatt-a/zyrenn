<?php

namespace App\Models\Workflow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A published, switched-on workflow's trigger, waiting for its moment: a
 * message in a room, a time, a webhook, a bot's message. Rebuilt from the
 * published version whenever the workflow is published or switched on or
 * off (App\Support\Workflow\Listeners).
 *
 * @property int $id
 * @property int $workflow_id
 * @property int $workflow_version_id
 * @property string $node_id
 * @property string $kind
 * @property string $key
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 */
class WorkflowListener extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return BelongsTo<WorkflowVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
    }
}
