<?php

namespace App\Models\Workflow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A line a run's Log step wrote.
 *
 * @property int $id
 * @property int $workflow_run_id
 * @property int|null $workflow_node_run_id
 * @property string $level
 * @property string $message
 * @property Carbon|null $created_at
 */
class WorkflowRunLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /** Its times are kept to the millisecond. */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
