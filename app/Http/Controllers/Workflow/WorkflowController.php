<?php

namespace App\Http\Controllers\Workflow;

use App\Http\Controllers\Concerns\BrowsesFolders;
use App\Http\Controllers\Concerns\SavesRevisions;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Project;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowFolder;
use App\Models\Workflow\WorkflowListener;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Audit\Audit;
use App\Support\Workflow\Choices;
use App\Support\Workflow\Definition;
use App\Support\Workflow\DefinitionInvalid;
use App\Support\Workflow\Health;
use App\Support\Workflow\Listeners;
use App\Support\Workflow\NodeTypes;
use App\Support\Workflow\References;
use App\Support\Workflow\Templates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkflowController extends Controller
{
    use BrowsesFolders, SavesRevisions;

    /**
     * Browse a folder of the user's or the project's workflows (the top level
     * when none is given), or search every folder when there is a query.
     */
    public function index(Request $request): Response
    {
        // What the "From a template" dialog offers
        Inertia::share('templates', fn () => collect(Templates::all())->map(fn (array $template, string $key) => [
            'key' => $key,
            ...collect($template)->only(['title', 'description', 'needs'])->all(),
        ])->values());
        // How its workflows have been doing, at the top of the list
        Inertia::share('health', function () use ($request) {
            $owner = $this->owner($request);

            return Health::of($owner instanceof Project ? $owner : $request->user());
        });
        Inertia::share('ownConnections', fn () => $this->owner($request)->connections()->orderBy('name')->get(['ref_id', 'name', 'service'])
            ->map(fn (Connection $connection) => [...$connection->only(['ref_id', 'name', 'service']), 'ai' => $connection->isAi()])->values());

        return $this->browse(
            $request,
            'workflows/Index',
            'workflows',
            $this->owner($request)->workflows()->with('publishedVersion:id,revision,number'),
            ['title'],
            fn (Workflow $workflow) => [
                'steps' => count($workflow->definition['nodes'] ?? []),
                'published' => $workflow->publishedVersion?->number,
                'unpublished_changes' => $workflow->published_version_id !== null && $workflow->hasUnpublishedChanges(),
            ],
        );
    }

    /**
     * Start a workflow -- something to start it, and nothing after yet; or a
     * template, with the connections it needs -- in a folder when one is
     * given, and open it.
     */
    public function store(Request $request): RedirectResponse
    {
        $owner = $this->owner($request, 'contribute');

        $validated = $request->validate([
            'folder' => ['nullable', 'string', self::ownFolder($owner)],
            'title' => ['nullable', 'string', 'max:255'],
            'template' => ['nullable', Rule::in(array_keys(Templates::all()))],
            'connections' => ['nullable', 'array'],
            'connections.*' => ['string'],
        ]);

        $template = null;

        if (filled($validated['template'] ?? null)) {
            $chosen = $validated['connections'] ?? [];
            $template = Templates::all($chosen)[$validated['template']];

            foreach ($template['needs'] as $service => $label) {
                if (References::connection($owner, (string) ($chosen[$service] ?? ''), [$service]) === null) {
                    throw ValidationException::withMessages(["connections.{$service}" => "Choose a {$label} of your own."]);
                }
            }
        }

        $workflow = (new Workflow)->ownedBy($owner, $request->user());
        $workflow->folder_id = self::folderId($validated['folder'] ?? null);
        $workflow->title = trim((string) ($validated['title'] ?? '')) ?: ($template['title'] ?? __('New workflow'));
        $workflow->definition = $template['definition'] ?? null;
        $workflow->save();
        Audit::record($workflow, 'workflow.created', $request->user(), $template ? ['template' => $template['title']] : []);

        return to_route('workflows.show', $workflow);
    }

    /**
     * Open the workflow in the editor.
     */
    public function show(Workflow $workflow): Response
    {
        Gate::authorize('view', $workflow);

        $workflow->loadMissing(['publishedVersion.creator:id,name', 'editor:id,name']);

        return Inertia::render('workflows/Show', [
            'workflow' => [
                ...$workflow->only(['ref_id', 'title', 'definition', 'revision', 'enabled', 'updated_at']),
                'edited_by' => $workflow->editor?->name,
                ...$this->publishing($workflow),
                'problems' => References::allProblems($workflow),
                'can' => ['update' => Gate::allows('update', $workflow)],
            ],
            'nodeTypes' => NodeTypes::shown(),
            'options' => $this->options($workflow),
            'listening' => $this->listening($workflow),
            // Who published it, switched it on, ran it by hand
            'activity' => Audit::of($workflow),
            'groups' => NodeTypes::groups(),
            'sections' => NodeTypes::sections(),
            'breadcrumbs' => self::crumbs($workflow->folder),
        ]);
    }

    /**
     * Save the draft's title and steps, or move it to another folder.
     *
     * The editor says which revision it last saw. When someone has saved since,
     * this save was made from an older copy: it is refused (409) with what is
     * there now, rather than quietly undoing their change. A draft may be
     * unfinished; one that isn't a workflow at all is refused (422).
     */
    public function update(Request $request, Workflow $workflow): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $workflow);

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'definition' => ['sometimes', 'array'],
            'folder' => ['sometimes', 'nullable', 'string', self::ownFolder($workflow->owner())],
            'revision' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($stale = $this->stale($workflow, $validated['revision'] ?? null)) {
            return $stale;
        }

        if (array_key_exists('title', $validated)) {
            $workflow->title = trim((string) $validated['title']);
        }

        if (array_key_exists('folder', $validated)) {
            $workflow->folder_id = self::folderId($validated['folder']);
        }

        try {
            if (array_key_exists('definition', $validated)) {
                $workflow->definition = Definition::normalize($validated['definition']);
            }
        } catch (DefinitionInvalid $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['definition' => $e->errors]], 422);
        }

        $workflow->save();

        // The editor saves with fetch; the list moves workflows through Inertia
        if (! $request->expectsJson()) {
            return back();
        }

        return response()->json([
            ...$workflow->only(['revision', 'updated_at']),
            'definition' => $workflow->definition,
            'problems' => References::allProblems($workflow),
            ...$this->publishing($workflow),
        ]);
    }

    /**
     * Publish the draft as it was last saved: it becomes what runs on its own.
     * Refused while anything stands in the way, with what does.
     */
    public function publish(Request $request, Workflow $workflow): JsonResponse
    {
        Gate::authorize('update', $workflow);

        $validated = $request->validate(['revision' => ['required', 'integer', 'min:0']]);

        if ($stale = $this->stale($workflow, $validated['revision'])) {
            return $stale;
        }

        $problems = References::allProblems($workflow);

        if ($problems !== []) {
            return response()->json([
                'message' => __('Fix what stands in the way first.'),
                'problems' => $problems,
            ], 422);
        }

        if (! $workflow->hasUnpublishedChanges()) {
            return response()->json($this->publishing($workflow));
        }

        $workflow->freeze(WorkflowVersion::PUBLISHED, $request->user());
        $workflow->publishedVersion?->setRelation('creator', $request->user());
        Audit::record($workflow, 'workflow.published', $request->user(), ['version' => $workflow->publishedVersion?->number]);

        // A switched-on workflow listens with what was just published
        Listeners::rebuild($workflow);

        return response()->json($this->publishing($workflow));
    }

    /**
     * Switch the workflow on -- its triggers start runs by themselves, as
     * published -- or off. Only something published can be switched on.
     */
    public function enable(Request $request, Workflow $workflow): JsonResponse
    {
        Gate::authorize('update', $workflow);

        $validated = $request->validate(['enabled' => ['required', 'boolean']]);

        if ($validated['enabled'] && $workflow->published_version_id === null) {
            return response()->json(['message' => __('Publish it first: what is switched on is what is published.')], 422);
        }

        // Not a change to the draft: neither a new revision nor an editor
        $workflow->newQuery()->whereKey($workflow->id)->update(['enabled' => $validated['enabled']]);
        $workflow->enabled = $validated['enabled'];
        $workflow->syncOriginalAttribute('enabled');

        Listeners::rebuild($workflow);
        Audit::record($workflow, $workflow->enabled ? 'workflow.enabled' : 'workflow.disabled', $request->user());

        return response()->json(['enabled' => $workflow->enabled, 'listening' => $this->listening($workflow)]);
    }

    /**
     * Delete the workflow, with every version of it.
     */
    public function destroy(Request $request, Workflow $workflow): RedirectResponse
    {
        Gate::authorize('delete', $workflow);

        Audit::record($workflow, 'workflow.deleted', $request->user());
        $owner = $workflow->owner();
        $folder = $workflow->folder;
        $workflow->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workflow deleted.')]);

        return redirect(self::ownerRoute($owner, 'workflows.index', $folder ? ['folder' => $folder->ref_id] : []));
    }

    protected static function folderModel(): string
    {
        return WorkflowFolder::class;
    }

    /**
     * What the steps' settings may choose from: the owner's connections,
     * chats and tables -- names only, never a secret -- and where a webhook
     * is reached from outside.
     *
     * @return array<string, mixed>
     */
    private function options(Workflow $workflow): array
    {
        return Choices::for($workflow->owner(), $workflow);
    }

    /**
     * What the switched-on workflow listens for now, step by step.
     *
     * @return list<array{node_id: string, kind: string}>
     */
    private function listening(Workflow $workflow): array
    {
        return array_values(WorkflowListener::where('workflow_id', $workflow->id)->get(['node_id', 'kind'])
            ->map(fn (WorkflowListener $listener) => $listener->only(['node_id', 'kind']))->all());
    }

    /**
     * What is published, and whether the draft has moved on since.
     *
     * @return array{published: array{number: int, at: mixed, by: string|null}|null, unpublished_changes: bool}
     */
    private function publishing(Workflow $workflow): array
    {
        $version = $workflow->publishedVersion;

        return [
            'published' => $version === null ? null : [
                'number' => $version->number,
                'at' => $version->created_at,
                'by' => $version->creator?->name,
            ],
            'unpublished_changes' => $workflow->hasUnpublishedChanges(),
        ];
    }

    private function stale(Workflow $workflow, ?int $seen): ?JsonResponse
    {
        return $this->staleSave($workflow, $seen, 'workflow', ['title', 'definition'], __('Someone else changed this workflow since you opened it.'));
    }
}
