<?php

namespace App\Models\Workflow;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A library a workflow's "Run code" step may use, added by the app's
 * admins. A JavaScript one is kept bundled into one file (`source`), loaded
 * into the step's sandbox ahead of its code and reached by require(name);
 * a Python one is installed in the Python runner and imported by name.
 *
 * @property int $id
 * @property string $language
 * @property string $name
 * @property string|null $asked
 * @property string|null $version
 * @property string $status
 * @property string|null $error
 * @property int|null $size
 * @property string|null $source
 * @property bool $enabled
 * @property int|null $added_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $adder
 */
class CodeLibrary extends Model
{
    public const JAVASCRIPT = 'javascript';

    public const PYTHON = 'python';

    public const LANGUAGES = [self::JAVASCRIPT, self::PYTHON];

    public const INSTALLING = 'installing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    protected $fillable = ['language', 'name', 'asked', 'version', 'status', 'error', 'size', 'source', 'enabled'];

    /** The bundle is for the runner, never for a page. */
    protected $hidden = ['source'];

    protected $attributes = ['status' => self::INSTALLING, 'enabled' => true];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'size' => 'integer'];
    }

    /**
     * Those a step may use now: installed, and not switched off.
     *
     * @param  Builder<CodeLibrary>  $query
     * @return Builder<CodeLibrary>
     */
    public function scopeUsable(Builder $query, string $language): Builder
    {
        return $query->where('language', $language)->where('status', self::READY)->where('enabled', true);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function adder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /**
     * As the admin page shows it.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            ...$this->only(['id', 'language', 'name', 'asked', 'version', 'status', 'error', 'size', 'enabled']),
            'added_by' => $this->adder?->name,
            'updated_at' => $this->updated_at,
        ];
    }
}
