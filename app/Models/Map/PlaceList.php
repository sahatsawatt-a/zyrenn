<?php

namespace App\Models\Map;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\HasRefId;
use Database\Factories\Map\PlaceListFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A list of saved places -- "Want to go", "Work" -- in a colour of its own.
 *
 * @property int $id
 * @property string $ref_id
 * @property string $name
 * @property string $color
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'color', 'sort_order'])]
class PlaceList extends Model
{
    /** @use HasFactory<PlaceListFactory> */
    use BelongsToOwner, HasFactory, HasRefId;

    /** The colours a new list is given in turn. */
    public const COLORS = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'];

    /**
     * Mirror the column defaults so new instances match what the database stores.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort_order' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * The places saved in it.
     *
     * @return HasMany<Place, $this>
     */
    public function places(): HasMany
    {
        return $this->hasMany(Place::class, 'list_id');
    }

    /**
     * The list as the map page reads it.
     *
     * @return array<string, mixed>
     */
    public function toMap(): array
    {
        return [
            'ref_id' => $this->ref_id,
            'name' => $this->name,
            'color' => $this->color,
        ];
    }
}
