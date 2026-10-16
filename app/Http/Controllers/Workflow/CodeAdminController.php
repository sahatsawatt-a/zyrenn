<?php

namespace App\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use App\Models\Workflow\CodeLibrary;
use App\Support\Workflow\Code\CodeLibraries;
use App\Support\Workflow\Code\CodeSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What workflows' "Run code" may do, as the app's admins set it: the
 * libraries each language may use, whether Python may be written at all,
 * the most seconds a step runs, and Python's memory. For admins only.
 */
class CodeAdminController extends Controller
{
    public function page(): Response
    {
        Gate::authorize('admin');

        return Inertia::render('admin/Code', [
            'settings' => CodeSettings::get(),
            'limits' => ['ceiling' => CodeSettings::CEILING_SECONDS, 'memory' => CodeSettings::PYTHON_MEMORY_RANGE],
            'libraries' => CodeLibrary::query()->with('adder:id,name')->orderBy('language')->orderBy('name')->get()
                ->map(fn (CodeLibrary $library) => $library->summary())->values(),
        ]);
    }

    public function settings(Request $request): RedirectResponse
    {
        Gate::authorize('admin');

        $valid = $request->validate([
            'python' => ['required', 'boolean'],
            'seconds.javascript' => ['required', 'integer', 'min:1', 'max:'.CodeSettings::CEILING_SECONDS['javascript']],
            'seconds.python' => ['required', 'integer', 'min:1', 'max:'.CodeSettings::CEILING_SECONDS['python']],
            'python_memory' => ['required', 'integer', 'min:'.CodeSettings::PYTHON_MEMORY_RANGE[0], 'max:'.CodeSettings::PYTHON_MEMORY_RANGE[1]],
        ]);

        CodeSettings::set([
            'python' => (bool) $valid['python'],
            'seconds' => ['javascript' => (int) $valid['seconds']['javascript'], 'python' => (int) $valid['seconds']['python']],
            'python_memory' => (int) $valid['python_memory'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);

        return back();
    }

    /**
     * Add a library, or ask again for one there: installed in the background.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('admin');

        $valid = $request->validate([
            'language' => ['required', Rule::in(CodeLibrary::LANGUAGES)],
            'name' => ['required', 'string', 'max:214'],
            'version' => ['nullable', 'string', 'max:64'],
        ]);

        CodeLibraries::add($valid['language'], $valid['name'], $valid['version'] ?? null, $request->user());

        return back();
    }

    /**
     * Switch one on or off: off, no step can use it, and steps that do stop with a problem.
     */
    public function update(Request $request, CodeLibrary $library): RedirectResponse
    {
        Gate::authorize('admin');

        $library->update($request->validate(['enabled' => ['required', 'boolean']]));

        return back();
    }

    public function destroy(CodeLibrary $library): RedirectResponse
    {
        Gate::authorize('admin');

        $library->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name taken away.', ['name' => $library->name])]);

        return back();
    }
}
