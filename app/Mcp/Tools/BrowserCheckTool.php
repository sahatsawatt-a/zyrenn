<?php

namespace App\Mcp\Tools;

use App\Models\Browser\BrowserRun;
use App\Models\Browser\BrowserSuite;
use App\Models\Drive\DriveFolder;
use App\Models\Owner;
use App\Models\User;
use App\Support\Browser\BrowserCheck;
use App\Support\Browser\Limits;
use App\Support\Browser\SuiteRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

/**
 * Base for the browser check tools: suites of checks of a public site, the
 * user's own or a project's, each check a few steps done in a real browser.
 *
 * @extends ScopedTool<DriveFolder>
 */
abstract class BrowserCheckTool extends ScopedTool
{
    /**
     * Suites are not filed in folders: this is never asked for.
     *
     * @return HasMany<DriveFolder, covariant Model&Owner>
     */
    protected function folders(Owner $owner): HasMany
    {
        return $owner->driveFolders();
    }

    /** What one step is, as the tools take it. */
    protected function stepSchema(JsonSchema $schema): Type
    {
        return $schema->object([
            'do' => $schema->string()->enum(BrowserCheck::KINDS)->required(),
            'url' => $schema->string()->max(4000)->description('For "open": the page, https://...'),
            'target' => $schema->string()->max(200)->description('For "click", "hover", "type", "choose", "upload", "see" and "gone": what it says on the page (look-at-page lists the names). For "scroll": what to scroll to, or none for a screen further down. For "down" and "drag": what to press on (a canvas, a card); for "move": the thing its points are in, if any.'),
            'text' => $schema->string()->max(2000)->description('For "type": what to type; {{name}} for a hidden value. For "choose": the option to choose. For "address": part of the address the page must be at. For "upload": the file\'s name.'),
            'key' => $schema->string()->max(30)->description('For "press": Enter, Tab, Escape, Backspace, ArrowDown, ArrowUp or Space.'),
            'selector' => $schema->string()->max(1000)->description('For a thing with no words of its own (a picture), its "selector" as look-at-page gives it.'),
            'nth' => $schema->integer()->min(1)->max(50)->description('Which of several sharing the name: 3 for the 3rd "Add to cart".'),
            'frame' => $schema->string()->max(1000)->description('For a thing inside a frame of the page (a card form), its "frame" as look-at-page gives it.'),
            'file' => $schema->string()->max(40)->description('For "upload": the ref_id of the Drive file to add (list-drive finds it).'),
            'note' => $schema->string()->max(600)->description('For any step: what to tell someone watching it, a few sentences at most -- how to do this in the app, why it matters. A demo video captions only steps with a note, in these words.'),
            'wait' => $schema->number()->min(0)->max(30)->description('For any step: how many seconds a demo video stays on it after it is done (0 to 30, e.g. 4.5) -- in place of its note\'s own time (3 to 8 s by its length) or, with no note, its pause. 0 goes straight on. Left out, the defaults.'),
            // Holding the pointer: "down" ... "move" ... "up", or "drag" in one
            'at' => $schema->string()->description('For "down" and "drag": where in the thing it is pressed; for "move" with a target: where it ends. Fractions of the thing\'s box, "0.2,0.3" (0,0 top left, 1,1 bottom right); left out, its middle.'),
            'onto' => $schema->string()->max(200)->description('For "drag": the thing it is dropped on, by what it says -- a column, a bin.'),
            'motion' => $schema->string()->enum(['straight', 'curve', 'path'])->description('For "move" and "drag": "straight" (the default) goes in straight lines through any "points"; "curve" goes smoothly through them; "path" follows "path".'),
            'points' => $schema->string()->max(2000)->description('For "move" and "drag": the points it goes through, "0.2,0.3 0.5,0.8 0.8,0.3" -- fractions of the thing\'s box, or with no target pixels from where the pointer is. It ends at the last, unless "onto", "by" or (a move) "at" says. "500ms" after a point pauses there.'),
            'path' => $schema->string()->max(4000)->description('For "move" and "drag" with "motion": "path": SVG path data -- M L H V C S Q T A Z, absolute or relative -- for circles, arcs, spirals, shapes. 0,0 is where it is pressed, in pixels (or with "units": "box", the thing\'s top left, 1,1 its bottom right). Each M after the first lifts and presses again: several strokes in one step. "500ms" between pieces pauses. E.g. a circle of radius 60: "M 0 0 a 60 60 0 1 0 120 0 a 60 60 0 1 0 -120 0".'),
            'units' => $schema->string()->enum(['px', 'box'])->description('For "path": pixels from where it is pressed (the default), or "box", fractions of the thing\'s box.'),
            'by' => $schema->string()->description('For "move" and "drag": moved this many pixels on, "120,-40".'),
            'duration' => $schema->number()->min(0)->max(20)->description('For "move" and "drag": how many seconds the move takes, for a film at a person\'s pace. Left out, as the run\'s speed has it (at once when quick).'),
            'keys' => $schema->string()->max(40)->description('For "down" and "drag": keys held until it lets go, "Shift" or "Alt+Shift" (Shift, Alt, Control, Meta) -- a straight line, a perfect shape.'),
            'button' => $schema->string()->enum(['left', 'right', 'middle'])->description('For "down" and "drag": the mouse button; left unless said.'),
            'clicks' => $schema->integer()->min(1)->max(2)->description('For "down" and "drag": 2 to double-click and hold on the second -- double-click and drag.'),
            'pointer' => $schema->string()->enum(['mouse', 'pen', 'touch'])->description('For "down" and "drag": pressed with a mouse (the default), a pen or a finger. Steps between a "down" and its "up" -- "press" Escape, "see", "shot" -- are done while it is held; a check that ends while held lets go.'),
            'timeout' => $schema->integer()->min(1)->max(Limits::CEILING['step'])->description('For any step: how many seconds it waits for the site -- a page to load, a thing to turn up or go -- before it fails, in place of the suite\'s step limit (10 s unless the suite says otherwise), up to the most an admin allows. For a slow page or a long job. Left out, the suite\'s.'),
        ]);
    }

    /**
     * One of a suite's checks, by its name.
     *
     * @return array{id: string, title: string, size?: string, as?: string, fresh?: bool, steps: list<array<string, string>>}
     */
    protected function checkCalled(BrowserSuite $suite, string $title): array
    {
        foreach ($suite->checks as $check) {
            if ($check['title'] === $title) {
                return $check;
            }
        }

        throw ValidationException::withMessages(['check' => "\"{$suite->title}\" has no check called \"{$title}\". See get-browser-suite."]);
    }

    /**
     * A check as the tools give it back: each step in words beside what it is.
     *
     * @param  array{id: string, title: string, size?: string, as?: string, fresh?: bool, steps: list<array<string, string>>}  $check
     * @return array<string, mixed>
     */
    protected function checkDescribed(array $check): array
    {
        return [
            'title' => $check['title'],
            'size' => $check['size'] ?? 'desktop',
            // Whom it starts signed in as, when it says; or signed out
            ...(isset($check['as']) ? ['as' => $check['as']] : []),
            ...(($check['fresh'] ?? false) ? ['fresh' => true] : []),
            'steps' => array_map(fn (array $step, int $at) => ['step' => $at + 1, ...$step, 'says' => BrowserCheck::say($step)], $check['steps'], array_keys($check['steps'])),
        ];
    }

    /**
     * A page as the browser found it, for an AI client to go on from: where it
     * is, where steps stopped if they did, and what is on it by the names
     * steps go by.
     *
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    protected function pageDescribed(array $page): array
    {
        /** @var list<array{ok: bool, error?: string}> $steps */
        $steps = $page['steps'] ?? [];
        $stopped = null;

        foreach ($steps as $at => $step) {
            if (! $step['ok']) {
                $stopped = 'Step '.($at + 1).' could not be done: '.($step['error'] ?? '').' This is the page before it.';
                break;
            }
        }

        return [
            'url' => $page['url'],
            'title' => $page['title'],
            'stopped' => $stopped,
            'things' => array_map(fn (array $thing) => [
                'kind' => $thing['kind'],
                'name' => $thing['name'],
                ...($thing['count'] > 1 ? ['count' => $thing['count']] : []),
                ...(isset($thing['selector']) ? ['selector' => $thing['selector']] : []),
                ...(isset($thing['nth']) ? ['nth' => $thing['nth']] : []),
                ...(isset($thing['frame']) ? ['frame' => $thing['frame']] : []),
                // A slider's ends, a date's form
                ...(isset($thing['input']) ? ['input' => $thing['input']] : []),
                ...(isset($thing['min']) ? ['min' => $thing['min'], 'max' => $thing['max'] ?? null] : []),
                // A list's options, to choose one of
                ...(isset($thing['options']) ? ['options' => $thing['options'], 'chosen' => $thing['chosen'] ?? null] : []),
            ], $page['things']),
            'further_down' => $page['below'],
        ];
    }

    /** One of the owner's suites, by ref_id or by its name. */
    protected function suiteCalled(Owner $owner, string $named): BrowserSuite
    {
        $suite = $owner->browserSuites()
            ->where(fn ($query) => $query->where('ref_id', $named)->orWhere('title', $named))
            ->first();

        if (! $suite instanceof BrowserSuite) {
            throw ValidationException::withMessages(['suite' => "There is no suite of browser checks called \"{$named}\". See list-browser-suites."]);
        }

        return $suite;
    }

    /**
     * A suite in full: its checks, each step said in words beside what it
     * is, when it runs by itself, and the names of its hidden values.
     *
     * @return array<string, mixed>
     */
    protected function suiteDescribed(BrowserSuite $suite): array
    {
        return [
            'ref_id' => $suite->ref_id,
            'title' => $suite->title,
            'checks' => array_map(fn (array $check) => [
                'title' => $check['title'],
                // Whom it starts signed in as (null: signed out)
                ...($suite->signIns() !== [] ? ['starts_as' => $suite->identityFor($check)] : []),
                'steps' => array_map(fn (array $step) => $step + ['says' => BrowserCheck::say($step)], $check['steps']),
            ], $suite->checks),
            // Signed in once a run, each by one of its checks: never the state it leaves
            'sign_ins' => array_map(fn (array $signIn) => [
                'name' => $signIn['name'],
                'check' => collect($suite->checks)->firstWhere('id', $signIn['check'])['title'] ?? null,
            ], $suite->signIns()),
            'runs_by_itself' => [
                'every' => $suite->settings['every'] ?? 'off',
                'at' => $suite->settings['at'] ?? '09:00',
                'zone' => $suite->settings['zone'] ?? null,
                'next' => $suite->next_run_at?->toIso8601String(),
            ],
            'files_tickets' => (bool) ($suite->settings['tickets'] ?? false),
            // What it waits, in seconds, within the most allowed
            'time_limits' => Limits::of($suite),
            // Which version of its checks these are; each run says which it tested
            'version' => $suite->version,
            // Only the names: a hidden value is never given out
            'hidden_values' => array_column($suite->secret_values ?? [], 'name'),
            'url' => $suite->project_id !== null
                ? route('projects.checks.index', $suite->project)
                : route('checks.index'),
        ];
    }

    /**
     * A run: how it went in a sentence, each check's verdict, and where its
     * pictures and films are.
     *
     * @return array<string, mixed>
     */
    protected function runDescribed(BrowserRun $run, bool $log = false): array
    {
        return [
            'ref_id' => $run->ref_id,
            'state' => $run->state,
            // The version of the suite's checks it tested
            'suite_version' => $run->suite_version,
            'summary' => match (true) {
                $run->ended() => SuiteRun::summary($run),
                $run->state === BrowserRun::WAITING => 'Not started yet. '.SuiteRun::waitingFor($run),
                $run->stopAsked() => 'Stopping after the step in hand: '.count($run->results ?? []).' checks done so far.',
                default => 'Still running: '.count($run->results ?? []).' checks done so far.',
            },
            'speed' => $run->settings['speed'],
            ...($run->trial ? ['try' => 'Only a try: kept nowhere, and taken away an hour after it ends.'] : []),
            'filmed' => (bool) $run->settings['record'],
            'started_by' => $run->started_by,
            'checks' => array_map(fn (array $check) => [
                'title' => $check['title'],
                'passed' => $check['ok'],
                ...(isset($check['sign_in']) ? ['sign_in' => $check['sign_in']] : []),
                'says' => SuiteRun::verdict($check),
                'film' => $check['video']['url'] ?? null,
                // Cut short by a stop: what was filmed before it
                ...(($check['video']['partial'] ?? false) ? ['film_partial' => true] : []),
                'pictures' => array_values(array_filter(array_map(
                    fn (array $step) => is_array($step['shot'] ?? null) ? $step['shot']['url'] : null,
                    $check['steps'],
                ))),
            ], $run->results ?? []),
            'tickets' => array_column($run->tickets ?? [], 'number'),
            'created_at' => $run->created_at?->toIso8601String(),
            // What each step met in the browser, for an admin
            ...($log ? ['log' => $run->log ?? []] : []),
        ];
    }

    /**
     * Whether a run's log is given out: on the admin server, or to a key of
     * one of the app's admins.
     */
    protected function seesLogs(Request $request): bool
    {
        return $this->global || ($request->user() instanceof User && $request->user()->is_admin);
    }

    /** One of the owner's runs, by ref_id. */
    protected function runCalled(Owner $owner, string $refId): BrowserRun
    {
        $run = BrowserRun::query()
            ->where('ref_id', $refId)
            ->whereHas('suite', fn ($query) => $query->where($owner->ownerColumn(), $owner->getKey()))
            ->first();

        if (! $run instanceof BrowserRun) {
            throw ValidationException::withMessages(['ref_id' => "There is no run {$refId} of these suites."]);
        }

        return $run;
    }
}
