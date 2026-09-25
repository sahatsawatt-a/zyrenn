<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Create an MCP access token. The plain-text token is flashed once.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        $token = $request->user()->createToken($validated['name'], ['mcp']);

        Inertia::flash('mcpToken', $token->plainTextToken);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Token created.')]);

        return to_route('mcp.edit');
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
