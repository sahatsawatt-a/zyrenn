<?php

namespace App\Models\Product;

use App\Models\Concerns\TellsLists;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one part of the product is to another, beside where each sits:
 * "Change border colour" uses the colour picker; "Undo" depends on history.
 *
 * @property int $id
 * @property int $from_id
 * @property int $to_id
 * @property string $kind
 */
class ProductLink extends Model
{
    use TellsLists;

    protected $guarded = [];

    /**
     * @return BelongsTo<ProductElement, $this>
     */
    public function from(): BelongsTo
    {
        return $this->belongsTo(ProductElement::class, 'from_id');
    }

    /**
     * @return BelongsTo<ProductElement, $this>
     */
    public function to(): BelongsTo
    {
        return $this->belongsTo(ProductElement::class, 'to_id');
    }
}
