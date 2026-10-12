<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ActsForOwner;
use App\Models\Connection;
use App\Models\Owner;
use App\Models\Project;
use App\Support\Audit\Audit;
use App\Support\Chat\AiClient;
use App\Support\Chat\AiFailed;
use App\Support\Connections\ConnectionCheck;
use App\Support\Connections\Services;
use App\Support\Connections\SignIns;
use App\Support\Workflow\Inbound\Hooks;
use App\Support\Workflow\Steps\WhatsAppApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where chats and workflows reach outside: a user's own connections in their
 * settings, a project's in the project's. A project's are seen by all its
 * members -- by name only -- and kept by its owners and editors. A secret
 * goes in and is used, and never comes back out.
 */
class ConnectionController extends Controller
{
    use ActsForOwner;

    /**
     * Show the owner's connections.
     */
    public function index(Request $request): Response
    {
        $owner = $this->owner($request);
        $project = $owner instanceof Project ? $owner : null;

        return Inertia::render($project ? 'projects/Connections' : 'settings/Connections', [
            'connections' => $owner->connections()->with('creator:id,name')->orderBy('name')->get()
                ->map(fn (Connection $connection) => $this->describe($connection))
                ->values(),
            'services' => Services::all(),
            'can' => ['manage' => $project === null || Gate::allows('contribute', $project)],
            'project' => $project?->only(['ref_id', 'name']),
            // Who added, changed or removed one lately
            'activity' => Audit::forOwner($project === null ? $request->user()->id : null, $project?->id, 'connection', 20),
            'urls' => [
                'store' => self::ownerRoute($owner, 'connections.store'),
                'check' => self::ownerRoute($owner, 'connections.check'),
            ],
        ]);
    }

    /**
     * Add a connection.
     */
    public function store(Request $request): RedirectResponse
    {
        $owner = $this->owner($request, 'contribute');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'service' => ['required', Rule::in(array_keys(Services::all()))],
            ...Services::rules((string) $request->input('service')),
        ], Services::messages(), Services::attributes((string) $request->input('service')));

        $connection = new Connection([
            'name' => $validated['name'],
            'service' => $validated['service'],
            'settings' => Services::settings($validated['service'], $validated['settings'] ?? []),
        ]);
        $connection->secrets = Services::secrets($validated['service'], $validated['secrets'] ?? []) ?: null;
        $connection->ownedBy($owner, $request->user())->save();
        Audit::record($connection, 'connection.added', $request->user(), ['service' => $connection->service]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection added.')]);

        return redirect(self::ownerRoute($owner, 'connections.index'));
    }

    /**
     * Change a connection. A secret left blank is kept. An AI connection may
     * become another AI service, keeping its key; anything else stays the
     * service it is, since its secrets mean nothing to another.
     */
    public function update(Request $request, Connection $connection): RedirectResponse
    {
        Gate::authorize('update', $connection);

        $service = (string) $request->input('service');
        $sameKind = $service === $connection->service || ($connection->isAi() && in_array($service, Services::ai(), true));
        $saved = $sameKind ? ($connection->secrets ?? []) : [];

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'service' => ['required', Rule::in($connection->isAi() ? Services::ai() : [$connection->service])],
            ...Services::rules($service, $saved),
        ], Services::messages(), Services::attributes($service));

        $before = [
            'name' => $connection->name,
            'service' => $connection->service,
            'settings' => $connection->settings ?? [],
            'secrets' => $connection->secrets ?? [],
        ];

        $connection->fill([
            'name' => $validated['name'],
            'service' => $validated['service'],
            'settings' => Services::settings($validated['service'], $validated['settings'] ?? [], $sameKind ? $before['settings'] : []),
        ]);
        $connection->secrets = Services::secrets($validated['service'], $validated['secrets'] ?? [], $saved) ?: null;
        $connection->save();

        // Which fields changed, never what they hold
        $changed = [
            ...($before['name'] !== $connection->name ? ['name'] : []),
            ...($before['service'] !== $connection->service ? ['service'] : []),
            ...self::changedKeys($before['settings'], $connection->settings ?? []),
            ...self::changedKeys($before['secrets'], $connection->secrets ?? []),
        ];

        if ($changed !== []) {
            Audit::record($connection, 'connection.changed', $request->user(), ['fields' => $changed]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection saved.')]);

        return redirect(self::ownerRoute($connection->owner(), 'connections.index'));
    }

    /**
     * Remove a connection; the chats that used it stay, without an agent.
     */
    public function destroy(Request $request, Connection $connection): RedirectResponse
    {
        Gate::authorize('delete', $connection);

        Audit::record($connection, 'connection.removed', $request->user(), ['service' => $connection->service]);
        $connection->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection removed.')]);

        return redirect(self::ownerRoute($connection->owner(), 'connections.index'));
    }

    /**
     * Whether a saved connection still works.
     */
    public function test(Connection $connection, ConnectionCheck $check): JsonResponse
    {
        Gate::authorize('update', $connection);

        return response()->json($check->run($connection));
    }

    /**
     * The models a saved AI connection offers, for choosing one in a chat.
     */
    public function models(Connection $connection, AiClient $ai): JsonResponse
    {
        Gate::authorize('view', $connection);

        if (! $connection->isAi()) {
            return response()->json(['message' => 'This connection does not answer chats.'], 422);
        }

        try {
            return response()->json($ai->catalog($connection));
        } catch (AiFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Try what is typed in the form before it is saved. Nothing is kept. A
     * secret left blank while changing a saved connection is that
     * connection's own, so it need not be typed again. A value of the wrong
     * shape is an answer to the question asked, like a host that fails, so it
     * comes back as ok: false rather than as an error.
     */
    public function check(Request $request, ConnectionCheck $check): JsonResponse
    {
        $owner = $this->owner($request, 'contribute');

        $request->validate([
            'service' => ['required', Rule::in(array_keys(Services::all()))],
            // A saved connection is only ever one of this owner's
            'connection' => ['nullable', 'string'],
        ]);

        $service = (string) $request->input('service');
        $fields = Validator::make(
            $request->only(['settings', 'secrets']),
            Services::rules($service, complete: false),
            Services::messages(),
            Services::attributes($service),
        );

        if ($fields->fails()) {
            return response()->json(['ok' => false, 'message' => $fields->errors()->first()]);
        }

        $validated = [...$fields->validated(), 'service' => $service, 'connection' => $request->input('connection')];

        $saved = $this->saved($owner, $validated['connection'] ?? null);

        $tried = new Connection([
            'service' => $validated['service'],
            'settings' => Services::settings($validated['service'], $validated['settings'] ?? [], $saved->settings ?? []),
        ]);
        $tried->secrets = Services::secrets($validated['service'], $validated['secrets'] ?? [], $saved->secrets ?? []);

        return response()->json($check->run($tried));
    }

    /**
     * @throws ValidationException when the owner has no such connection
     */
    private function saved(Owner $owner, ?string $refId): ?Connection
    {
        if ($refId === null) {
            return null;
        }

        return $owner->connections()->where('ref_id', $refId)->first()
            ?? throw ValidationException::withMessages(['connection' => 'There is no such connection.']);
    }

    /**
     * What the page is told of a connection: its settings, and which secrets
     * it holds -- never what they are.
     *
     * @return array<string, mixed>
     */
    private function describe(Connection $connection): array
    {
        return [
            ...$connection->only(['ref_id', 'name', 'service']),
            'settings' => (object) ($connection->settings ?? []),
            'saved_secrets' => array_keys($connection->secrets ?? []),
            'added_by' => $connection->creator?->name,
            'created_at' => $connection->created_at,
            'hook' => self::hookOf($connection),
            'sign_in' => SignIns::describe($connection),
        ];
    }

    /**
     * Where a bot or channel sends what it is told, for its page to show:
     * LINE's is pasted into LINE's console; Telegram's is set by the app when
     * a workflow using the bot is switched on. The address is null while
     * nothing outside can reach the app (WORKFLOWS_PUBLIC_URL).
     *
     * @return array{url: string|null, set: string, hint?: string}|null
     */
    private static function hookOf(Connection $connection): ?array
    {
        return match (true) {
            $connection->service === Services::WHATSAPP => [
                'url' => Hooks::url("whatsapp/{$connection->ref_id}"),
                'set' => 'by_hand',
                'hint' => 'In the Meta app, under WhatsApp → Configuration → Webhook: paste it as the Callback URL, with the verify token '.WhatsAppApi::verifyToken($connection).', then subscribe to "messages".',
            ],
            $connection->service === 'line' => ['url' => Hooks::url("line/{$connection->ref_id}"), 'set' => 'by_hand'],
            $connection->service === 'telegram' && $connection->setting('receive') !== 'poll' => ['url' => Hooks::url("telegram/{$connection->ref_id}"), 'set' => 'by_app'],
            default => null,
        };
    }

    /**
     * The fields of two sets that differ, by name.
     *
     * @param  array<string, mixed>  $was
     * @param  array<string, mixed>  $is
     * @return list<string>
     */
    private static function changedKeys(array $was, array $is): array
    {
        return array_values(array_filter(
            array_unique([...array_keys($was), ...array_keys($is)]),
            fn (string $key) => ($was[$key] ?? null) !== ($is[$key] ?? null),
        ));
    }
}
