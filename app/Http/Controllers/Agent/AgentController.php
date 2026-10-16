<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Concerns\ActsForOwner;
use App\Http\Controllers\Controller;
use App\Models\Agent\Agent;
use App\Models\Agent\AgentVersion;
use App\Models\Connection;
use App\Models\Knowledge\KnowledgeBase;
use App\Models\Owner;
use App\Models\Project;
use App\Support\Agents\AgentRunner;
use App\Support\Agents\Presets;
use App\Support\Agents\Toolbox;
use App\Support\Ai\AiFailed;
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
 * Agents: a model with the instructions it answers by and the knowledge it
 * answers from -- one's own, or a project's, used by anyone in it and set
 * up by its owners and editors. Every change is kept, to go back to.
 */
class AgentController extends Controller
{
    use ActsForOwner;

    /** How many of an agent's versions its page lists. */
    private const HISTORY = 30;

    public function index(Request $request): Response
    {
        $owner = $this->owner($request);

        return Inertia::render('agents/Index', [
            'agents' => Agent::query()->of($owner)
                ->with(['connection:id,ref_id,name', 'knowledgeBase:id,ref_id,title', 'versions' => fn ($versions) => $versions->limit(self::HISTORY)->with(['connection:id,name', 'knowledgeBase:id,title', 'author:id,name'])])
                ->orderBy('name')
                ->get()
                ->map(fn (Agent $agent) => self::shown($agent))
                ->values(),
            'choices' => [
                'connections' => $owner->connections()->ai()->orderBy('name')->get()
                    ->map(fn (Connection $connection) => [...$connection->only(['ref_id', 'name']), 'label' => $connection->label(), 'default_model' => $connection->setting('default_model')])->values(),
                'knowledge' => KnowledgeBase::query()->of($owner)->orderBy('title')->get(['ref_id', 'title'])->map->only(['ref_id', 'title'])->values(),
                'presets' => collect(Presets::all())->map(fn (array $preset, string $key) => ['key' => $key, ...array_intersect_key($preset, array_flip(['name', 'description']))])->values(),
            ],
            'can' => ['manage' => ! $owner instanceof Project || Gate::allows('contribute', $owner)],
            'urls' => ['store' => OwnerUrl::to($owner, 'agents.store')],
        ]);
    }

    /**
     * Start an agent from a preset, through the AI connection made last
     * and its usual model, reading the knowledge base made last.
     */
    public function store(Request $request): RedirectResponse
    {
        $owner = $this->owner($request, 'contribute');
        $validated = $request->validate([
            'preset' => ['required', Rule::in(array_keys(Presets::all()))],
            'name' => ['nullable', 'string', 'max:80'],
        ]);
        $preset = Presets::all()[$validated['preset']];
        $connection = $owner->connections()->ai()->latest('id')->first();

        $agent = new Agent(['name' => trim((string) ($validated['name'] ?? '')) ?: $preset['name']]);
        $agent->ownedBy($owner, $request->user())->save();
        $agent->change([
            'connection_id' => $connection?->id,
            'model' => $connection?->setting('default_model'),
            'instructions' => $preset['instructions'],
            'knowledge_base_id' => KnowledgeBase::query()->of($owner)->latest('id')->value('id'),
        ], $request->user(), "Made from \"{$preset['name']}\"");

        return back();
    }

    /**
     * Rename it, or change what it is -- kept as a new version.
     */
    public function update(Request $request, Agent $agent): RedirectResponse
    {
        $owner = $this->allowed($request, $agent, change: true);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'connection' => ['sometimes', 'nullable', 'string', 'max:16'],
            'model' => ['sometimes', 'nullable', 'string', 'max:160'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:'.Agent::MAX_INSTRUCTIONS],
            'knowledge' => ['sometimes', 'nullable', 'string', 'max:16'],
            'tools' => ['sometimes', 'array', 'max:60'],
            'tools.*.server' => ['required', 'string', 'max:16'],
            'tools.*.name' => ['required', 'string', 'max:128'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        if (array_key_exists('name', $validated)) {
            $agent->update(['name' => trim($validated['name'])]);
        }

        $changes = [];

        if (array_key_exists('connection', $validated)) {
            $changes['connection_id'] = $validated['connection'] === null ? null
                : ($owner->connections()->ai()->where('ref_id', $validated['connection'])->value('id')
                    ?? throw ValidationException::withMessages(['connection' => __('Choose one of these AI connections.')]));
        }

        if (array_key_exists('model', $validated)) {
            $changes['model'] = filled($validated['model']) ? trim($validated['model']) : null;
        }

        if (array_key_exists('instructions', $validated)) {
            $changes['instructions'] = (string) $validated['instructions'];
        }

        if (array_key_exists('knowledge', $validated)) {
            $changes['knowledge_base_id'] = $validated['knowledge'] === null ? null
                : (KnowledgeBase::query()->of($owner)->where('ref_id', $validated['knowledge'])->value('id')
                    ?? throw ValidationException::withMessages(['knowledge' => __('Choose one of these knowledge bases.')]));
        }

        if (array_key_exists('tools', $validated)) {
            // Only what its owner's servers offer, each once, in a steady order
            $offered = [];

            foreach (Toolbox::catalog($owner) as $server) {
                foreach ($server['tools'] as $tool) {
                    $offered["{$tool['server']}:{$tool['name']}"] = ['server' => $tool['server'], 'name' => $tool['name']];
                }
            }

            $chosen = [];

            foreach ($validated['tools'] as $tool) {
                $key = "{$tool['server']}:{$tool['name']}";

                if (isset($offered[$key])) {
                    $chosen[$key] = $offered[$key];
                }
            }

            ksort($chosen);
            $changes['tools'] = $chosen === [] ? null : array_values($chosen);
        }

        if ($changes !== []) {
            $agent->change($changes, $request->user(), $validated['note'] ?? null);
        }

        return back();
    }

    /**
     * Make an earlier version what it is again.
     */
    public function restore(Request $request, Agent $agent): RedirectResponse
    {
        $this->allowed($request, $agent, change: true);
        $number = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];

        $version = $agent->at($number) ?? throw ValidationException::withMessages(['version' => __('It has no version :number.', ['number' => $number])]);
        $agent->restore($version, $request->user());

        return back();
    }

    public function destroy(Request $request, Agent $agent): RedirectResponse
    {
        $this->allowed($request, $agent, change: true);
        $agent->delete();

        return back();
    }

    /**
     * The tools its owner's agents may be given, server by server.
     */
    public function tools(Request $request, Agent $agent): JsonResponse
    {
        return response()->json(['servers' => array_map(fn (array $server) => [
            ...array_intersect_key($server, array_flip(['server', 'name', 'problem'])),
            'tools' => array_map(fn (array $tool) => array_intersect_key($tool, array_flip(['name', 'description', 'reads'])), $server['tools']),
        ], Toolbox::catalog($this->allowed($request, $agent, change: false)))]);
    }

    /**
     * Ask it something, at its latest or at a version, to see how it answers.
     */
    public function try(Request $request, Agent $agent, AgentRunner $runner): JsonResponse
    {
        $this->allowed($request, $agent, change: false);
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'version' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            // Tried, it looks things up with its tools and changes nothing
            return response()->json($runner->ask($agent, $validated['version'] ?? null, $validated['message'], for: $request->user(), changes: false));
        } catch (AiFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * The owner of an agent the user may use, or change.
     */
    private function allowed(Request $request, Agent $agent, bool $change): Owner
    {
        $owner = $agent->owner();

        abort_unless(
            $owner instanceof Project
                ? Gate::allows($change ? 'contribute' : 'view', $owner)
                : $agent->user_id === $request->user()->id,
            403,
        );

        return $owner;
    }

    /**
     * @return array<string, mixed>
     */
    private static function shown(Agent $agent): array
    {
        return [
            ...$agent->only(['ref_id', 'name', 'model', 'instructions', 'version']),
            'connection' => $agent->connection?->ref_id,
            'knowledge' => $agent->knowledgeBase?->ref_id,
            'tools' => $agent->tools ?? [],
            'versions' => $agent->versions->map(fn (AgentVersion $version) => [
                ...$version->only(['number', 'model', 'instructions', 'note']),
                'connection' => $version->connection->name ?? null,
                'knowledge' => $version->knowledgeBase->title ?? null,
                'tools' => array_map(fn (array $tool) => $tool['name'], $version->tools ?? []),
                'by' => $version->author?->name,
                'at' => $version->created_at,
            ])->values(),
        ];
    }
}
