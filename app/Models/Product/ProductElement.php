<?php

namespace App\Models\Product;

use App\Models\Concerns\HasRefId;
use App\Models\Concerns\TellsLists;
use App\Models\Project;
use App\Models\Ticket\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A part of a project's product: a component (an area of it) or a
 * capability (something it can do), under another or at the top, to any
 * depth. Its code never changes, nor is it given again. What it uses or
 * depends on is kept apart from where it sits (ProductLink).
 *
 * @property int $id
 * @property string $ref_id
 * @property int $project_id
 * @property int|null $parent_id
 * @property string $kind
 * @property string $code
 * @property string $name
 * @property string|null $summary
 * @property string $status
 * @property bool $shared
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'summary', 'status', 'shared', 'parent_id', 'position'])]
class ProductElement extends Model
{
    use HasRefId, TellsLists;

    public const COMPONENT = 'component';

    public const CAPABILITY = 'capability';

    /** How a capability stands, in the order it usually goes. */
    public const STATUSES = ['planned', 'building', 'partial', 'verifying', 'live', 'issues', 'blocked', 'retired'];

    /** What one part may be to another, beside where it sits. */
    public const LINKS = ['uses', 'depends_on', 'extends', 'replaces', 'related'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['shared' => 'boolean'];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('id');
    }

    /**
     * What it uses or depends on.
     *
     * @return HasMany<ProductLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(ProductLink::class, 'from_id');
    }

    /**
     * What uses or depends on it.
     *
     * @return HasMany<ProductLink, $this>
     */
    public function linkedFrom(): HasMany
    {
        return $this->hasMany(ProductLink::class, 'to_id');
    }

    /**
     * @return HasMany<ProductElementEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ProductElementEvent::class)->orderByDesc('id');
    }

    /**
     * @return BelongsToMany<Requirement, $this>
     */
    public function requirements(): BelongsToMany
    {
        return $this->belongsToMany(Requirement::class, 'requirement_element');
    }

    /**
     * The work done on it: the project's tickets about it.
     *
     * @return BelongsToMany<Ticket, $this>
     */
    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, 'element_ticket');
    }

    /**
     * Keep in its history that something about it changed.
     */
    public function note(string $what, ?User $by, ?string $from = null, ?string $to = null): void
    {
        $this->events()->create([
            'user_id' => $by?->id,
            'what' => $what,
            'from' => $from === null ? null : mb_strimwidth($from, 0, 255, '…'),
            'to' => $to === null ? null : mb_strimwidth($to, 0, 255, '…'),
            'created_at' => now(),
        ]);
    }
}
