<?php

namespace App\Models\Workflow;

use App\Support\Workflow\NodeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step of one run: where it stands, what it was given and gave out, and
 * what went wrong if something did. Only App\Support\Workflow\Engine moves it
 * from one status to the next.
 *
 * @property int $id
 * @property int $workflow_run_id
 * @property string $node_id
 * @property string $node_key
 * @property string $node_type
 * @property int $node_version
 * @property NodeStatus $status
 * @property int $attempt
 * @property string|null $handle
 * @property string|null $waiting_for
 * @property Carbon|null $resume_at
 * @property array<string, mixed>|null $wait
 * @property array<string, mixed>|null $input
 * @property mixed $output
 * @property array{message: string, retryable?: bool}|null $error
 * @property Carbon|null $lease_until
 * @property Carbon|null $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WorkflowNodeRun extends Model
{
    protected $guarded = ['id'];

    /** Its times are kept to the millisecond. */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => NodeStatus::class,
            'input' => 'array',
            'output' => 'json',
            'error' => 'array',
            'lease_until' => 'datetime',
            'queued_at' => 'datetime',
            'resume_at' => 'datetime',
            'wait' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WorkflowRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }
}
