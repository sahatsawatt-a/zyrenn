<?php

namespace App\Models\Product;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing in a part's history: made, moved, renamed, its status or its
 * summary changed, a link made or taken away.
 *
 * @property int $id
 * @property int $product_element_id
 * @property int|null $user_id
 * @property string $what
 * @property string|null $from
 * @property string|null $to
 * @property Carbon|null $created_at
 */
class ProductElementEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
