<?php

namespace App\Http\Controllers\Knowledge;

use App\Http\Controllers\Concerns\ActsForOwner;
use App\Http\Controllers\Controller;
use App\Jobs\ReadKnowledge;
use App\Models\Connection;
use App\Models\Drive\DriveFolder;
use App\Models\Knowledge\KnowledgeBase;
use App\Models\Note\NoteFolder;
use App\Models\Owner;
use App\Models\Project;
use App\Models\Table\Table;
use App\Support\Ai\AiFailed;
use App\Support\Knowledge\Knowledge;
use App\Support\OwnerUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Knowledge bases: what an AI may answer from -- one's own, or a project's,
 * read by anyone in it and set up by its owners and editors.
 */
class KnowledgeController extends Controller
{
    use ActsForOwner;

    public function index(Request $request): Response
    {
        $owner = $this->owner($request);
        $tables = $owner->tables()->orderBy('title')->get(['id', 'ref_id', 'title']);

        return Inertia::render('knowledge/Index', [
            'bases' => KnowledgeBase::query()->of($owner)->with('connection:id,ref_id')->withCount('chunks')->orderBy('title')->get()
                ->map(fn (KnowledgeBase $base) => self::shown($base, $tables->pluck('title', 'ref_id')->all()))
                ->values(),
            // What a base can read
            'choices' => [
                'notes' => collect(NoteFolder::pathsById($owner->noteFolders()->get(['id', 'parent_id', 'name']), '/'))->sort(SORT_NATURAL | SORT_FLAG_CASE)->values(),
                'tables' => $tables->map(fn (Table $table) => ['ref' => $table->ref_id, 'title' => $table->title ?: 'Untitled table'])->values(),
                'drive' => collect(DriveFolder::pathsById($owner->driveFolders()->get(['id', 'parent_id', 'name']), '/'))->sort(SORT_NATURAL | SORT_FLAG_CASE)->values(),
                'connections' => $owner->connections()->ai()->orderBy('name')->get()
                    ->map(fn (Connection $connection) => [...$connection->only(['ref_id', 'name']), 'label' => $connection->label()])->values(),
            ],
            'can' => ['manage' => ! $owner instanceof Project || Gate::allows('contribute', $owner)],
            'urls' => ['store' => OwnerUrl::to($owner, 'knowledge.store')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $owner = $this->owner($request, 'contribute');
        $validated = $request->validate(['title' => ['required', 'string', 'max:120']]);

        $base = new KnowledgeBase([
            'title' => trim($validated['title']),
            // Every note, to begin with: the most usual thing to answer from
            'sources' => [['kind' => 'notes', 'ref' => '']],
        ]);
        // Being read from the start, so its page follows how that goes
        $base->state = KnowledgeBase::READING;
        $base->ownedBy($owner, $request->user())->save();
        ReadKnowledge::dispatch($base->id);

        return back();
    }

    public function update(Request $request, KnowledgeBase $knowledge): RedirectResponse
    {
        $owner = $this->allowed($request, $knowledge, change: true);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'sources' => ['sometimes', 'array', 'max:'.KnowledgeBase::MOST_SOURCES],
            'sources.*.kind' => ['required', Rule::in(KnowledgeBase::KINDS)],
            'sources.*.ref' => ['present', 'nullable', 'string', 'max:255'],
            'connection' => ['sometimes', 'nullable', 'string', 'max:16'],
            'embed_model' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        if (array_key_exists('title', $validated)) {
            $knowledge->title = trim($validated['title']);
        }

        if (array_key_exists('sources', $validated)) {
            $knowledge->sources = $this->sources($owner, $validated['sources']);
        }

        if (array_key_exists('connection', $validated)) {
            $knowledge->connection_id = $validated['connection'] === null ? null
                : ($owner->connections()->ai()->where('ref_id', $validated['connection'])->value('id')
                    ?? throw ValidationException::withMessages(['connection' => __('Choose one of these AI connections.')]));
        }

        if (array_key_exists('embed_model', $validated)) {
            $knowledge->embed_model = filled($validated['embed_model']) ? trim($validated['embed_model']) : null;
        }

        $reread = $knowledge->isDirty(['sources', 'connection_id', 'embed_model']);

        if ($reread) {
            $knowledge->state = KnowledgeBase::READING;
        }

        $knowledge->save();

        // What it reads, or how, has changed: read it again
        if ($reread) {
            ReadKnowledge::dispatch($knowledge->id);
        }

        return back();
    }

    public function destroy(Request $request, KnowledgeBase $knowledge): RedirectResponse
    {
        $this->allowed($request, $knowledge, change: true);
        $knowledge->delete();

        return back();
    }

    /**
     * Read it again now, rather than within the hour.
     */
    public function read(Request $request, KnowledgeBase $knowledge): RedirectResponse
    {
        $this->allowed($request, $knowledge, change: true);

        $knowledge->forceFill(['state' => KnowledgeBase::READING, 'problem' => null])->save();
        ReadKnowledge::dispatch($knowledge->id);

        return back();
    }

    /**
     * What a question finds in it, to try it out.
     */
    public function ask(Request $request, KnowledgeBase $knowledge, Knowledge $search): JsonResponse
    {
        $this->allowed($request, $knowledge, change: false);
        $validated = $request->validate(['question' => ['required', 'string', 'max:500']]);

        try {
            return response()->json(['found' => $search->find($knowledge, $validated['question'], 5)]);
        } catch (AiFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * The owner of a base the user may look at, or change.
     */
    private function allowed(Request $request, KnowledgeBase $base, bool $change): Owner
    {
        $owner = $base->owner();

        abort_unless(
            $owner instanceof Project
                ? Gate::allows($change ? 'contribute' : 'view', $owner)
                : $base->user_id === $request->user()->id,
            403,
        );

        return $owner;
    }

    /**
     * Sources as given, each one of the owner's: a folder's path for notes
     * and Drive files ("" for all of them), a table's ref_id.
     *
     * @param  list<array{kind: string, ref: string|null}>  $given
     * @return list<array{kind: string, ref: string}>
     */
    private function sources(Owner $owner, array $given): array
    {
        $sources = [];

        foreach ($given as $source) {
            $ref = trim((string) $source['ref'], "/ \t");

            if ($source['kind'] === 'table' && ! $owner->tables()->where('ref_id', $ref)->exists()) {
                throw ValidationException::withMessages(['sources' => __('Choose one of these tables.')]);
            }

            // The same one twice is read once, as it was first named
            $sources[$source['kind'].':'.mb_strtolower($ref)] ??= ['kind' => $source['kind'], 'ref' => $ref];
        }

        return array_values($sources);
    }

    /**
     * @param  array<string, string>  $tables  titles by ref_id
     * @return array<string, mixed>
     */
    private static function shown(KnowledgeBase $base, array $tables): array
    {
        return [
            'ref_id' => $base->ref_id,
            'title' => $base->title,
            'sources' => array_map(fn (array $source) => [
                ...$source,
                'label' => match ($source['kind']) {
                    'table' => 'Table: '.($tables[$source['ref']] ?? 'gone'),
                    'drive' => 'Drive: '.($source['ref'] ?: 'all files'),
                    default => 'Notes: '.($source['ref'] ?: 'all notes'),
                },
            ], $base->sources ?? []),
            'connection' => $base->connection?->ref_id,
            'embed_model' => $base->embed_model,
            'state' => $base->state,
            'problem' => $base->problem,
            'read_at' => $base->read_at,
            'passages' => $base->chunks_count,
        ];
    }
}
