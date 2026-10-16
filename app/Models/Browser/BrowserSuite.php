<?php

namespace App\Models\Browser;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\HasRefId;
use App\Models\Concerns\TellsLists;
use App\Models\Drive\DriveFile;
use App\Support\Browser\BrowserCheck;
use App\Support\Browser\Limits;
use App\Support\Browser\SuiteVersions;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A suite of browser checks: a customer's site, say, each check a few steps
 * done in a real browser (App\Support\Browser\BrowserCheck). A person's own,
 * or a project's.
 *
 * @property int $id
 * @property string $ref_id
 * @property int|null $user_id
 * @property int|null $project_id
 * @property int|null $created_by
 * @property string $title
 * @property list<array{id: string, title: string, size?: string, as?: string, fresh?: bool, steps: list<array<string, string>>}> $checks
 * @property int $version
 * @property array{every?: string, at?: string, zone?: string, tickets?: bool, sign_ins?: list<array{name: string, check: string}>, limits?: array{step?: int, check?: int}}|null $settings
 * @property list<array{name: string, value: string}>|null $secret_values
 * @property CarbonInterface|null $next_run_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'checks'])]
#[Hidden(['secret_values'])]
class BrowserSuite extends Model
{
    use BelongsToOwner, HasRefId, TellsLists;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checks' => 'array',
            'settings' => 'array',
            'secret_values' => 'encrypted:array',
            'next_run_at' => 'datetime',
        ];
    }

    /** When it runs by itself: off, every hour, every day, or on weekdays. */
    public const EVERY = ['off', 'hour', 'day', 'weekdays'];

    /**
     * The rules what a suite is given keeps to: its name, its checks (kept as
     * they are written, half-written steps and all), when it runs by itself,
     * and its hidden values. `$checkIds` when each check names its own id.
     *
     * @return array<string, mixed>
     */
    public static function rules(bool $checkIds = true): array
    {
        return [
            // Kept while it is being written, so a name may be empty for now
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'checks' => ['sometimes', 'present', 'array', 'max:50'],
            ...($checkIds ? ['checks.*.id' => ['required', 'string', 'max:16', 'distinct']] : []),
            'checks.*.title' => ['present', 'nullable', 'string', 'max:200'],
            // The screen it is done on: a desktop's, unless said
            'checks.*.size' => ['nullable', Rule::in(BrowserCheck::SIZES)],
            // Whom it starts signed in as (one of sign_ins), or signed out
            'checks.*.as' => ['nullable', 'string', 'regex:'.self::IDENTITY],
            'checks.*.fresh' => ['nullable', 'boolean'],
            ...BrowserCheck::stepRules('checks.*.steps', complete: false),
            'settings' => ['sometimes', 'array'],
            'settings.every' => ['required_with:settings', Rule::in(self::EVERY)],
            'settings.at' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'settings.zone' => ['nullable', 'timezone:all'],
            'settings.tickets' => ['boolean'],
            // How long its steps and checks wait, in seconds, up to the most
            // an admin allows; null for the default
            'limits' => ['sometimes', 'array'],
            'limits.step' => ['nullable', 'integer', 'min:1', 'max:'.Limits::most()['step']],
            'limits.check' => ['nullable', 'integer', 'min:10', 'max:'.Limits::most()['check']],
            // A value left out is kept as it was
            'values' => ['sometimes', 'array', 'max:20'],
            'values.*.name' => ['required', 'string', 'regex:/^[A-Za-z][\w-]{0,39}$/', 'distinct'],
            'values.*.value' => ['nullable', 'string', 'max:500'],
            // Signing in once a run: each by a name, done by one of its checks
            'sign_ins' => ['sometimes', 'array', 'max:'.self::MOST_SIGN_INS],
            'sign_ins.*.name' => ['required', 'string', 'regex:'.self::IDENTITY, 'distinct'],
            'sign_ins.*.check' => ['required', 'string', 'max:200'],
        ];
    }

    /** What a sign-in may be called: a short word, "member", "admin". */
    public const IDENTITY = '/^[A-Za-z][\w-]{0,39}$/';

    /** No suite signs in as more people than this. */
    public const MOST_SIGN_INS = 5;

    /**
     * Who a run signs in as, once, before the checks that start signed in:
     * each a name and the check (by its id) that does the signing in.
     *
     * @return list<array{name: string, check: string}>
     */
    public function signIns(): array
    {
        return array_values(array_filter($this->settings['sign_ins'] ?? [], 'is_array'));
    }

    /**
     * Whom `$check` starts signed in as: the sign-in it names ("as"), else
     * the suite's first -- or nobody, for a check marked "fresh", for a
     * sign-in itself, and in a suite that signs in as nobody.
     *
     * @param  array<string, mixed>  $check
     */
    public function identityFor(array $check): ?string
    {
        $signIns = $this->signIns();

        if ($signIns === [] || ($check['fresh'] ?? false) === true || in_array($check['id'] ?? null, array_column($signIns, 'check'), true)) {
            return null;
        }

        return is_string($check['as'] ?? null) && $check['as'] !== '' ? $check['as'] : $signIns[0]['name'];
    }

    /**
     * Takes what passed rules(): each part only when given. A check without
     * an id keeps the id of the one of its name, or is given a new one.
     *
     * @param  array<string, mixed>  $given
     */
    public function take(array $given): static
    {
        if (array_key_exists('title', $given)) {
            $this->title = (string) $given['title'];
        }

        if (isset($given['checks'])) {
            $ids = array_column($this->checks ?? [], 'id', 'title');
            $this->checks = array_values(array_map(fn (array $check) => [
                'id' => (string) ($check['id'] ?? $ids[(string) $check['title']] ?? Str::lower(Str::random(8))),
                'title' => (string) $check['title'],
                'size' => $check['size'] ?? 'desktop',
                // Only what each step's kind uses, each with its own id
                'steps' => self::stepIds(
                    array_values(array_map(BrowserCheck::only(...), $check['steps'])),
                    $this->stepsOf((string) ($check['id'] ?? $ids[(string) $check['title']] ?? '')),
                ),
                // Whom it starts signed in as, when not the suite's first; or signed out
                ...(is_string($check['as'] ?? null) && $check['as'] !== '' ? ['as' => $check['as']] : []),
                ...(($check['fresh'] ?? false) === true ? ['fresh' => true] : []),
            ], $given['checks']));
        }

        if (isset($given['settings'])) {
            $this->settings = [
                'every' => $given['settings']['every'],
                'at' => $given['settings']['at'] ?? '09:00',
                'zone' => $given['settings']['zone'] ?? config('app.timezone'),
                // Only a project has tickets to file
                'tickets' => $this->project_id !== null && (bool) ($given['settings']['tickets'] ?? false),
                ...($this->signIns() !== [] ? ['sign_ins' => $this->signIns()] : []),
                ...(isset($this->settings['limits']) ? ['limits' => $this->settings['limits']] : []),
            ];
            $this->next_run_at = $this->nextRunAfter(CarbonImmutable::now());
        }

        // A value sent empty keeps the one it had, as one left out does: a
        // client re-sending the names it read (never their values) must not
        // blank them. A value is cleared by leaving its name out.
        // Each sign-in kept by its check's id, named by its id or its title
        if (isset($given['sign_ins'])) {
            $ids = array_column($this->checks ?? [], 'id', 'title');
            $known = array_column($this->checks ?? [], 'id');

            $this->settings = [...($this->settings ?? []), 'sign_ins' => array_values(array_filter(array_map(fn (array $signIn) => [
                'name' => (string) $signIn['name'],
                'check' => in_array($signIn['check'], $known, true) ? (string) $signIn['check'] : ($ids[$signIn['check']] ?? ''),
            ], $given['sign_ins']), fn (array $signIn) => $signIn['check'] !== ''))];
        }

        // Each left out or null waits the default
        if (isset($given['limits'])) {
            $limits = array_filter([
                'step' => isset($given['limits']['step']) ? (int) $given['limits']['step'] : null,
                'check' => isset($given['limits']['check']) ? (int) $given['limits']['check'] : null,
            ], fn (?int $seconds) => $seconds !== null);
            $settings = $this->settings ?? [];
            unset($settings['limits']);
            $this->settings = [...$settings, ...($limits !== [] ? ['limits' => $limits] : [])];
        }

        if (isset($given['values'])) {
            $before = $this->values();
            $this->secret_values = array_values(array_map(fn (array $value) => [
                'name' => (string) $value['name'],
                'value' => isset($value['value']) && $value['value'] !== '' ? (string) $value['value'] : ($before[$value['name']] ?? ''),
            ], $given['values']));
        }

        return $this;
    }

    /**
     * The steps a check of this suite has now, by its id: those it had before
     * a change, for the ones coming back to keep their ids.
     *
     * @return list<array<string, string>>
     */
    private function stepsOf(string $checkId): array
    {
        foreach ($this->getOriginal('checks') ?? [] as $check) {
            if (is_array($check) && ($check['id'] ?? null) === $checkId) {
                return array_values(array_filter($check['steps'] ?? [], 'is_array'));
            }
        }

        return [];
    }

    /**
     * Each step with an id of its own, lasting through edits: the one it was
     * given, else that of the same step as it was before (written again
     * whole, say over MCP), else a new one -- no two alike in a check.
     *
     * @param  list<array<string, string>>  $steps
     * @param  list<array<string, string>>  $before
     * @return list<array<string, string>>
     */
    private static function stepIds(array $steps, array $before): array
    {
        $sameness = fn (array $step) => implode("\0", [$step['do'] ?? '', $step['url'] ?? '', $step['target'] ?? '', $step['text'] ?? '', $step['key'] ?? '']);
        $taken = [];
        $free = [];

        foreach ($before as $old) {
            if (isset($old['id'])) {
                $free[$sameness($old)][] = $old['id'];
            }
        }

        foreach ($steps as $step) {
            if (isset($step['id'])) {
                $taken[$step['id']] = true;
            }
        }

        foreach ($steps as $at => $step) {
            $id = $step['id'] ?? null;

            // Given twice in one check: the second is a step of its own
            if ($id !== null && ($taken[$id] ?? 0) === 'used') {
                $id = null;
            }

            while ($id === null) {
                $same = $sameness($step);
                $id = ($free[$same] ?? []) !== [] ? array_shift($free[$same]) : Str::lower(Str::random(8));
                $id = isset($taken[$id]) ? null : $id;
            }

            $taken[$id] = 'used';
            $steps[$at] = ['id' => $id, ...array_diff_key($step, ['id' => true])];
        }

        return $steps;
    }

    /**
     * What each hidden value's name stands for, as {{ name }} in a step.
     *
     * @return array<string, string>
     */
    public function values(): array
    {
        return collect($this->secret_values ?? [])->pluck('value', 'name')->map(fn ($value) => (string) $value)->all();
    }

    /**
     * Its hidden values, and the Drive files `steps` add to a page -- each as
     * "file:<ref_id>", its name, type and bytes -- for steps on their way to
     * the browser. A file gone from the Drive, or too big, is left out, and
     * its step says so when it runs.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return array<string, string>
     */
    public function valuesFor(array $steps): array
    {
        $refs = collect($steps)
            ->filter(fn (array $step) => ($step['do'] ?? null) === 'upload' && is_string($step['file'] ?? null))
            ->pluck('file')
            ->unique();

        if ($refs->isEmpty()) {
            return $this->values();
        }

        $files = $this->owner()->driveFiles()->whereIn('ref_id', $refs)->where('size', '<=', BrowserCheck::MOST_FILE)->get();

        return $this->values() + $files->mapWithKeys(fn (DriveFile $file) => ["file:{$file->ref_id}" => (string) json_encode([
            'name' => $file->ext && ! str_ends_with(strtolower($file->name), '.'.strtolower($file->ext)) ? "{$file->name}.{$file->ext}" : $file->name,
            'type' => $file->mime,
            'data' => base64_encode((string) Storage::disk(DriveFile::DISK)->get($file->path)),
        ])])->all();
    }

    /**
     * When it next runs by itself after `$after`, in its own time zone; null
     * when it does not.
     */
    public function nextRunAfter(CarbonImmutable $after): ?CarbonImmutable
    {
        $every = $this->settings['every'] ?? 'off';

        if (! in_array($every, ['hour', 'day', 'weekdays'], true)) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', $this->settings['at'] ?? '09:00') + [1 => '0']);
        $local = $after->setTimezone($this->settings['zone'] ?? config('app.timezone'));

        if ($every === 'hour') {
            $next = $local->setTime($local->hour, $minute);

            return ($next <= $local ? $next->addHour() : $next)->utc();
        }

        $next = $local->setTime($hour, $minute);

        while ($next <= $local || ($every === 'weekdays' && $next->isWeekend())) {
            $next = $next->addDay();
        }

        return $next->utc();
    }

    /**
     * Its runs as its history keeps them -- what its health, last run and
     * tickets go by: not a try, which is kept nowhere.
     *
     * @return HasMany<BrowserRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->allRuns()->where('trial', false);
    }

    /**
     * Every run of it, tries too.
     *
     * @return HasMany<BrowserRun, $this>
     */
    public function allRuns(): HasMany
    {
        return $this->hasMany(BrowserRun::class, 'suite_id');
    }

    /**
     * Each version of its checks, as they changed.
     *
     * @return HasMany<BrowserSuiteVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(BrowserSuiteVersion::class, 'browser_suite_id');
    }

    /** Being put back as an earlier version: kept as a version of its own. */
    public bool $restoring = false;

    protected static function booted(): void
    {
        // Each change to its checks kept as a version (SuiteVersions)
        static::saved(function (BrowserSuite $suite) {
            if ($suite->wasRecentlyCreated || $suite->wasChanged('checks')) {
                SuiteVersions::keep($suite, $suite->restoring);
            }
        });
    }
}
