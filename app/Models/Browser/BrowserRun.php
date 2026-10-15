<?php

namespace App\Models\Browser;

use App\Models\Concerns\HasRefId;
use App\Models\Drive\DriveFolder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One run of a suite's checks (App\Jobs\RunBrowserChecks): at what speed, with
 * what on screen, and what each step did -- its pictures and video in the
 * Drive when they were kept there.
 *
 * @property int $id
 * @property string $ref_id
 * @property int $suite_id
 * @property int|null $created_by
 * @property array{pace: array{glide: int, typing: int, pause: int}, speed: string, captions: bool, record: bool, keep: bool, checks: list<string>, one_film?: bool} $settings
 * @property string $state
 * @property list<array<string, mixed>>|null $results
 * @property int|null $drive_folder_id
 * @property string $started_by
 * @property list<array{number: int, title: string, new: bool}>|null $tickets
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $created_at
 */
class BrowserRun extends Model
{
    use HasRefId;

    public const WAITING = 'waiting';

    public const RUNNING = 'running';

    public const PASSED = 'passed';

    public const FAILED = 'failed';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'results' => 'array',
            'tickets' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BrowserSuite, $this>
     */
    public function suite(): BelongsTo
    {
        return $this->belongsTo(BrowserSuite::class, 'suite_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<DriveFolder, $this>
     */
    public function driveFolder(): BelongsTo
    {
        return $this->belongsTo(DriveFolder::class, 'drive_folder_id');
    }

    public function ended(): bool
    {
        return in_array($this->state, [self::PASSED, self::FAILED], true);
    }
}
