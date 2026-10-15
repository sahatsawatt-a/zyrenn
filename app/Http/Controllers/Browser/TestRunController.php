<?php

namespace App\Http\Controllers\Browser;

use App\Http\Controllers\Controller;
use App\Support\Browser\BrowserTests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The app's own browser suites (tests/e2e), run from a page of their own and
 * followed as they go -- for its admins alone, since they are about the app
 * itself, and apart from anyone's browser checks. The runner is on this machine (node tests/e2e/run.mjs --listen):
 * this only asks it for a run and reads what it writes down.
 */
class TestRunController extends Controller
{
    public function page(): Response
    {
        Gate::authorize('admin');

        return Inertia::render('admin/TestRuns', ['initial' => self::state()]);
    }

    public function index(): JsonResponse
    {
        Gate::authorize('admin');

        return response()->json($this->state());
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $listener = BrowserTests::listener();
        abort_if($listener === null, 409, 'The test runner is not waiting: start it with node tests/e2e/run.mjs --listen');

        $validated = $request->validate([
            'suites' => ['present', 'array'],
            'suites.*' => ['string', Rule::in($listener['suites'])],
            'lanes' => ['required', 'integer', 'between:1,3'],
            'watch' => ['boolean'],
        ]);

        return response()->json([
            'id' => BrowserTests::ask($validated['suites'], $validated['lanes'], $request->boolean('watch')),
        ]);
    }

    public function show(string $run): JsonResponse
    {
        Gate::authorize('admin');

        return response()->json(BrowserTests::run($run) ?? abort(404));
    }

    public function stop(string $run): JsonResponse
    {
        Gate::authorize('admin');
        BrowserTests::stop($run);

        return response()->json(['ok' => true]);
    }

    public function shot(string $run, string $suite, string $file): BinaryFileResponse
    {
        Gate::authorize('admin');

        return response()->file(BrowserTests::shot($run, $suite, $file) ?? abort(404));
    }

    /** @return array{listener: array<string, mixed>|null, runs: list<array<string, mixed>>} */
    public static function state(): array
    {
        return ['listener' => BrowserTests::listener(), 'runs' => BrowserTests::runs()];
    }
}
