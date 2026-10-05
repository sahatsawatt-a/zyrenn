<?php

namespace App\Models\Map;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\HasRefId;
use App\Models\Concerns\RecordsEditor;
use Database\Factories\Map\PlaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A saved place: where it is, what it's called, a note, and what Google says
 * of it once looked up. It belongs to whoever its list belongs to.
 *
 * @property int $id
 * @property string $ref_id
 * @property int $list_id
 * @property string $name
 * @property string $address
 * @property string $kind
 * @property string|null $note
 * @property float $lat
 * @property float $lng
 * @property array<string, mixed>|null $details
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'address', 'kind', 'note', 'lat', 'lng', 'details'])]
class Place extends Model
{
    /** @use HasFactory<PlaceFactory> */
    use BelongsToOwner, HasFactory, HasRefId, RecordsEditor;

    /**
     * Mirror the column defaults so new instances match what the database stores.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'address' => '',
        'kind' => '',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'details' => 'array',
        ];
    }

    /**
     * The list it is saved in.
     *
     * @return BelongsTo<PlaceList, $this>
     */
    public function list(): BelongsTo
    {
        return $this->belongsTo(PlaceList::class, 'list_id');
    }

    /**
     * The place as the map page reads it.
     *
     * @return array<string, mixed>
     */
    public function toMap(): array
    {
        return [
            'ref_id' => $this->ref_id,
            'list' => $this->list?->ref_id,
            'name' => $this->name,
            'address' => $this->address,
            'kind' => $this->kind,
            'note' => (string) $this->note,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'details' => $this->details,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
