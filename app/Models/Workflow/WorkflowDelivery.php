<?php

namespace App\Models\Workflow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One send to the outside by a step -- a message, a request -- kept apart
 * from the step's own status, since a send can go out and its worker die
 * before the step is marked done. "sending" before the call, "sent" or
 * "failed" after; "unknown" when nobody lived to say.
 *
 * @property int $id
 * @property int $workflow_node_run_id
 * @property string $service
 * @property string|null $target
 * @property string|null $idempotency_key
 * @property string $status
 * @property array<string, mixed>|null $response
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WorkflowDelivery extends Model
{
    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['response' => 'array'];
    }

    /**
     * @return BelongsTo<WorkflowNodeRun, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowNodeRun::class, 'workflow_node_run_id');
    }
}
