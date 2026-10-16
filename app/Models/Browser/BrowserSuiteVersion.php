<?php

namespace App\Models\Browser;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A suite's checks as one version of them was: kept each time they change
 * (quick edits in a row by one person, before any run used them, as one), so
 * a run can say what it tested and an earlier version can be restored.
 *
 * @property int $id
 * @property int $browser_suite_id
 * @property int $version
 * @property list<array<string, mixed>> $checks
 * @property int|null $created_by
 */
class BrowserSuiteVersion extends Model
{
    protected $fillable = ['version', 'checks', 'created_by'];

    protected function casts(): array
    {
        return ['checks' => 'array'];
    }

    /** @return BelongsTo<BrowserSuite, $this> */
    public function suite(): BelongsTo
    {
        return $this->belongsTo(BrowserSuite::class, 'browser_suite_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
