<?php

namespace App\Models\Agent;

use App\Models\Connection;
use App\Models\Knowledge\KnowledgeBase;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What an agent was at one time: its model, instructions and knowledge.
 *
 * @property int $id
 * @property int $agent_id
 * @property int $number
 * @property int|null $connection_id
 * @property string|null $model
 * @property string|null $instructions
 * @property int|null $knowledge_base_id
 * @property list<array{server: string, name: string}>|null $tools
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
class AgentVersion extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'tools' => 'array'];
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    /**
     * @return BelongsTo<KnowledgeBase, $this>
     */
    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
