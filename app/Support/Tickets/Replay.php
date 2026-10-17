<?php

namespace App\Support\Tickets;

use App\Jobs\RunBrowserChecks;
use App\Models\Browser\BrowserRun;
use App\Models\Browser\BrowserSuite;
use App\Models\Project;
use App\Models\Ticket\Ticket;
use App\Models\User;
use App\Support\Browser\BrowserCheck;
use App\Support\Browser\SuiteRun;
use Illuminate\Validation\ValidationException;

/**
 * A bug's browser check: one of its project's checks that does what makes the
 * bug happen, and passes once it no longer does. Run from the ticket, it says
 * whether the bug still happens -- by the last run that did that check,
 * whoever or whatever started it.
 */
final class Replay
{
    /** How many of a suite's latest runs are looked through for one that did the check. */
    private const LOOKED_BACK = 30;

    /**
     * The check a ticket names, as "suite" (a ref_id or a title) and "check"
     * (an id or a title), found among the project's suites: the columns the
     * ticket keeps it in. Neither, for none.
     *
     * @return array{browser_suite_id: int|null, browser_check: string|null}
     */
    public static function find(Project $project, ?string $suite, ?string $check): array
    {
        $suite = trim((string) $suite);
        $check = trim((string) $check);

        if ($suite === '' && $check === '') {
            return ['browser_suite_id' => null, 'browser_check' => null];
        }

        /** @var BrowserSuite|null $found */
        $found = $project->browserSuites()->where('ref_id', $suite)->first()
            ?? $project->browserSuites()->whereRaw('lower(title) = ?', [mb_strtolower($suite)])->first();

        if ($found === null) {
            throw ValidationException::withMessages([
                'check' => "\"{$project->name}\" has no browser suite \"{$suite}\". See list-browser-suites.",
            ]);
        }

        $named = collect($found->checks)->first(fn (array $one) => $one['id'] === $check)
            ?? collect($found->checks)->first(fn (array $one) => mb_strtolower(trim((string) $one['title'])) === mb_strtolower($check));

        if ($named === null) {
            throw ValidationException::withMessages([
                'check' => "The suite \"{$found->title}\" has no check \"{$check}\".",
            ]);
        }

        return ['browser_suite_id' => $found->id, 'browser_check' => $named['id']];
    }

    /**
     * The check itself, while its suite still has it.
     *
     * @return array{id: string, title: string, size?: string, as?: string, fresh?: bool, steps: list<array<string, string>>}|null
     */
    public static function check(Ticket $ticket): ?array
    {
        if ($ticket->suite === null || $ticket->browser_check === null) {
            return null;
        }

        return collect($ticket->suite->checks)->first(fn (array $one) => $one['id'] === $ticket->browser_check);
    }

    /**
     * Runs the check once, quick and filmed, to see whether the bug still
     * happens. Whoever may run the suite's checks may.
     */
    public static function start(Ticket $ticket, User $by): BrowserRun
    {
        $check = self::check($ticket);

        if ($check === null) {
            throw ValidationException::withMessages(['check' => 'This ticket has no browser check to run: choose one first.']);
        }

        $missing = BrowserCheck::unready($check);

        if ($missing !== null) {
            throw ValidationException::withMessages(['check' => "Before it can run -- {$missing}."]);
        }

        /** @var BrowserSuite $suite */
        $suite = $ticket->suite;

        $run = SuiteRun::start($suite, $by, [
            ...SuiteRun::settings('quick', record: true, checks: [$check['id']]),
            // Run for this ticket: a failure is this bug, not a new one to file
            'ticket' => $ticket->number,
        ]);

        RunBrowserChecks::dispatch($run->id);

        return $run;
    }

    /** The last run that did the check: started from the ticket or not. */
    public static function lastRun(Ticket $ticket): ?BrowserRun
    {
        if ($ticket->suite === null || $ticket->browser_check === null) {
            return null;
        }

        return $ticket->suite->allRuns()
            ->latest('id')
            ->limit(self::LOOKED_BACK)
            ->get()
            ->first(fn (BrowserRun $run) => self::did($run, (string) $ticket->browser_check));
    }

    /** Whether a run did -- or is about to do -- the check. */
    private static function did(BrowserRun $run, string $check): bool
    {
        if (! $run->ended()) {
            $only = $run->settings['checks'] ?? [];

            return $only === [] || in_array($check, $only, true);
        }

        return collect($run->results ?? [])->contains(fn (array $result) => ($result['id'] ?? null) === $check && ! ($result['not_run'] ?? false));
    }

    /**
     * The check as a ticket shows it: which, and how its last run went --
     * "still happens" when it failed, gone when it passed.
     *
     * @return array<string, mixed>|null
     */
    public static function described(Ticket $ticket): ?array
    {
        if ($ticket->browser_suite_id === null && $ticket->browser_check === null) {
            return null;
        }

        $check = self::check($ticket);
        $suite = $ticket->suite;

        if ($check === null || $suite === null) {
            // Its suite, or the check in it, was deleted since
            return ['gone' => true, 'suite' => $suite?->ref_id, 'check' => $ticket->browser_check];
        }

        $run = self::lastRun($ticket);
        $result = $run !== null ? collect($run->results ?? [])->firstWhere('id', $check['id']) : null;

        return [
            'gone' => false,
            'suite' => $suite->ref_id,
            'suite_title' => $suite->title,
            'check' => $check['id'],
            'title' => trim((string) $check['title']) ?: 'Untitled check',
            'steps' => count($check['steps']),
            'url' => route('projects.checks.index', ['project' => $ticket->project, 'suite' => $suite->ref_id]),
            'run' => $run === null ? null : [
                'ref_id' => $run->ref_id,
                'state' => $run->state,
                // Passed: the bug no longer happens; failed: it still does
                'ok' => $result !== null && $run->ended() ? (bool) $result['ok'] : null,
                'verdict' => $result !== null && $run->ended() ? SuiteRun::verdict($result) : null,
                'video' => $result['video'] ?? null,
                'at' => $run->ended_at ?? $run->created_at,
                // Followed as it goes, and seen in full on the checks page
                'url' => route('checks.runs.show', $run, absolute: false),
                'page' => route('projects.checks.index', ['project' => $ticket->project, 'suite' => $suite->ref_id, 'run' => $run->ref_id]),
            ],
        ];
    }
}
