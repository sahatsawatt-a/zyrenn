<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Mcp\Scopes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class McpController extends Controller
{
    /**
     * Show the user's MCP connection settings and access tokens.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Mcp', [
            'endpoint' => url('/mcp/user'),
            'tokens' => $request->user()
                ->tokens()
                ->latest()
                ->get()
                ->filter(fn (PersonalAccessToken $token) => $token->can('mcp'))
                ->map(fn (PersonalAccessToken $token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'created_at_diff' => $token->created_at?->diffForHumans(),
                    'last_used_at_diff' => $token->last_used_at?->diffForHumans(),
                    // What it may do in each part: off, read or change
                    'parts' => Scopes::levels($token),
                ])
                ->values()
                ->all(),
            'parts' => Scopes::PARTS,
            // Each part's tools, so choosing a level shows what it offers
            'tools' => Scopes::tools(),
        ]);
    }

    /**
     * Create an MCP access token. The plain-text token is flashed once.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            ...self::partRules(),
        ]);

        $token = $request->user()->createToken($validated['name'], Scopes::abilities($validated['parts'] ?? array_fill_keys(array_keys(Scopes::PARTS), Scopes::CHANGE)));

        Inertia::flash('mcpToken', $token->plainTextToken);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Token created.')]);

        return to_route('mcp.edit');
    }

    /**
     * Change what one of the user's MCP keys may do, part by part. A client
     * using it sees the change from its next call.
     */
    public function update(Request $request, int $token): RedirectResponse
    {
        $validated = $request->validate(['parts' => ['required', 'array'], ...self::partRules()]);
        $key = $request->user()->tokens()->whereKey($token)->firstOrFail();
        $key->forceFill(['abilities' => Scopes::abilities($validated['parts'])])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Access changed.')]);

        return to_route('mcp.edit');
    }

    /** @return array<string, mixed> */
    private static function partRules(): array
    {
        return [
            'parts' => ['sometimes', 'array'],
            'parts.*' => [Rule::in(Scopes::LEVELS)],
        ];
    }

    /**
     * Revoke one of the user's MCP access tokens.
     */
    public function destroy(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->whereKey($token)->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Token revoked.')]);

        return to_route('mcp.edit');
    }
}
