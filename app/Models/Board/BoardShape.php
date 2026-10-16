<?php

namespace App\Models\Board;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\HasRefId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A shape of one's own, made on a board -- a vector drawing, a group of
 * shapes and words -- to put on any board, as one group: a person's own, or
 * a project's for all its members. A copy of the items it was made of, set
 * at the top left of its own box (BoardShapes), so changing it later never
 * changes a board it is already on.
 *
 * @property int $id
 * @property string $ref_id
 * @property string $name
 * @property list<array<string, mixed>> $items
 * @property int $width
 * @property int $height
 * @property string|null $preview a small PNG of it, as a data URL
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'items', 'width', 'height', 'preview'])]
class BoardShape extends Model
{
    use BelongsToOwner, HasRefId;

    protected function casts(): array
    {
        return ['items' => 'array'];
    }
}
