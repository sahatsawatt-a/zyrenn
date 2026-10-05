<?php

namespace App\Models\Table;

use App\Models\Concerns\HasRefId;
use Database\Factories\Table\TableColumnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ref_id
 * @property int $table_id
 * @property string $name
 * @property string $label
 * @property string $type
 * @property bool $is_primary
 * @property int $width
 * @property bool $hidden
 * @property array<array-key, mixed>|null $options_meta
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['label', 'type', 'width', 'hidden', 'options_meta', 'sort_order'])]
class TableColumn extends Model
{
    /** @use HasFactory<TableColumnFactory> */
    use HasFactory, HasRefId;

    /**
     * Mirror the column defaults so new instances match what the database stores.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_primary' => false,
        'width' => 180,
        'hidden' => false,
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
            'is_primary' => 'boolean',
            'hidden' => 'boolean',
            'width' => 'integer',
            'sort_order' => 'integer',
            'options_meta' => 'array', // Automatically handles JSON string serialization
        ];
    }

    /**
     * The column as the grid reads it.
     *
     * Settings beyond the label live in options_meta: the choices of a select,
     * and how a currency or a rating is drawn. A bare list there is the choices
     * on their own, as the first tables were written.
     *
     * @return array<string, mixed>
     */
    public function toGrid(): array
    {
        $meta = $this->options_meta ?? [];
        $settings = array_is_list($meta) ? ['options' => $meta] : $meta;

        return [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'isPrimary' => $this->is_primary,
            'width' => $this->width,
            // $this->hidden is Eloquent's own list of hidden attributes, not this one
            'hidden' => (bool) $this->getAttribute('hidden'),
            'options' => $settings['options'] ?? [],
            'currencySymbol' => $settings['currencySymbol'] ?? null,
            'maxRating' => $settings['maxRating'] ?? null,
            // A formula column's formula (TableFormulas), and what the footer shows under any column
            'expression' => $settings['expression'] ?? null,
            'summary' => $settings['summary'] ?? null,
        ];
    }

    /**
     * Get the parent table spreadsheet that owns this schema column field definition.
     *
     * @return BelongsTo<Table, $this>
     */
    public function parentTable(): BelongsTo
    {
        return $this->belongsTo(Table::class, 'table_id');
    }
}
