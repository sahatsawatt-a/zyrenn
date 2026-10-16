<?php

namespace App\Models\Workflow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One thing a step has given out before (Support\Workflow\Seen).
 *
 * @property int $id
 * @property int $workflow_id
 * @property string $node_id
 * @property string $hash
 * @property int|null $workflow_run_id
 * @property Carbon $seen_at
 */
class WorkflowSeen extends Model
{
    protected $table = 'workflow_seen';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['seen_at' => 'datetime'];
    }
}
