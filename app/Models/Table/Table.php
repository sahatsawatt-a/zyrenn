<?php

namespace App\Models\Table;

use App\Models\Concerns\HasRefId;
use App\Models\User;
use App\Support\Table\TableStorage;
use Database\Factories\Table\TableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A spreadsheet-like table. What it looks like -- its columns -- is kept here;
 * its rows live in a database table of their own (see TableStorage).
 *
 * @property int $id
 * @property string $ref_id
 * @property int $user_id
 * @property int|null $folder_id
 * @property string $title
 * @property string $density
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'density'])]
class Table extends Model
{
    /** @use HasFactory<TableFactory> */
    use HasFactory, HasRefId;

    /** How tightly the rows are drawn. */
    public const DENSITIES = ['compact', 'normal', 'spacious'];

    /**
     * Mirror the column defaults so new instances match what the database stores.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'title' => '',
        'density' => 'normal',
    ];

    /**
     * A table's rows go with it: they are not reachable any other way.
     */
    protected static function booted(): void
    {
        static::deleting(fn (Table $table) => TableStorage::drop($table));
    }

    /**
     * Get the user that owns the table.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the folder the table is filed in, if any.
     *
     * @return BelongsTo<TableFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(TableFolder::class, 'folder_id');
    }

    /**
     * Get the table's columns, in the order they are shown.
     *
     * @return HasMany<TableColumn, $this>
     */
    public function columns(): HasMany
    {
        return $this->hasMany(TableColumn::class, 'table_id')->orderBy('sort_order')->orderBy('id');
    }
}
