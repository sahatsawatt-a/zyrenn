<?php

namespace App\Models\Knowledge;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\HasRefId;
use App\Models\Connection;
use App\Models\Owner;
use App\Models\Project;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What an AI may answer from: some of its owner's notes, tables and Drive
 * files, read and cut into passages (KnowledgeChunk). Read again whenever
 * asked to, and every hour, keeping what has not changed.
 *
 * @property int $id
 * @property string $ref_id
 * @property string $title
 * @property list<array{kind: string, ref: string}>|null $sources
 * @property int|null $connection_id
 * @property string|null $embed_model
 * @property string $state
 * @property string|null $problem
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'sources', 'connection_id', 'embed_model'])]
class KnowledgeBase extends Model
{
    use BelongsToOwner, HasRefId;

    public const IDLE = 'idle';

    public const READING = 'reading';

    public const FAILED = 'failed';

    /** What it can read: notes in a folder, a table, files in a Drive folder. */
    public const KINDS = ['notes', 'table', 'drive'];

    public const MOST_SOURCES = 20;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sources' => 'array', 'read_at' => 'datetime'];
    }

    /**
     * An owner's knowledge bases.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOf(Builder $query, Owner $owner): void
    {
        $query->where($owner->ownerColumn(), $owner->getKey())
            ->when($owner instanceof Project, fn (Builder $only) => $only->whereNull('user_id'), fn (Builder $only) => $only->whereNull('project_id'));
    }

    /**
     * Get the connection that turns a passage into numbers.
     *
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    /**
     * Get its passages.
     *
     * @return HasMany<KnowledgeChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    /**
     * Whether its passages are found by meaning, and not only by their words.
     */
    public function embeds(): bool
    {
        return $this->connection_id !== null && filled($this->embed_model);
    }
}
