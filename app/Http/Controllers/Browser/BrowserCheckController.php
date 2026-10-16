<?php

namespace App\Http\Controllers\Browser;

use App\Http\Controllers\Concerns\ActsForOwner;
use App\Http\Controllers\Controller;
use App\Jobs\RunBrowserChecks;
use App\Models\Browser\BrowserRun;
use App\Models\Browser\BrowserSuite;
use App\Models\Browser\BrowserSuiteVersion;
use App\Models\Drive\DriveFolder;
use App\Models\Owner;
use App\Models\Project;
use App\Models\User;
use App\Support\Browser\BrowserCheck;
use App\Support\Browser\Limits;
use App\Support\Browser\LivePages;
use App\Support\Browser\SuiteRun;
use App\Support\Browser\SuiteVersions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Browser checks: suites of checks of any public site -- a person's own, or
 * a project's, such as a customer's -- built by clicking through its pages,
 * run quick to test it or slowed and filmed to show it, on a timetable too,
 * their pictures and films kept in the Drive. (The app's own test runs are
 * TestRunController's, on an admin page of their own.)
 */
class BrowserCheckController extends Controller
{
    use ActsForOwner;

    public function show(Request $request): Response
    {
        $owner = $this->owner($request);
        $user = $request->user();

        return Inertia::render('checks/Index', [
            'space' => $owner instanceof Project ? $owner->name : null,
            'spaces' => $this->spaces($user, $owner),
            'canChange' => $this->canChange($user, $owner),
            'suites' => $owner->browserSuites()->latest('updated_at')->get()
                ->map(fn (BrowserSuite $suite) => $this->summary($suite))->all(),
            'newSuite' => self::ownerRoute($owner, 'checks.suites.store'),
            'speeds' => BrowserCheck::SPEEDS,
            'limits' => $this->limits($user),
        ]);
    }

    /**
     * Sets the most anyone's browser checks may wait, in seconds -- for the
     * app's admins alone. Each suite asking for more is held to it.
     */
    public function setLimits(Request $request): JsonResponse
    {
        Gate::authorize('admin');

        $validated = $request->validate([
            'step' => ['required', 'integer', 'between:'.Limits::FLOOR['step'].','.Limits::CEILING['step']],
            'check' => ['required', 'integer', 'between:'.Limits::FLOOR['check'].','.Limits::CEILING['check']],
        ]);
        Limits::setMost(['step' => (int) $validated['step'], 'check' => (int) $validated['check']]);

        return response()->json($this->limits($request->user()));
    }

    /**
     * How long checks may wait, in seconds: what a suite waits that sets none,
     * the most one may set, and -- for an admin, who sets that most -- the
     * bounds it is set within.
     *
     * @return array<string, mixed>
     */
    private function limits(User $user): array
    {
        return [
            'default' => Limits::DEFAULT,
            'most' => Limits::most(),
            ...($user->can('admin') ? ['floor' => Limits::FLOOR, 'ceiling' => Limits::CEILING, 'set' => route('admin.checks.limits')] : []),
        ];
    }

    public function storeSuite(Request $request): JsonResponse
    {
        $owner = $this->owner($request, 'contribute');
        $validated = $request->validate(['title' => ['required', 'string', 'max:200']]);

        // Empty: its first check is best built by clicking through the page
        $suite = (new BrowserSuite([
            'title' => $validated['title'],
            'checks' => [],
        ]))->ownedBy($owner, $request->user());
        $suite->save();

        return response()->json($this->full($suite), 201);
    }

    public function showSuite(BrowserSuite $suite): JsonResponse
    {
        Gate::authorize('view', $suite);

        return response()->json($this->full($suite));
    }

    /**
     * The versions of a suite's checks, newest first: when, by whom, how
     * many checks and steps, and how many runs tested each.
     */
    public function versions(BrowserSuite $suite): JsonResponse
    {
        Gate::authorize('view', $suite);

        $runs = $suite->runs()->whereNotNull('suite_version')->selectRaw('suite_version, count(*) as n')->groupBy('suite_version')->pluck('n', 'suite_version');

        return response()->json([
            'current' => $suite->version,
            'versions' => $suite->versions()->with('creator:id,name')->orderByDesc('version')->limit(50)->get()
                ->map(fn (BrowserSuiteVersion $version) => [
                    'version' => $version->version,
                    'at' => $version->updated_at,
                    'by' => $version->creator?->name,
                    'checks' => count($version->checks),
                    'steps' => array_sum(array_map(fn (array $check) => count($check['steps'] ?? []), $version->checks)),
                    'titles' => array_column($version->checks, 'title'),
                    'runs' => (int) ($runs[$version->version] ?? 0),
                    'restore' => route('checks.suites.versions.restore', [$suite, $version->version]),
                ])->all(),
        ]);
    }

    /** Puts a suite's checks back as an earlier version had them, as a new version. */
    public function restoreVersion(BrowserSuite $suite, int $version): JsonResponse
    {
        Gate::authorize('update', $suite);

        SuiteVersions::restore($suite, $suite->versions()->where('version', $version)->firstOrFail());

        return response()->json($this->full($suite->refresh()));
    }

    /** What changed of a suite: its name, its checks. Kept as it is written. */
    public function updateSuite(Request $request, BrowserSuite $suite): JsonResponse
    {
        Gate::authorize('update', $suite);

        $suite->take($request->validate(BrowserSuite::rules()))->save();

        return response()->json(['updated_at' => $suite->updated_at, 'next_run_at' => $suite->next_run_at]);
    }

    public function destroySuite(BrowserSuite $suite): JsonResponse
    {
        Gate::authorize('delete', $suite);
        $suite->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * The page a check's first steps lead to, and what is on it, for picking
     * what the next step is about rather than guessing at its words.
     */
    public function look(Request $request, BrowserSuite $suite): JsonResponse
    {
        Gate::authorize('update', $suite);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'size' => ['nullable', Rule::in(BrowserCheck::SIZES)],
            ...BrowserCheck::stepRules('steps', complete: false),
        ]);
        $steps = $validated['steps'];

        if ($steps[0]['do'] !== 'open') {
            throw ValidationException::withMessages(['steps' => 'Start the check with "Open a page", so there is a page to pick from.']);
        }

        $missing = BrowserCheck::unready(['title' => (string) ($validated['title'] ?? ''), 'steps' => $steps]);

        if ($missing !== null) {
            throw ValidationException::withMessages(['steps' => "To get to the page -- {$missing}."]);
        }

        try {
            return response()->json(BrowserCheck::look($steps, $suite->valuesFor($steps), $validated['size'] ?? 'desktop'));
        } catch (RuntimeException $problem) {
            return response()->json(['message' => $problem->getMessage()], 502);
        }
    }

    /**
     * Opens a page in the browser and keeps it open while a check is built by
     * clicking through it: the check's steps so far done on it first, then
     * each next one once, there. Only whoever opened it can go on with it.
     */
    public function openLive(Request $request, BrowserSuite $suite): JsonResponse
    {
        Gate::authorize('update', $suite);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'size' => ['nullable', Rule::in(BrowserCheck::SIZES)],
            ...BrowserCheck::stepRules('steps', complete: false),
        ]);

        try {
            return response()->json(LivePages::open($request->user(), $suite, $validated['steps'], $validated['size'] ?? 'desktop', title: (string) ($validated['title'] ?? '')));
        } catch (RuntimeException $problem) {
            return response()->json(['message' => $problem->getMessage()], 502);
        }
    }

    /** One more step on a live page: what came of it, and the page now. */
    public function stepLive(Request $request, string $live): JsonResponse
    {
        Gate::authorize('update', $this->liveSuite($request, $live));
        $validated = validator(['steps' => [$request->input('step')]], BrowserCheck::stepRules('steps'))->validate();

        try {
            return response()->json(LivePages::step($request->user(), $live, $validated['steps'][0]));
        } catch (RuntimeException $problem) {
            return response()->json(['message' => $problem->getMessage()], 502);
        }
    }

    public function closeLive(Request $request, string $live): JsonResponse
    {
        $this->liveSuite($request, $live);
        LivePages::close($request->user(), $live);

        return response()->json(['ok' => true]);
    }

    /** The suite a live page is being built for, when the one asking opened it. */
    private function liveSuite(Request $request, string $live): BrowserSuite
    {
        try {
            return LivePages::suiteOf($request->user(), $live);
        } catch (ValidationException) {
            abort(404, 'That page has been closed: open it again.');
        }
    }

    /** Starts a run of a suite's checks, or of the ones named. */
    public function startRun(Request $request, BrowserSuite $suite): JsonResponse
    {
        Gate::authorize('update', $suite);

        $validated = $request->validate([
            ...BrowserCheck::settingRules(),
            'checks' => ['present', 'array'],
            'checks.*' => ['string', Rule::in(array_column($suite->checks, 'id'))],
            // Only a try: followed as any run, then kept nowhere
            'trial' => ['sometimes', 'boolean'],
        ]);

        if ($suite->checks === []) {
            throw ValidationException::withMessages(['checks' => 'Add a check first: there is nothing to run yet.']);
        }

        // Each check that is to run, whole: what it lacks said plainly
        foreach ($suite->checks as $check) {
            if ($validated['checks'] === [] || in_array($check['id'], $validated['checks'], true)) {
                $missing = BrowserCheck::unready($check);

                if ($missing !== null) {
                    throw ValidationException::withMessages(['checks' => "Before it can run -- {$missing}."]);
                }
            }
        }

        $run = SuiteRun::start($suite, $request->user(), SuiteRun::settings(
            $validated['speed'],
            $validated['pace'] ?? null,
            $request->boolean('captions'),
            $request->boolean('record'),
            $request->boolean('keep'),
            $validated['checks'],
            $request->boolean('one_film'),
            // Left out: cut, as a film usually wants
            $request->boolean('cut_waits', true),
        ), trial: $request->boolean('trial'));

        RunBrowserChecks::dispatch($run->id);

        return response()->json($this->runFull($run->refresh()), 201);
    }

    public function showRun(BrowserRun $browserRun): JsonResponse
    {
        Gate::authorize('view', $browserRun->suite);

        return response()->json($this->runFull($browserRun));
    }

    /**
     * Stops a run part way: one still waiting never starts, one running ends
     * after the step in hand. Whoever may start a run may stop one.
     */
    public function stopRun(Request $request, BrowserRun $browserRun): JsonResponse
    {
        Gate::authorize('update', $browserRun->suite);

        if ($browserRun->ended()) {
            throw ValidationException::withMessages(['run' => 'This run has already ended.']);
        }

        return response()->json($this->runFull(SuiteRun::stop($browserRun, $request->user())));
    }

    /**
     * Where suites can be kept: one's own space, and each project one is in.
     *
     * @return list<array{label: string, url: string, current: bool}>
     */
    private function spaces(User $user, Owner $owner): array
    {
        return [
            ['label' => 'Your own', 'url' => route('checks.index'), 'current' => $owner instanceof User],
            ...$user->projects()->orderBy('name')->get()->map(fn (Project $project) => [
                'label' => $project->name,
                'url' => route('projects.checks.index', $project),
                'current' => $owner instanceof Project && $owner->is($project),
            ])->all(),
        ];
    }

    private function canChange(User $user, Owner $owner): bool
    {
        return ! $owner instanceof Project || $user->can('contribute', $owner);
    }

    /** @return array<string, mixed> */
    private function summary(BrowserSuite $suite): array
    {
        // The last few, oldest first: how it has been going, at a glance
        $recent = $suite->runs()->latest('id')->limit(10)->get(['id', 'state', 'created_at']);

        return [
            'ref_id' => $suite->ref_id,
            'title' => $suite->title,
            'checks' => count($suite->checks),
            'last' => $recent->first()?->state,
            'last_at' => $recent->first()?->created_at,
            'recent' => $recent->reverse()->pluck('state')->values()->all(),
            'url' => route('checks.suites.show', $suite),
        ];
    }

    private function suiteFolder(BrowserSuite $suite): ?string
    {
        $folder = $suite->runs()->whereNotNull('drive_folder_id')->latest('id')->first()?->driveFolder?->parent;

        return $folder instanceof DriveFolder
            ? self::ownerRoute($suite->owner(), 'drive.index', ['folder' => $folder->ref_id])
            : null;
    }

    /** @return array<string, mixed> */
    private function full(BrowserSuite $suite): array
    {
        $runs = $suite->runs()->latest('id')->limit(20)->get();

        return [
            'ref_id' => $suite->ref_id,
            'title' => $suite->title,
            'checks' => $suite->checks,
            'settings' => [
                'every' => $suite->settings['every'] ?? 'off',
                'at' => $suite->settings['at'] ?? '09:00',
                'zone' => $suite->settings['zone'] ?? null,
                'tickets' => (bool) ($suite->settings['tickets'] ?? false),
            ],
            // Its own, in seconds; null for the default
            'limits' => [
                'step' => $suite->settings['limits']['step'] ?? null,
                'check' => $suite->settings['limits']['check'] ?? null,
            ],
            'project' => $suite->project_id !== null,
            // Which version of its checks these are, and where to see the others
            'version' => $suite->version,
            // Its folder in the Drive -- where its runs' films and pictures
            // are kept -- found by its latest kept run, so a renamed suite
            // still finds the one it has; null until a run keeps anything
            'folder' => $this->suiteFolder($suite),
            'next_run_at' => $suite->next_run_at,
            // Who a run signs in as, once, and by which check (by its id)
            'sign_ins' => $suite->signIns(),
            // Only their names: a hidden value is never sent back
            'values' => array_map(fn (array $value) => ['name' => $value['name'], 'set' => $value['value'] !== ''], $suite->secret_values ?? []),
            'runs' => $runs->map(fn (BrowserRun $run) => [
                'ref_id' => $run->ref_id,
                'state' => $run->state,
                'started_by' => $run->started_by,
                'speed' => $run->settings['speed'],
                'record' => $run->settings['record'],
                'created_at' => $run->created_at,
                'took' => $run->started_at && $run->ended_at ? (int) $run->started_at->diffInMilliseconds($run->ended_at) : null,
                'passed' => count(array_filter($run->results ?? [], fn (array $check) => $check['ok'] ?? false)),
                'total' => count($run->results ?? []),
                'url' => route('checks.runs.show', $run),
            ])->all(),
            // How each check did the last time it ran
            'latest' => $runs->reduce(function (array $seen, BrowserRun $run) {
                foreach ($run->results ?? [] as $check) {
                    // Not run, for a sign-in that failed: it says nothing of the check
                    if (! ($check['not_run'] ?? false)) {
                        $seen[$check['id']] ??= ['ok' => (bool) $check['ok'], 'took' => (int) ($check['took'] ?? 0), 'at' => $run->created_at];
                    }
                }

                return $seen;
            }, []),
            'urls' => [
                'update' => route('checks.suites.update', $suite),
                'runs' => route('checks.runs.store', $suite),
                'look' => route('checks.suites.look', $suite),
                'live' => route('checks.suites.live', $suite),
                'versions' => route('checks.suites.versions', $suite),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function runFull(BrowserRun $run): array
    {
        return [
            'ref_id' => $run->ref_id,
            'state' => $run->state,
            // Only a try: kept nowhere, and taken away a while after it ends
            'trial' => (bool) $run->trial,
            'settings' => $run->settings,
            'results' => $run->results ?? [],
            'created_at' => $run->created_at,
            'started_at' => $run->started_at,
            'ended_at' => $run->ended_at,
            'by' => $run->creator?->name,
            'started_by' => $run->started_by,
            // Why it has not started yet, while it waits
            'waiting_for' => SuiteRun::waitingFor($run),
            // Asked to stop, and by whom; once stopped, where
            'stop_asked' => $run->stopAsked() && ! $run->ended(),
            'stopped' => $run->state === BrowserRun::STOPPED ? SuiteRun::stoppedAt($run) : null,
            // What each step met in the browser: for the app's admins alone
            'log' => Gate::allows('admin') ? ($run->log ?? []) : null,
            // Filed in the suite's project, where its tickets are
            'tickets' => $run->suite->project !== null ? array_map(fn (array $ticket) => $ticket + [
                'url' => route('projects.tickets.index', $run->suite->project),
            ], $run->tickets ?? []) : [],
            'folder' => $run->drive_folder_id !== null
                ? self::ownerRoute($run->suite->owner(), 'drive.index', ['folder' => $run->driveFolder?->ref_id])
                : null,
            // The suite's, the folder this run's is in: the page's way there
            // from the first run that keeps anything
            'suite_folder' => $run->drive_folder_id !== null ? $this->suiteFolder($run->suite) : null,
            'url' => route('checks.runs.show', $run),
            'stop' => route('checks.runs.stop', $run),
        ];
    }
}
