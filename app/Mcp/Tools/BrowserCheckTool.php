<?php

namespace App\Mcp\Tools;

use App\Models\Browser\BrowserRun;
use App\Models\Browser\BrowserSuite;
use App\Models\Drive\DriveFolder;
use App\Models\Owner;
use App\Support\Browser\BrowserCheck;
use App\Support\Browser\SuiteRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;

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
            'target' => $schema->string()->max(200)->description('For "click", "hover", "type", "choose", "upload", "see" and "gone": what it says on the page (look-at-page lists the names). For "scroll": what to scroll to, or none for a screen further down.'),
            'text' => $schema->string()->max(2000)->description('For "type": what to type; {{name}} for a hidden value. For "choose": the option to choose. For "address": part of the address the page must be at. For "upload": the file\'s name.'),
            'key' => $schema->string()->max(30)->description('For "press": Enter, Tab, Escape, Backspace, ArrowDown, ArrowUp or Space.'),
            'selector' => $schema->string()->max(1000)->description('For a thing with no words of its own (a picture), its "selector" as look-at-page gives it.'),
            'nth' => $schema->integer()->min(1)->max(50)->description('Which of several sharing the name: 3 for the 3rd "Add to cart".'),
            'frame' => $schema->string()->max(1000)->description('For a thing inside a frame of the page (a card form), its "frame" as look-at-page gives it.'),
            'file' => $schema->string()->max(40)->description('For "upload": the ref_id of the Drive file to add (list-drive finds it).'),
            'note' => $schema->string()->max(600)->description('For any step: what to tell someone watching it, a few sentences at most -- how to do this in the app, why it matters. A demo video captions only steps with a note, in these words.'),
            'wait' => $schema->number()->min(0)->max(30)->description('For any step: how many seconds a demo video stays on it after it is done (0 to 30, e.g. 4.5) -- in place of its note\'s own time (3 to 8 s by its length) or, with no note, its pause. 0 goes straight on. Left out, the defaults.'),
        ]);
    }

    /**
     * One of a suite's checks, by its name.
     *
     * @return array{id: string, title: string, size?: string, steps: list<array<string, string>>}
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
     * @param  array{id: string, title: string, size?: string, steps: list<array<string, string>>}  $check
     * @return array<string, mixed>
     */
    protected function checkDescribed(array $check): array
    {
        return [
            'title' => $check['title'],
            'size' => $check['size'] ?? 'desktop',
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
                'steps' => array_map(fn (array $step) => $step + ['says' => BrowserCheck::say($step)], $check['steps']),
            ], $suite->checks),
            'runs_by_itself' => [
                'every' => $suite->settings['every'] ?? 'off',
                'at' => $suite->settings['at'] ?? '09:00',
                'zone' => $suite->settings['zone'] ?? null,
                'next' => $suite->next_run_at?->toIso8601String(),
            ],
            'files_tickets' => (bool) ($suite->settings['tickets'] ?? false),
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
    protected function runDescribed(BrowserRun $run): array
    {
        return [
            'ref_id' => $run->ref_id,
            'state' => $run->state,
            'summary' => $run->ended() ? SuiteRun::summary($run) : 'Still running: '.count($run->results ?? []).' checks done so far.',
            'speed' => $run->settings['speed'],
            'filmed' => (bool) $run->settings['record'],
            'started_by' => $run->started_by,
            'checks' => array_map(fn (array $check) => [
                'title' => $check['title'],
                'passed' => $check['ok'],
                'says' => SuiteRun::verdict($check),
                'film' => $check['video']['url'] ?? null,
                'pictures' => array_values(array_filter(array_map(
                    fn (array $step) => is_array($step['shot'] ?? null) ? $step['shot']['url'] : null,
                    $check['steps'],
                ))),
            ], $run->results ?? []),
            'tickets' => array_column($run->tickets ?? [], 'number'),
            'created_at' => $run->created_at?->toIso8601String(),
        ];
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
