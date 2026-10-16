<?php

namespace App\Support\Browser;

use App\Jobs\RunBrowserChecks;
use App\Models\Browser\BrowserRun;
use App\Models\Browser\BrowserSuite;
use App\Models\Drive\DriveFolder;
use App\Models\Project;
use App\Models\Ticket\Ticket;
use App\Models\User;
use App\Support\Folders;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A run of a suite's checks, however it was started -- by a person, by the
 * suite's own timetable, or by a workflow: each check done in the browser
 * in turn, what each step did written down as each check ends, pictures and
 * films kept in the owner's Drive when asked, and, for a project that wants
 * it, a bug ticket filed for each check that fails. Anyone who may run it may
 * stop it part way (stop()).
 */
final class SuiteRun
{
    public const PERSON = 'person';

    public const TIMETABLE = 'timetable';

    public const WORKFLOW = 'workflow';

    /**
     * How a run goes, made whole: a speed (or one's own pace), and what it
     * shows and keeps. A film is only ever kept in the Drive.
     *
     * @param  array{glide: int, typing: int, pause: int}|null  $pace
     * @param  list<string>  $checks  the checks to run; all of them when none
     * @param  bool  $oneFilm  the checks done one after another in one browser, filmed as one
     * @param  bool  $cutWaits  a film with the waiting on the site cut out of it
     * @return array{speed: string, pace: array{glide: int, typing: int, pause: int}, captions: bool, record: bool, keep: bool, checks: list<string>, one_film: bool, cut_waits: bool}
     */
    public static function settings(string $speed, ?array $pace = null, bool $captions = false, bool $record = false, bool $keep = false, array $checks = [], bool $oneFilm = false, bool $cutWaits = true): array
    {
        return [
            'speed' => $speed,
            'pace' => $speed === 'custom' && $pace !== null
                ? array_map('intval', $pace)
                : BrowserCheck::SPEEDS[$speed] ?? BrowserCheck::SPEEDS['quick'],
            'captions' => $captions,
            'record' => $record,
            'keep' => $record || $keep,
            'checks' => $checks,
            'one_film' => $record && $oneFilm,
            'cut_waits' => $record && $cutWaits,
        ];
    }

    /**
     * A new run of `$suite`, waiting to be carried out. A `trial` is only a
     * try: done and followed as any other, then kept nowhere -- not in the
     * suite's history, no tickets, nothing in the Drive, so it is not filmed
     * -- and taken away soon after it ends (forgetTries).
     *
     * @param  array{speed: string, pace: array{glide: int, typing: int, pause: int}, captions: bool, record: bool, keep: bool, checks: list<string>, one_film?: bool, cut_waits?: bool}  $settings
     */
    public static function start(BrowserSuite $suite, ?User $by, array $settings, string $startedBy = self::PERSON, bool $trial = false): BrowserRun
    {
        if ($trial) {
            $settings = ['record' => false, 'keep' => false, 'one_film' => false, 'cut_waits' => false] + $settings;
        }

        return $suite->allRuns()->create([
            'state' => BrowserRun::WAITING,
            'trial' => $trial,
            'created_by' => $by?->id,
            'settings' => $settings,
            'started_by' => $startedBy,
        ]);
    }

    /** How long a try is kept after it ends, for whoever is looking at it. */
    public const TRIAL_KEPT_MINUTES = 60;

    /**
     * Takes away the tries that ended a while ago -- and any left unfinished
     * for a day. Answers with how many went.
     */
    public static function forgetTries(): int
    {
        return BrowserRun::query()
            ->where('trial', true)
            ->where(fn ($query) => $query
                ->where('ended_at', '<', now()->subMinutes(self::TRIAL_KEPT_MINUTES))
                ->orWhere('created_at', '<', now()->subDay()))
            ->delete();
    }

    /**
     * Starts a run of each suite whose time has come, quick and keeping its
     * pictures (for the tickets it may file), and sets when it next runs.
     * Answers with how many were started.
     */
    public static function due(): int
    {
        $started = 0;

        BrowserSuite::query()
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->each(function (BrowserSuite $suite) use (&$started) {
                // Set first, so a slow run is never started twice for one time
                $suite->forceFill(['next_run_at' => $suite->nextRunAfter(CarbonImmutable::now())])->save();

                if (BrowserCheck::unready(['title' => '', 'steps' => array_merge(...array_column($suite->checks, 'steps'))]) !== null) {
                    return;
                }

                $run = self::start(
                    $suite,
                    $suite->creator,
                    self::settings('quick', keep: (bool) ($suite->settings['tickets'] ?? false)),
                    self::TIMETABLE,
                );
                RunBrowserChecks::dispatch($run->id);
                $started++;
            });

        return $started;
    }

    /**
     * Stops a run part way, for `$by`. A waiting run never starts. A running
     * one stops after the step in hand: the browser closes, the checks done
     * keep their verdicts and pictures, a film so far is kept (marked
     * partial), and it ends "stopped" -- filing no tickets. Answers with the
     * run; one already ended is left as it was.
     */
    public static function stop(BrowserRun $run, ?User $by): BrowserRun
    {
        $asked = ['stop_asked_at' => now(), 'stopped_by' => $by?->id];

        // Still in the queue: taken out of it before the worker gets to it
        $dropped = BrowserRun::whereKey($run->id)->where('state', BrowserRun::WAITING)
            ->update([...$asked, 'state' => BrowserRun::STOPPED, 'ended_at' => now()]);

        if ($dropped === 0 && BrowserRun::whereKey($run->id)->where('state', BrowserRun::RUNNING)->whereNull('stop_asked_at')->update($asked) > 0) {
            // The browser is told at once, so the step in hand is the last;
            // if it cannot be reached, the run still stops before its next check
            try {
                BrowserCheck::stop($run->ref_id);
            } catch (ConnectionException|RuntimeException $problem) {
                report($problem);
            }
        }

        $run->refresh()->tell();

        return $run;
    }

    /** Whether someone has asked for this run to stop, as the database has it now. */
    private static function stopAsked(BrowserRun $run): bool
    {
        return BrowserRun::whereKey($run->id)->whereNotNull('stop_asked_at')->exists();
    }

    /**
     * Why a waiting run has not started, in a sentence; null for one that is
     * not waiting. Runs go one at a time, so it waits for those before it --
     * counted, not named, since they may be someone else's.
     */
    public static function waitingFor(BrowserRun $run): ?string
    {
        if ($run->state !== BrowserRun::WAITING) {
            return null;
        }

        $going = BrowserRun::query()->where('state', BrowserRun::RUNNING)->count();
        $queued = BrowserRun::query()->where('state', BrowserRun::WAITING)->where('id', '<', $run->id)->count();

        if ($going > 0 || $queued > 0) {
            $before = array_filter([
                $going > 0 ? ($going === 1 ? 'a run going now' : "{$going} runs going now") : null,
                $queued > 0 ? ($queued === 1 ? '1 run queued before it' : "{$queued} runs queued before it") : null,
            ]);

            return 'Waiting for '.implode(' and ', $before).': runs of browser checks go one at a time.';
        }

        // Nothing of its kind ahead: other work in its queue, or no worker
        $jobs = config('queue.default') === 'database'
            ? DB::table((string) config('queue.connections.database.table', 'jobs'))
                ->where('queue', RunBrowserChecks::QUEUE)
                ->where('created_at', '<', $run->created_at?->getTimestamp() ?? time())
                ->count()
            : 0;

        if ($jobs > 0) {
            return 'Waiting behind '.($jobs === 1 ? '1 other job' : "{$jobs} other jobs").' in the browser-check queue.';
        }

        return $run->created_at !== null && $run->created_at->lt(now()->subMinute())
            ? 'Nothing is ahead of it, yet it has not started: the browser-check worker may not be running.'
            : 'Starting in a moment.';
    }

    /** Does a waiting run's checks, one after another, and answers with it ended. */
    public static function carryOut(BrowserRun $run): BrowserRun
    {
        // Taken from the queue only while still waiting: one stopped there
        // meanwhile never starts
        $claimed = BrowserRun::whereKey($run->id)->where('state', BrowserRun::WAITING)
            ->update(['state' => BrowserRun::RUNNING, 'started_at' => now(), 'results' => json_encode([])]);

        if ($claimed === 0) {
            return $run->refresh();
        }

        $run->refresh()->tell();

        $suite = $run->suite;
        $settings = $run->settings;
        // What it tests: the version of the suite's checks it starts with
        $run->forceFill(['suite_version' => $suite->version])->saveQuietly();
        $owner = $suite->owner();
        $by = $run->creator;
        // A sign-in is done once, before the checks that start from it -- not
        // as a check of its own, unless it is the one asked for
        $signInChecks = array_column($suite->signIns(), 'check');
        $checks = array_values(array_filter(
            $suite->checks,
            fn (array $check) => in_array($check['id'], $settings['checks'], true)
                || ($settings['checks'] === [] && ! in_array($check['id'], $signInChecks, true)),
        ));
        $values = $suite->valuesFor(array_merge(
            ...array_column($checks, 'steps'),
            ...array_column(array_filter($suite->checks, fn (array $check) => in_array($check['id'], $signInChecks, true)), 'steps'),
        ));

        // Made only once there is something to put in it
        $folder = null;
        $folderFor = function () use (&$folder, $owner, $suite, $run, $by): ?DriveFolder {
            if ($folder === null) {
                $name = fn (string $text) => trim(str_replace('/', '-', $text)) ?: 'Untitled';
                /** @var DriveFolder|null $folder */
                $folder = Folders::ensure(
                    $owner->driveFolders(),
                    'Browser checks/'.$name($suite->title).'/'.$run->created_at?->format('Y-m-d H.i'),
                    $by,
                );
                $run->forceFill(['drive_folder_id' => $folder?->id])->save();
            }

            return $folder;
        };

        // Signed in once, as each person the checks start as: the browser
        // state each sign-in leaves is held here for this run alone
        $whom = array_map(fn (array $check) => $suite->identityFor($check), $checks);
        // How long its steps and checks may wait: its own, within the most allowed
        $limits = Limits::of($suite);
        $keepShot = $settings['keep'] ? fn (string $shot, string $name) => BrowserCheck::keep($shot, $name, $owner, $folderFor(), $by) : null;
        ['states' => $states, 'results' => $results, 'failed' => $failed, 'stopped' => $stopped]
            = self::signIns($run, $suite, array_values(array_unique(array_filter($whom))), $values, $keepShot);
        $passed = $failed === [];

        if ($stopped) {
            $checks = [];
        }

        // Every check in one browser, filmed as one: asked for once, each
        // check's part of the answer then kept as its own -- once everyone
        // in it could be signed in
        $film = ($settings['one_film'] ?? false) && $failed === [] && $checks !== []
            ? self::film(array_map(fn (array $check, ?string $as) => [...$check, 'as' => $as], $checks, $whom), $settings, $values, $run->ref_id, $states, $limits)
            : null;
        $filmKept = null;

        foreach ($checks as $at => $check) {
            // Its sign-in failed: said once, on the sign-in, not as every check failing at its first step
            $notRun = $whom[$at] !== null && isset($failed[$whom[$at]]) ? "Not run: signing in as \"{$whom[$at]}\" failed." : null;
            $notRun ??= $failed !== [] && ($settings['one_film'] ?? false) ? 'Not filmed: a sign-in failed first.' : null;

            if ($notRun !== null) {
                $results[] = ['id' => $check['id'], 'title' => trim($check['title']) ?: 'Untitled check', 'ok' => false, 'steps' => [], 'planned' => count($check['steps']),
                    'refused' => [], 'video' => null, 'problem' => $notRun, 'not_run' => true, 'took' => 0];
                $run->forceFill(['results' => $results])->save();

                continue;
            }

            if ($film === null ? self::stopAsked($run) : ($film['halted'] ?? false) && ($film['chapters'][$at] ?? []) === []) {
                $stopped = true;

                break;
            }

            $began = microtime(true);
            $check['title'] = trim($check['title']) ?: 'Untitled check';

            try {
                $answer = $film === null
                    ? BrowserCheck::run($check['steps'], $settings['pace'], $settings['captions'], $settings['record'], $values, $check['size'] ?? 'desktop', $run->ref_id,
                        $whom[$at] !== null ? ['state' => $states[$whom[$at]]] : [], $limits, $settings['cut_waits'] ?? true)
                    : self::chapter($film, $at);
                // Where in the one film it begins, for its player to go to
                $filmAt = $film !== null && isset($film['starts'][$at]) ? (int) $film['starts'][$at] : null;
                $halted = $answer['halted'] && count($answer['steps']) < count($check['steps']);
                self::journal($run, $check['title'], $answer['log'], $values);

                // Stopped before its first step: there is nothing of it to keep
                if ($halted && $answer['steps'] === [] && $film === null) {
                    $stopped = true;

                    break;
                }

                $steps = [];

                foreach ($answer['steps'] as $at => $step) {
                    $step['say'] = BrowserCheck::say($check['steps'][$at]);

                    if (! empty($step['shot']) && $settings['keep']) {
                        $step['shot'] = BrowserCheck::keep($step['shot'], "{$check['title']} - step ".($at + 1).'.jpg', $owner, $folderFor(), $by);
                    }

                    $steps[] = $step;
                }

                $ok = count($steps) === count($check['steps']) && collect($steps)->every(fn (array $step) => $step['ok']);
                // A film cut short by a stop is kept, and called partial
                $partial = $halted || ($film['halted'] ?? false);
                $result = [
                    'id' => $check['id'],
                    'title' => $check['title'],
                    'size' => $check['size'] ?? 'desktop',
                    'ok' => $ok,
                    'steps' => $steps,
                    'planned' => count($check['steps']),
                    'refused' => $answer['refused'],
                    'video' => self::cut((int) (($film ?? $answer)['cut'] ?? 0), self::partial($partial, match (true) {
                        $film !== null => $filmKept ??= ($film['video'] ?? null) !== null
                            ? BrowserCheck::keep($film['video'], trim($suite->title ?: 'Browser checks').($partial ? ' (partial)' : '').'.webm', $owner, $folderFor(), $by)
                            : null,
                        $answer['video'] !== null => BrowserCheck::keep($answer['video'], "{$check['title']}".($partial ? ' (partial)' : '').'.webm', $owner, $folderFor(), $by),
                        default => null,
                    })),
                    ...($filmAt !== null ? ['film_at' => $filmAt] : []),
                    ...($halted ? ['stopped' => true] : []),
                ];
                $stopped = $halted;
            } catch (Throwable $problem) {
                $ok = false;
                $result = [
                    'id' => $check['id'],
                    'title' => $check['title'],
                    'ok' => false,
                    'steps' => [],
                    'planned' => count($check['steps']),
                    'refused' => [],
                    'video' => null,
                    'problem' => match (true) {
                        $problem instanceof RuntimeException => $problem->getMessage(),
                        // Given up on, rather than not there: the site was slow to answer
                        $problem instanceof ConnectionException && str_contains($problem->getMessage(), 'timed out') => 'It took longer than the browser was given: the site was slow to answer.',
                        default => 'The browser could not be reached.',
                    },
                ];
                report($problem);
            }

            $result['took'] = $film === null
                ? (int) round((microtime(true) - $began) * 1000)
                : (int) array_sum(array_column($result['steps'], 'took'));
            $results[] = $result;
            $passed = $passed && $ok;
            $run->forceFill(['results' => $results])->save();

            if ($stopped) {
                break;
            }
        }

        // Asked to stop once the last check had already ended: it stopped all the same
        $stopped = $stopped || (self::stopAsked($run) && count($results) < count($checks));

        $run->forceFill([
            'state' => match (true) {
                $stopped => BrowserRun::STOPPED,
                $passed => BrowserRun::PASSED,
                default => BrowserRun::FAILED,
            },
            'ended_at' => now(),
        ])->save();

        // How long each step took and waited, for how long it usually needs
        StepTimings::keep($run);

        // A run someone stopped proves nothing broke, and a try is kept nowhere: no tickets
        if (! $passed && ! $stopped && ! $run->trial && $owner instanceof Project && ($suite->settings['tickets'] ?? false)) {
            self::fileTickets($run, $owner);
        }

        return $run;
    }

    /**
     * A film kept in the Drive, with how much waiting on the site was cut
     * from it (ms), so the run page can say so.
     *
     * @param  array<string, mixed>|null  $video
     * @return array<string, mixed>|null
     */
    private static function cut(int $cut, ?array $video): ?array
    {
        return $video !== null && $cut > 0 ? [...$video, 'cut' => $cut] : $video;
    }

    /**
     * A film kept in the Drive, marked partial when a stop cut it short.
     *
     * @param  array<string, mixed>|null  $video
     * @return array<string, mixed>|null
     */
    private static function partial(bool $partial, ?array $video): ?array
    {
        return $video !== null && $partial ? [...$video, 'partial' => true] : $video;
    }

    /**
     * Signs in once as each of `$names`, by each one's own check, quickly and
     * unfilmed, and answers with the browser state each left -- held for the
     * run alone, never kept -- a result for each sign-in (its steps and
     * pictures, as a check's), why each that failed did, and whether the run
     * was stopped meanwhile.
     *
     * @param  list<string>  $names
     * @param  array<string, string>  $values
     * @param  (callable(string, string): array<string, mixed>)|null  $keep  keeps a picture in the Drive
     * @return array{states: array<string, array<string, mixed>>, results: list<array<string, mixed>>, failed: array<string, string>, stopped: bool}
     */
    private static function signIns(BrowserRun $run, BrowserSuite $suite, array $names, array $values, ?callable $keep): array
    {
        $states = [];
        $results = [];
        $failed = [];
        $known = array_column($suite->signIns(), 'check', 'name');
        $checks = array_column($suite->checks, null, 'id');

        foreach ($names as $name) {
            if (self::stopAsked($run)) {
                return ['states' => $states, 'results' => $results, 'failed' => $failed, 'stopped' => true];
            }

            $check = $checks[$known[$name] ?? ''] ?? null;
            $title = "Signing in as \"{$name}\"";
            $began = microtime(true);
            $result = ['id' => $check['id'] ?? $name, 'title' => $title, 'size' => $check['size'] ?? 'desktop', 'ok' => false, 'steps' => [],
                'planned' => count($check['steps'] ?? []), 'refused' => [], 'video' => null, 'sign_in' => $name];

            try {
                if ($check === null) {
                    throw new RuntimeException(isset($known[$name])
                        ? "The check that signs in as \"{$name}\" is gone: choose another in the suite's settings."
                        : "There is no sign-in called \"{$name}\": add it in the suite's settings, or start the check signed out.");
                }

                $answer = BrowserCheck::run($check['steps'], BrowserCheck::SPEEDS['quick'], false, false, $values, $check['size'] ?? 'desktop', $run->ref_id, ['keep' => true], Limits::of($suite));
                self::journal($run, $title, $answer['log'], $values);

                foreach ($answer['steps'] as $at => $step) {
                    $step['say'] = BrowserCheck::say($check['steps'][$at]);

                    if (! empty($step['shot']) && $keep !== null) {
                        $step['shot'] = $keep($step['shot'], "{$title} - step ".($at + 1).'.jpg');
                    }

                    $result['steps'][] = $step;
                }

                $result['refused'] = $answer['refused'];
                $result['ok'] = $answer['state'] !== null;

                if ($answer['state'] !== null) {
                    $states[$name] = $answer['state'];
                } elseif ($answer['halted']) {
                    return ['states' => $states, 'results' => [...$results, [...$result, 'stopped' => true, 'took' => (int) round((microtime(true) - $began) * 1000)]], 'failed' => $failed, 'stopped' => true];
                }
            } catch (Throwable $problem) {
                $result['problem'] = $problem instanceof RuntimeException ? $problem->getMessage() : 'The browser could not be reached.';

                if (! $problem instanceof RuntimeException) {
                    report($problem);
                }
            }

            if (! $result['ok']) {
                $failed[$name] = self::verdict($result);
            }

            $results[] = [...$result, 'took' => (int) round((microtime(true) - $began) * 1000)];
            $run->forceFill(['results' => $results])->save();
        }

        return ['states' => $states, 'results' => $results, 'failed' => $failed, 'stopped' => false];
    }

    /**
     * The checks run one after another in one browser and filmed as one, or
     * what kept the browser from it.
     *
     * @param  list<array<string, mixed>>  $checks
     * @param  array<string, mixed>  $settings
     * @param  array<string, string>  $values
     * @param  array<string, array<string, mixed>>  $states  the browser state each sign-in left
     * @param  array{step: int, check: int}  $limits
     * @return array<string, mixed>
     */
    private static function film(array $checks, array $settings, array $values, string $token, array $states = [], array $limits = Limits::DEFAULT): array
    {
        try {
            return BrowserCheck::film(
                array_map(fn (array $check) => ['title' => trim($check['title']) ?: 'Untitled check', 'steps' => $check['steps'], 'as' => $check['as'] ?? null], $checks),
                $settings['pace'],
                $settings['captions'],
                $values,
                // One screen for the whole film: the first check's
                $checks[0]['size'] ?? 'desktop',
                $token,
                $states,
                $limits,
                $settings['cut_waits'] ?? true,
            );
        } catch (Throwable $problem) {
            return ['problem' => $problem];
        }
    }

    /**
     * One check's part of a film, as a run of it alone would answer --
     * nothing for a check the film never reached.
     *
     * @param  array<string, mixed>  $film
     * @return array{steps: list<array<string, mixed>>, refused: list<string>, video: null, halted: bool, log: list<array<string, mixed>>}
     */
    private static function chapter(array $film, int $at): array
    {
        if (isset($film['problem'])) {
            throw $film['problem'];
        }

        return [
            'steps' => array_values($film['chapters'][$at] ?? []),
            'refused' => $at === 0 ? $film['refused'] : [],
            'video' => null,
            'halted' => (bool) ($film['halted'] ?? false),
            // What its own steps met, of the one film's log
            'log' => array_values(array_filter($film['log'] ?? [], fn (array $entry) => ($entry['chapter'] ?? 0) === $at)),
        ];
    }

    /** No run keeps more of its log than this, in lines. */
    public const LOG_LINES = 200;

    /** Nor a line longer than this. */
    private const LOG_LINE = 240;

    /**
     * Keeps what each step of `$check` met in the browser, for the app's
     * admins: when it began and ended, where it ended up, how long it waited
     * on the site, and its console, page errors and failed requests -- every
     * hidden value the steps type blotted out of it, each line cut short, and
     * the whole run's log held to LOG_LINES.
     *
     * @param  list<array<string, mixed>>  $entries  as the browser gave them, a step each
     * @param  array<string, string>  $values  the suite's hidden values
     */
    private static function journal(BrowserRun $run, string $check, array $entries, array $values): void
    {
        if ($entries === []) {
            return;
        }

        // Hidden values, not the Drive files a step adds (those are no secret)
        $secrets = array_filter($values, fn (string $value, string $name) => ! str_starts_with($name, 'file:') && mb_strlen($value) >= 3, ARRAY_FILTER_USE_BOTH);
        $blot = fn (string $text) => mb_substr(str_replace(array_values($secrets), '•••', $text), 0, self::LOG_LINE);

        $log = $run->log ?? [];
        $room = self::LOG_LINES - array_sum(array_map(fn (array $entry) => count($entry['lines']) + 1, $log));

        foreach ($entries as $entry) {
            if ($room <= 0) {
                break;
            }

            $lines = array_map(fn ($line) => $blot((string) $line), array_values(array_filter((array) ($entry['lines'] ?? []), 'is_string')));
            // The step itself takes a line; what does not fit is said in the last
            $fits = $room - 1;
            $kept = count($lines) <= $fits ? $lines : [
                ...array_slice($lines, 0, max(0, $fits - 1)),
                '… '.(count($lines) - max(0, $fits - 1)).' more left out: the log is full',
            ];

            $log[] = [
                'check' => $check,
                'step' => (int) ($entry['step'] ?? 0),
                'from' => (int) ($entry['from'] ?? 0),
                'to' => (int) ($entry['to'] ?? 0),
                'url' => $blot((string) ($entry['url'] ?? '')),
                ...(isset($entry['waited']) ? ['waited' => (int) $entry['waited']] : []),
                'lines' => $kept,
            ];
            $room -= count($kept) + 1;
        }

        $run->forceFill(['log' => $log])->save();
    }

    /**
     * Where a check stopped, in a sentence: what a ticket and a workflow say.
     *
     * @param  array<string, mixed>  $check
     */
    public static function verdict(array $check): string
    {
        if (! empty($check['problem'])) {
            return (string) $check['problem'];
        }

        if ($check['ok']) {
            return "Passed: {$check['planned']} steps";
        }

        if ($check['stopped'] ?? false) {
            return 'Stopped after step '.count($check['steps']).' of '.$check['planned'];
        }

        foreach ($check['steps'] as $at => $step) {
            if (! $step['ok']) {
                return 'Failed at step '.($at + 1).", {$step['say']}: ".($step['error'] ?? '');
            }
        }

        return 'Stopped after step '.count($check['steps']);
    }

    /** How a whole run went, in a sentence. */
    public static function summary(BrowserRun $run): string
    {
        $results = collect($run->results ?? []);

        if ($run->state === BrowserRun::STOPPED) {
            return self::stoppedAt($run).'.';
        }

        // A sign-in that failed is the one thing to say: the checks after it never began
        $signIn = $results->first(fn (array $check) => isset($check['sign_in']) && ! $check['ok']);

        if ($signIn !== null) {
            $notRun = $results->filter(fn (array $check) => $check['not_run'] ?? false)->count();

            return "Sign-in failed: {$signIn['title']} -- ".rtrim(self::verdict($signIn), '.').'.'
                .($notRun > 0 ? ' '.($notRun === 1 ? '1 check' : "{$notRun} checks").' not run.' : '');
        }

        $failed = $results->reject(fn (array $check) => $check['ok']);

        if ($failed->isEmpty()) {
            return $results->count() === 1
                ? 'Passed: the check did what it should.'
                : "Passed: all {$results->count()} checks did what they should.";
        }

        return "Failed: {$failed->count()} of {$results->count()} ".($results->count() === 1 ? 'check' : 'checks').' did not -- '
            .$failed->map(fn (array $check) => "{$check['title']}: ".self::verdict($check))->implode('; ').'.';
    }

    /**
     * Who stopped a run, and where: "Stopped by Sam at "Sign in", step 3 --
     * 2 checks done before it", or "Stopped by Sam before it started".
     */
    public static function stoppedAt(BrowserRun $run): string
    {
        $who = $run->stopper?->name;
        $by = 'Stopped'.($who !== null ? " by {$who}" : '');
        $results = collect($run->results ?? []);

        if ($run->started_at === null) {
            return "{$by} before it started";
        }

        $last = $results->last();

        if ($last === null) {
            return "{$by} before its first check";
        }

        $done = $results->count() - (($last['stopped'] ?? false) ? 1 : 0);
        $where = ($last['stopped'] ?? false)
            ? " at \"{$last['title']}\", step ".count($last['steps']).' of '.$last['planned']
            : " after \"{$last['title']}\"";

        return $by.$where.' -- '.($done === 1 ? '1 check' : "{$done} checks").' done';
    }

    /**
     * A bug ticket for each check that failed -- unless one about it is still
     * open, so a check failing every hour files it once.
     */
    private static function fileTickets(BrowserRun $run, Project $project): void
    {
        $suite = $run->suite;
        $filed = [];

        foreach ($run->results ?? [] as $check) {
            // Not run, for a sign-in that failed: the sign-in's ticket says it
            if ($check['ok'] || ($check['not_run'] ?? false)) {
                continue;
            }

            $title = Str::limit("Browser check fails: {$check['title']} ({$suite->title})", 250);
            $open = Ticket::query()
                ->where('project_id', $project->id)
                ->where('title', $title)
                ->whereIn('status', Ticket::UNSETTLED)
                ->first();

            if ($open !== null) {
                $filed[] = ['number' => $open->number, 'title' => $title, 'new' => false];

                continue;
            }

            /** @var list<array<string, mixed>> $steps */
            $steps = $check['steps'];
            $pictures = collect($steps)
                ->filter(fn (array $step) => is_array($step['shot'] ?? null))
                ->map(fn (array $step) => ['src' => $step['shot']['url'], 'name' => $step['shot']['name']])
                ->values()
                ->all();

            $ticket = Ticket::file($project, $suite->creator, [
                'type' => Ticket::BUG,
                'priority' => 'normal',
                'title' => $title,
                'unit' => 'Browser checks',
                'body' => implode("\n\n", array_filter([
                    self::verdict($check).'.',
                    $check['video'] ? "A film of it: [{$check['video']['name']}]({$check['video']['url']})" : null,
                    'From a run of the suite "'.$suite->title.'" on '.$run->created_at?->format('j M Y, H:i').'.',
                ])),
                'pictures' => $pictures,
            ]);
            $filed[] = ['number' => $ticket->number, 'title' => $title, 'new' => true];
        }

        $run->forceFill(['tickets' => $filed])->save();
    }
}
