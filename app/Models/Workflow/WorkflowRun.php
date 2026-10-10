<?php

namespace App\Models\Workflow;

use App\Models\Concerns\HasRefId;
use App\Models\User;
use App\Support\Workflow\RunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One run of a workflow: the frozen version it runs, how far it has got, and
 * how it ended. Its steps are WorkflowNodeRuns. Only App\Support\Workflow\Engine
 * moves it from one status to the next.
 *
 * @property int $id
 * @property string $ref_id
 * @property int $workflow_id
 * @property int $workflow_version_id
 * @property RunStatus $status
 * @property string|null $reason
 * @property string|null $error
 * @property string $trigger
 * @property string $trigger_node_id
 * @property mixed $trigger_data
 * @property int|null $started_by
 * @property int|null $origin_run_id
 * @property int $depth
 * @property int $executed_steps
 * @property int $max_executed_steps
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $alerted_at
 * @property int|null $caller_node_run_id
 * @property int|null $caller_index
 * @property string|null $run_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WorkflowRun extends Model
{
    use HasRefId;

    protected $guarded = ['id'];

    /** Its times are kept to the millisecond. */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'trigger_data' => 'json',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'alerted_at' => 'datetime',
        ];
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * Who the run acts as: whoever started it, or -- for a trigger that fired
     * by itself -- whoever published the version it runs, then whoever made
     * the workflow. What it does is shown as the workflow's, naming them.
     */
    public function actor(): ?User
    {
        return $this->starter
            ?? $this->version->creator
            ?? $this->workflow->creator;
    }

    /**
     * @return HasMany<WorkflowNodeRun, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowNodeRun::class);
    }

    /**
     * @return HasMany<WorkflowRunLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(WorkflowRunLog::class);
    }
}
