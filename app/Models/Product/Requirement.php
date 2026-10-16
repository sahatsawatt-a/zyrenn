<?php

namespace App\Models\Product;

use App\Models\Concerns\HasRefId;
use App\Models\Concerns\TellsLists;
use App\Models\Project;
use App\Models\Ticket\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * What the product must do, because of a request: the need it serves, how
 * well it is understood, what is still to be asked or assumed, and the
 * criteria it is checked against -- verified only once every one is met,
 * never just because the work is done. A change to it is a requirement of
 * its own that names it, so what it was is kept.
 *
 * @property int $id
 * @property string $ref_id
 * @property int $project_id
 * @property string $code
 * @property int|null $product_request_id
 * @property string|null $need
 * @property string $title
 * @property string|null $body
 * @property string $kind
 * @property string $status
 * @property string $understanding
 * @property string $priority
 * @property int|null $owner_id
 * @property list<string>|null $questions
 * @property list<string>|null $assumptions
 * @property list<array{text: string, met: bool}>|null $criteria
 * @property int|null $changes_id
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Requirement extends Model
{
    use HasRefId, TellsLists;

    public const KINDS = ['new', 'additional', 'change', 'constraint', 'quality'];

    public const STATUSES = ['draft', 'clarifying', 'ready', 'building', 'verifying', 'verified', 'released', 'dropped'];

    public const UNDERSTANDING = ['unknown', 'partial', 'confirmed'];

    public const PRIORITIES = ['low', 'normal', 'high'];

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['questions' => 'array', 'assumptions' => 'array', 'criteria' => 'array'];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<ProductRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ProductRequest::class, 'product_request_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function changes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'changes_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The parts of the product it is about.
     *
     * @return BelongsToMany<ProductElement, $this>
     */
    public function elements(): BelongsToMany
    {
        return $this->belongsToMany(ProductElement::class, 'requirement_element');
    }

    /**
     * The work done for it.
     *
     * @return BelongsToMany<Ticket, $this>
     */
    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, 'requirement_ticket');
    }

    /**
     * Whether every criterion it is checked against is met -- and it has some.
     */
    public function met(): bool
    {
        $criteria = $this->criteria ?? [];

        return $criteria !== [] && collect($criteria)->every(fn (array $one) => $one['met']);
    }
}
