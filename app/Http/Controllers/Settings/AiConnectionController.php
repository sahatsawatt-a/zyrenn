<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Chat\AiConnection;
use App\Support\Chat\AiClient;
use App\Support\Chat\AiFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AiConnectionController extends Controller
{
    /**
     * Show the user's connections: where their models are reached.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('settings/AiConnections', [
            'connections' => $request->user()->aiConnections()->orderBy('name')->get()
                ->map(fn (AiConnection $connection) => $this->describe($connection))
                ->values(),
            'presets' => AiConnection::PRESETS,
        ]);
    }

    /**
     * Add a connection.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $request->user()->aiConnections()->create($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection added.')]);

        return to_route('ai-connections.index');
    }

    /**
     * Change a connection. A key left blank is kept, so it never has to come back to the page.
     */
    public function update(Request $request, AiConnection $connection): RedirectResponse
    {
        Gate::authorize('update', $connection);

        $validated = $this->validated($request, $connection);

        if (blank($validated['api_key'] ?? null)) {
            unset($validated['api_key']);
        }

        $connection->update($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection saved.')]);

        return to_route('ai-connections.index');
    }

    /**
     * Remove a connection; the rooms that used it stay, without an agent.
     */
    public function destroy(AiConnection $connection): RedirectResponse
    {
        Gate::authorize('delete', $connection);

        $connection->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection removed.')]);

        return to_route('ai-connections.index');
    }

    /**
     * The models a saved connection offers -- also how the page tells whether it still works.
     */
    public function models(AiConnection $connection, AiClient $ai): JsonResponse
    {
        Gate::authorize('view', $connection);

        return $this->listing($connection, $ai);
    }

    /**
     * Try what is typed in the form before it is saved: its host, its key, and
     * the models it offers. Nothing is kept. A key left blank while changing a
     * saved connection is that connection's own, so it need not be typed again.
     * A host that fails is still an answer to the question asked, so it comes
     * back as ok: false, not as an error.
     */
    public function check(Request $request, AiClient $ai): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(array_keys(AiConnection::PRESETS))],
            'base_url' => ['nullable', 'url:http,https', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:500'],
            // A saved connection is only ever one of the user's own
            'connection' => ['nullable', 'string', Rule::exists('ai_connections', 'ref_id')->where('user_id', $request->user()->id)],
        ]);

        $tried = new AiConnection([
            'kind' => $validated['kind'],
            'base_url' => $this->host($validated),
            'api_key' => $validated['api_key'] ?? null,
        ]);

        if ($tried->base_url === '') {
            return response()->json(['ok' => false, 'message' => 'Give the address of the host.']);
        }

        if (blank($tried->api_key) && ($validated['connection'] ?? null)) {
            $tried->api_key = $request->user()->aiConnections()->where('ref_id', $validated['connection'])->value('api_key');
        }

        try {
            return response()->json(['ok' => true, ...$ai->catalog($tried)]);
        } catch (AiFailed $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    private function listing(AiConnection $connection, AiClient $ai): JsonResponse
    {
        try {
            return response()->json($ai->catalog($connection));
        } catch (AiFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * The host as typed, or the kind's usual one when it was left out.
     *
     * @param  array<string, mixed>  $validated
     */
    private function host(array $validated): string
    {
        return rtrim($validated['base_url'] ?? '', '/') ?: AiConnection::PRESETS[$validated['kind']]['url'];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?AiConnection $existing = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'kind' => ['required', Rule::in(array_keys(AiConnection::PRESETS))],
            // Only "other" has no usual address to fall back on
            'base_url' => [Rule::requiredIf(fn () => $request->input('kind') === AiConnection::CUSTOM), 'nullable', 'url:http,https', 'max:255'],
            // A hosted service is paid for with the user's own key; a machine of theirs needs none
            'api_key' => [
                Rule::requiredIf(fn () => (AiConnection::PRESETS[$request->input('kind')]['key_required'] ?? false) && $existing?->api_key === null),
                'nullable', 'string', 'max:500',
            ],
            'default_model' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['base_url'] = $this->host($validated);

        return $validated;
    }

    /**
     * What the page is told of a connection: never the key, only that there is one.
     *
     * @return array<string, mixed>
     */
    private function describe(AiConnection $connection): array
    {
        return [
            ...$connection->only(['ref_id', 'name', 'kind', 'base_url', 'default_model']),
            'label' => $connection->label(),
            'has_key' => $connection->api_key !== null,
        ];
    }
}
