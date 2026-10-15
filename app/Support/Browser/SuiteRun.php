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
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A run of a suite's checks, however it was started -- by a person, by the
 * suite's own timetable, or by a workflow: each check done in the browser
 * in turn, what each step did written down as each check ends, pictures and
 * films kept in the owner's Drive when asked, and, for a project that wants
 * it, a bug ticket filed for each check that fails.
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
     * @return array{speed: string, pace: array{glide: int, typing: int, pause: int}, captions: bool, record: bool, keep: bool, checks: list<string>, one_film: bool}
     */
    public static function settings(string $speed, ?array $pace = null, bool $captions = false, bool $record = false, bool $keep = false, array $checks = [], bool $oneFilm = false): array
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
        ];
    }

    /**
     * A new run of `$suite`, waiting to be carried out.
     *
     * @param  array{speed: string, pace: array{glide: int, typing: int, pause: int}, captions: bool, record: bool, keep: bool, checks: list<string>, one_film?: bool}  $settings
     */
    public static function start(BrowserSuite $suite, ?User $by, array $settings, string $startedBy = self::PERSON): BrowserRun
    {
        return $suite->runs()->create([
            'state' => BrowserRun::WAITING,
            'created_by' => $by?->id,
            'settings' => $settings,
            'started_by' => $startedBy,
        ]);
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

    /** Does a waiting run's checks, one after another, and answers with it ended. */
    public static function carryOut(BrowserRun $run): BrowserRun
    {
        if ($run->state !== BrowserRun::WAITING) {
            return $run;
        }

        $suite = $run->suite;
        $settings = $run->settings;
        $owner = $suite->owner();
        $by = $run->creator;
        $checks = array_values(array_filter(
            $suite->checks,
            fn (array $check) => $settings['checks'] === [] || in_array($check['id'], $settings['checks'], true),
        ));
        $values = $suite->valuesFor(array_merge(...array_column($checks, 'steps')));

        $run->forceFill(['state' => BrowserRun::RUNNING, 'started_at' => now(), 'results' => []])->save();

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

        $results = [];
        $passed = true;
        // Every check in one browser, filmed as one: asked for once, each
        // check's part of the answer then kept as its own
        $film = ($settings['one_film'] ?? false) ? self::film($checks, $settings, $values) : null;
        $filmKept = null;

        foreach ($checks as $at => $check) {
            $began = microtime(true);
            $check['title'] = trim($check['title']) ?: 'Untitled check';

            try {
                $answer = $film === null
                    ? BrowserCheck::run($check['steps'], $settings['pace'], $settings['captions'], $settings['record'], $values, $check['size'] ?? 'desktop')
                    : self::chapter($film, $at);
                $steps = [];

                foreach ($answer['steps'] as $at => $step) {
                    $step['say'] = BrowserCheck::say($check['steps'][$at]);

                    if (! empty($step['shot']) && $settings['keep']) {
                        $step['shot'] = BrowserCheck::keep($step['shot'], "{$check['title']} - step ".($at + 1).'.jpg', $owner, $folderFor(), $by);
                    }

                    $steps[] = $step;
                }

                $ok = count($steps) === count($check['steps']) && collect($steps)->every(fn (array $step) => $step['ok']);
                $result = [
                    'id' => $check['id'],
                    'title' => $check['title'],
                    'size' => $check['size'] ?? 'desktop',
                    'ok' => $ok,
                    'steps' => $steps,
                    'planned' => count($check['steps']),
                    'refused' => $answer['refused'],
                    'video' => match (true) {
                        $film !== null => $filmKept ??= ($film['video'] ?? null) !== null
                            ? BrowserCheck::keep($film['video'], trim($suite->title ?: 'Browser checks').'.webm', $owner, $folderFor(), $by)
                            : null,
                        $answer['video'] !== null => BrowserCheck::keep($answer['video'], "{$check['title']}.webm", $owner, $folderFor(), $by),
                        default => null,
                    },
                ];
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
        }

        $run->forceFill([
            'state' => $passed ? BrowserRun::PASSED : BrowserRun::FAILED,
            'ended_at' => now(),
        ])->save();

        if (! $passed && $owner instanceof Project && ($suite->settings['tickets'] ?? false)) {
            self::fileTickets($run, $owner);
        }

        return $run;
    }

    /**
     * The checks run one after another in one browser and filmed as one, or
     * what kept the browser from it.
     *
     * @param  list<array<string, mixed>>  $checks
     * @param  array<string, mixed>  $settings
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    private static function film(array $checks, array $settings, array $values): array
    {
        try {
            return BrowserCheck::film(
                array_map(fn (array $check) => ['title' => trim($check['title']) ?: 'Untitled check', 'steps' => $check['steps']], $checks),
                $settings['pace'],
                $settings['captions'],
                $values,
                // One screen for the whole film: the first check's
                $checks[0]['size'] ?? 'desktop',
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
     * @return array{steps: list<array<string, mixed>>, refused: list<string>, video: null}
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
        ];
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
     * A bug ticket for each check that failed -- unless one about it is still
     * open, so a check failing every hour files it once.
     */
    private static function fileTickets(BrowserRun $run, Project $project): void
    {
        $suite = $run->suite;
        $filed = [];

        foreach ($run->results ?? [] as $check) {
            if ($check['ok']) {
                continue;
            }

            $title = Str::limit("Browser check fails: {$check['title']} ({$suite->title})", 250);
            $open = Ticket::query()
                ->where('project_id', $project->id)
                ->where('title', $title)
                ->whereIn('status', [Ticket::OPEN, Ticket::IN_PROGRESS])
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
