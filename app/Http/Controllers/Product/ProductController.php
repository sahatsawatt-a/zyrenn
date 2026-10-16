<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Models\Agent\Agent;
use App\Models\Chat\ChatRoom;
use App\Models\Product\ProductElement;
use App\Models\Product\ProductLink;
use App\Models\Product\ProductRequest;
use App\Models\Product\Requirement;
use App\Models\Project;
use App\Models\Ticket\Ticket;
use App\Support\Ai\AiFailed;
use App\Support\Product\Analyst;
use App\Support\Product\Keeper;
use App\Support\Product\Tracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A project's product, tracked: its tree of parts, what was asked of it,
 * what it must do, and the work and coverage behind them. Anyone in the
 * project reads it; its owners and editors keep it.
 */
class ProductController extends Controller
{
    public function index(Request $request, Project $project): Response
    {
        return Inertia::render('product/Index', [
            'elements' => Tracker::tree($project),
            'links' => $project->productElements()->join('product_links', 'product_links.from_id', '=', 'product_elements.id')
                ->join('product_elements as targets', 'targets.id', '=', 'product_links.to_id')
                ->get(['product_links.id', 'product_elements.ref_id as from', 'targets.ref_id as to', 'product_links.kind'])
                ->map(fn ($link) => ['id' => $link->id, 'from' => $link->getAttribute('from'), 'to' => $link->getAttribute('to'), 'kind' => $link->kind])->values(),
            'requests' => $project->productRequests()->withCount('requirements')->with(['chatMessage.room:id,ref_id', 'ticket:id,number'])->latest('id')->get()
                ->map(fn (ProductRequest $one) => self::request($one))->values(),
            'requirements' => $project->requirements()->with(['elements:id,ref_id,code,name', 'tickets:id,number,title,type,status', 'request:id,code,ref_id', 'changes:id,code,ref_id', 'owner:id,name'])->latest('id')->get()
                ->map(fn (Requirement $one) => self::requirement($one))->values(),
            'coverage' => Tracker::coverage($project),
            'tickets' => $project->tickets()->orderByDesc('number')->get(['id', 'number', 'title', 'type', 'status'])->map->only(['number', 'title', 'type', 'status'])->values(),
            // Who can work a request out into requirements
            'agents' => Agent::query()->of($project)->orderBy('name')->get(['ref_id', 'name'])->map->only(['ref_id', 'name'])->values(),
            'people' => $project->members()->wherePivotIn('role', [Project::OWNER, Project::EDITOR])->orderBy('name')->get()->map->only(['id', 'name'])->values(),
            'can' => ['change' => Gate::allows('contribute', $project)],
        ]);
    }

    // ------------------------------------------------------------------ Parts

    public function storeElement(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('contribute', $project);
        $validated = $request->validate([
            'kind' => ['required', Rule::in([ProductElement::COMPONENT, ProductElement::CAPABILITY])],
            'name' => ['required', 'string', 'max:160'],
            'parent' => ['nullable', 'string', 'max:16'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'shared' => ['sometimes', 'boolean'],
        ]);
        $parent = filled($validated['parent'] ?? null) ? $this->element($project, (string) $validated['parent']) : null;

        Keeper::addPart($project, $request->user(), $validated['kind'], $validated['name'], $parent, $validated['summary'] ?? null, (bool) ($validated['shared'] ?? false));

        return back();
    }

    public function updateElement(Request $request, ProductElement $element): RedirectResponse
    {
        $project = $this->changing($element->project);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(ProductElement::STATUSES)],
            'shared' => ['sometimes', 'boolean'],
            'parent' => ['sometimes', 'nullable', 'string', 'max:16'],
        ]);
        if (array_key_exists('parent', $validated)) {
            $validated['parent'] = filled($validated['parent']) ? $this->element($project, (string) $validated['parent']) : null;
        }

        Keeper::changePart($element, $request->user(), $validated);

        return back();
    }

    /**
     * Take a part away -- one with nothing under it. Its code is not given again.
     */
    public function destroyElement(ProductElement $element): RedirectResponse
    {
        $this->changing($element->project);

        Keeper::removePart($element);

        return back();
    }

    /**
     * One part in full: what is linked to it, its history, and what a change to it would reach.
     */
    public function showElement(ProductElement $element): JsonResponse
    {
        Gate::authorize('view', $element->project);
        $element->load(['links.to:id,ref_id,code,name', 'linkedFrom.from:id,ref_id,code,name', 'requirements:id,ref_id,code,title,status', 'tickets:id,number,title,type,status', 'events.user:id,name']);

        return response()->json([
            'links' => $element->links->map(fn (ProductLink $link) => ['id' => $link->id, 'kind' => $link->kind, ...$link->to->only(['ref_id', 'code', 'name'])])->values(),
            'linked_from' => $element->linkedFrom->map(fn (ProductLink $link) => ['id' => $link->id, 'kind' => $link->kind, ...$link->from->only(['ref_id', 'code', 'name'])])->values(),
            'requirements' => $element->requirements->map->only(['ref_id', 'code', 'title', 'status'])->values(),
            'tickets' => $element->tickets->map->only(['number', 'title', 'type', 'status'])->values(),
            'history' => $element->events->take(50)->map(fn ($event) => [...$event->only(['what', 'from', 'to']), 'by' => $event->user?->name, 'at' => $event->created_at])->values(),
            'impact' => Tracker::impact($element),
        ]);
    }

    public function link(Request $request, ProductElement $element): RedirectResponse
    {
        $project = $this->changing($element->project);
        $validated = $request->validate([
            'to' => ['required', 'string', 'max:16'],
            'kind' => ['required', Rule::in(ProductElement::LINKS)],
        ]);
        Keeper::link($element, $this->element($project, $validated['to']), $validated['kind'], $request->user());

        return back();
    }

    public function unlink(Request $request, ProductLink $link): RedirectResponse
    {
        $this->changing($link->from->project);
        Keeper::unlink($link, $request->user());

        return back();
    }

    /**
     * Work on a part: one of the project's tickets, by its number, linked or not.
     */
    public function elementTicket(Request $request, ProductElement $element): RedirectResponse
    {
        $project = $this->changing($element->project);
        $validated = $request->validate(['number' => ['required', 'integer'], 'linked' => ['required', 'boolean']]);
        $ticket = $project->tickets()->where('number', $validated['number'])->firstOrFail();

        $validated['linked'] ? $element->tickets()->syncWithoutDetaching([$ticket->id]) : $element->tickets()->detach($ticket->id);

        return back();
    }

    // --------------------------------------------------------------- Requests

    public function storeRequest(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('contribute', $project);
        $validated = $request->validate([
            'original' => ['required', 'string', 'max:20000'],
            'from_name' => ['nullable', 'string', 'max:160'],
        ]);

        Keeper::record($project, $request->user(), $validated['original'], 'typed', $validated['from_name'] ?? null);

        return back();
    }

    /**
     * What a customer wrote in their chat, kept as a request of the project's product.
     */
    public function requestFromChat(Request $request, ChatRoom $room): RedirectResponse
    {
        Gate::authorize('update', $room);
        abort_unless($room->kind === ChatRoom::CUSTOMER && $room->project !== null, 404);
        $message = $room->messages()->whereKey((int) $request->validate(['message' => ['required', 'integer']])['message'])->firstOrFail();

        $kept = Keeper::record($room->project, $request->user(), $message->content, 'chat', $room->contact->name ?? $room->title, ['chat_message_id' => $message->id]);
        // The chat keeps a note of it, for the project alone
        $room->say($request->user(), "Kept as request {$kept->code} of the product", ['internal' => true]);

        return back();
    }

    /**
     * A ticket, kept as a request of the product: what it said when it was kept.
     */
    public function requestFromTicket(Request $request, Project $project, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('contribute', $project);
        abort_unless($ticket->project_id === $project->id, 404);

        Keeper::record($project, $request->user(), trim($ticket->title."\n\n".($ticket->body ?? '')), 'ticket', $ticket->creator?->name, ['ticket_id' => $ticket->id]);

        return back();
    }

    /**
     * How far a request has been worked out. What it says is never changed.
     */
    public function updateRequest(Request $request, ProductRequest $productRequest): RedirectResponse
    {
        $this->changing($productRequest->project);

        if ($request->has('original')) {
            throw ValidationException::withMessages(['original' => __('What was asked is kept as it was asked: add a requirement for what it means.')]);
        }

        $productRequest->update($request->validate(['status' => ['required', Rule::in(ProductRequest::STATUSES)]]));

        return back();
    }

    /**
     * A request worked out by one of the project's agents into requirements:
     * a draft, kept by nobody, for a person to read and change first.
     */
    public function workOut(Request $request, ProductRequest $productRequest, Analyst $analyst): JsonResponse
    {
        $project = $this->changing($productRequest->project);
        $ref = (string) $request->validate(['agent' => ['required', 'string', 'max:16']])['agent'];
        $agent = Agent::query()->of($project)->where('ref_id', $ref)->first()
            ?? throw ValidationException::withMessages(['agent' => __('Choose one of the project\'s agents.')]);

        try {
            return response()->json($analyst->workOut($agent, $productRequest, $request->user()));
        } catch (AiFailed $e) {
            // Asked, and it could not: said as an answer, not as a request gone wrong
            return response()->json(['failed' => $e->getMessage()]);
        }
    }

    /**
     * The requirements a person keeps of a draft, as they left them: each
     * made from the request, still to be checked -- "clarifying" while it has
     * questions, a "draft" otherwise. Their criteria start unmet.
     */
    public function keepWorkedOut(Request $request, ProductRequest $productRequest): RedirectResponse
    {
        $project = $this->changing($productRequest->project);
        $validated = $request->validate([
            'requirements' => ['required', 'array', 'min:1', 'max:8'],
            'requirements.*.title' => ['required', 'string', 'max:200'],
            'requirements.*.need' => ['nullable', 'string', 'max:5000'],
            'requirements.*.kind' => ['sometimes', Rule::in(Requirement::KINDS)],
            'requirements.*.understanding' => ['sometimes', Rule::in(Requirement::UNDERSTANDING)],
            'requirements.*.criteria' => ['sometimes', 'array', 'max:50'],
            'requirements.*.criteria.*' => ['nullable', 'string', 'max:500'],
            'requirements.*.questions' => ['sometimes', 'array', 'max:50'],
            'requirements.*.questions.*' => ['nullable', 'string', 'max:500'],
            'requirements.*.assumptions' => ['sometimes', 'array', 'max:50'],
            'requirements.*.assumptions.*' => ['nullable', 'string', 'max:500'],
            'requirements.*.parts' => ['sometimes', 'array', 'max:100'],
            'requirements.*.parts.*' => ['string', 'max:16'],
        ]);

        $kept = DB::transaction(fn () => array_map(function (array $one) use ($project, $request, $productRequest) {
            $requirement = Keeper::addRequirement($project, $request->user(), $one['title'], $one['need'] ?? null, $one['kind'] ?? null, $productRequest);
            $questions = array_values(array_filter(array_map(fn ($text) => trim((string) $text), $one['questions'] ?? [])));

            Keeper::changeRequirement($requirement, [
                'understanding' => $one['understanding'] ?? 'partial',
                'status' => $questions === [] ? 'draft' : 'clarifying',
                'criteria' => array_map(fn ($text) => ['text' => (string) $text, 'met' => false], $one['criteria'] ?? []),
                'questions' => $questions,
                'assumptions' => $one['assumptions'] ?? [],
                'elements' => $project->productElements()->whereIn('code', $one['parts'] ?? [])->pluck('id')->all(),
            ]);

            return $requirement->code;
        }, $validated['requirements']));

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':codes kept.|:codes kept.', count($kept), ['codes' => implode(', ', $kept)])]);

        return back();
    }

    // ----------------------------------------------------------- Requirements

    public function storeRequirement(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('contribute', $project);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'need' => ['nullable', 'string', 'max:5000'],
            'kind' => ['sometimes', Rule::in(Requirement::KINDS)],
            'request' => ['nullable', 'string', 'max:16'],
            'changes' => ['nullable', 'string', 'max:16'],
            'element' => ['nullable', 'string', 'max:16'],
        ]);
        $from = filled($validated['request'] ?? null) ? $project->productRequests()->where('ref_id', $validated['request'])->firstOrFail() : null;
        $changes = filled($validated['changes'] ?? null) ? $project->requirements()->where('ref_id', $validated['changes'])->firstOrFail() : null;

        $requirement = Keeper::addRequirement($project, $request->user(), $validated['title'], $validated['need'] ?? null, $validated['kind'] ?? null, $from, $changes);

        if (filled($validated['element'] ?? null)) {
            $requirement->elements()->attach($this->element($project, (string) $validated['element'])->id);
        }

        return back();
    }

    public function updateRequirement(Request $request, Requirement $requirement): RedirectResponse
    {
        $project = $this->changing($requirement->project);
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'need' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'body' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'kind' => ['sometimes', Rule::in(Requirement::KINDS)],
            'status' => ['sometimes', Rule::in(Requirement::STATUSES)],
            'understanding' => ['sometimes', Rule::in(Requirement::UNDERSTANDING)],
            'priority' => ['sometimes', Rule::in(Requirement::PRIORITIES)],
            'owner' => ['sometimes', 'nullable', 'integer'],
            'questions' => ['sometimes', 'array', 'max:50'],
            'questions.*' => ['nullable', 'string', 'max:500'],
            'assumptions' => ['sometimes', 'array', 'max:50'],
            'assumptions.*' => ['nullable', 'string', 'max:500'],
            'criteria' => ['sometimes', 'array', 'max:50'],
            'criteria.*.text' => ['required', 'string', 'max:500'],
            'criteria.*.met' => ['required', 'boolean'],
            'elements' => ['sometimes', 'array', 'max:100'],
            'elements.*' => ['string', 'max:16'],
            'tickets' => ['sometimes', 'array', 'max:100'],
            'tickets.*' => ['integer'],
        ]);

        if (array_key_exists('owner', $validated)) {
            $validated['owner_id'] = Keeper::owner($project, $validated['owner']);
        }

        if (array_key_exists('elements', $validated)) {
            $validated['elements'] = $project->productElements()->whereIn('ref_id', $validated['elements'])->pluck('id')->all();
        }

        if (array_key_exists('tickets', $validated)) {
            $validated['tickets'] = $project->tickets()->whereIn('number', $validated['tickets'])->pluck('id')->all();
        }

        Keeper::changeRequirement($requirement, $validated);

        return back();
    }

    // ------------------------------------------------------------------ Help

    private function element(Project $project, string $refId): ProductElement
    {
        return $project->productElements()->where('ref_id', $refId)->first()
            ?? throw ValidationException::withMessages(['element' => __('That is not a part of this product.')]);
    }

    private function changing(Project $project): Project
    {
        Gate::authorize('contribute', $project);

        return $project;
    }

    /**
     * @return array<string, mixed>
     */
    private static function request(ProductRequest $one): array
    {
        return [
            ...$one->only(['ref_id', 'code', 'original', 'source', 'from_name', 'status']),
            'requirements' => (int) $one->getAttribute('requirements_count'),
            // Where it was said, to go back and read the rest
            'chat' => $one->chatMessage?->room->ref_id,
            'ticket' => $one->ticket?->number,
            'created_at' => $one->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function requirement(Requirement $one): array
    {
        return [
            ...$one->only(['ref_id', 'code', 'title', 'need', 'body', 'kind', 'status', 'understanding', 'priority']),
            'questions' => $one->questions ?? [],
            'assumptions' => $one->assumptions ?? [],
            'criteria' => $one->criteria ?? [],
            'owner' => $one->owner?->only(['id', 'name']),
            'request' => $one->request?->only(['ref_id', 'code']),
            'changes' => $one->changes?->only(['ref_id', 'code']),
            'elements' => $one->elements->map->only(['ref_id', 'code', 'name'])->values(),
            'tickets' => $one->tickets->map->only(['number', 'title', 'type', 'status'])->values(),
            'updated_at' => $one->updated_at,
        ];
    }
}
