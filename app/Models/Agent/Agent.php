<?php

namespace App\Models\Agent;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\HasRefId;
use App\Models\Connection;
use App\Models\Knowledge\KnowledgeBase;
use App\Models\Owner;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * An agent: a model, through one of its owner's AI connections, answering
 * by its instructions from what its knowledge base finds. What it is now is
 * its latest version; every one before is kept (AgentVersion), and any of
 * them can be made the latest again -- or named by a step, which then keeps
 * to it whatever changes after.
 *
 * @property int $id
 * @property string $ref_id
 * @property string $name
 * @property int|null $connection_id
 * @property string|null $model
 * @property string|null $instructions
 * @property int|null $knowledge_base_id
 * @property list<array{server: string, name: string}>|null $tools
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
class Agent extends Model
{
    use BelongsToOwner, HasRefId;

    /** What a version is made of: a change to any of these is a new one. */
    public const KEPT = ['connection_id', 'model', 'instructions', 'knowledge_base_id', 'tools'];

    public const MAX_INSTRUCTIONS = 20000;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tools' => 'array'];
    }

    /**
     * An owner's agents.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOf(Builder $query, Owner $owner): void
    {
        $query->where($owner->ownerColumn(), $owner->getKey())
            ->when($owner instanceof Project, fn (Builder $only) => $only->whereNull('user_id'), fn (Builder $only) => $only->whereNull('project_id'));
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
     * Every version of it, the latest first.
     *
     * @return HasMany<AgentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AgentVersion::class)->orderByDesc('number');
    }

    /**
     * Change what it is, kept as a new version -- when anything did change.
     *
     * @param  array{connection_id?: int|null, model?: string|null, instructions?: string|null, knowledge_base_id?: int|null, tools?: list<array{server: string, name: string}>|null}  $changes
     */
    public function change(array $changes, ?User $by, ?string $note = null): bool
    {
        $this->forceFill(array_intersect_key($changes, array_flip(self::KEPT)));

        if ($this->version > 0 && ! $this->isDirty(self::KEPT)) {
            return false;
        }

        DB::transaction(function () use ($by, $note) {
            $this->version++;
            $this->save();

            $this->versions()->create([
                'number' => $this->version,
                ...$this->only(self::KEPT),
                'note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 200),
                'created_by' => $by?->id,
                'created_at' => now(),
            ]);
        });

        return true;
    }

    /**
     * Make an earlier version what it is again: kept as a new version, so
     * the one gone back from is not lost either.
     */
    public function restore(AgentVersion $version, ?User $by): void
    {
        $this->change($version->only(self::KEPT), $by, "Back to version {$version->number}");
    }

    /**
     * One of its versions, by number; the latest for null.
     */
    public function at(?int $number): ?AgentVersion
    {
        return $this->versions()->where('number', $number ?? $this->version)->first();
    }
}
