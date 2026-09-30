<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

/**
 * Base for every tool that acts as one user.
 *
 * - User mode: the user is always the authenticated token's owner.
 * - Global mode: the caller picks the user with a required `user_id` argument.
 */
abstract class UserTool extends Tool
{
    public function __construct(protected bool $global = false) {}

    /**
     * Arguments specific to this tool.
     *
     * @return array<string, Type>
     */
    abstract protected function arguments(JsonSchema $schema): array;

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $user = $this->global
            ? ['user_id' => $schema->integer()->description('ID of the user to act as (see list-users).')->required()]
            : [];

        return [...$user, ...$this->arguments($schema)];
    }

    /**
     * Resolves the user this call acts as.
     */
    protected function targetUser(Request $request): User
    {
        if (! $this->global) {
            $user = $request->user();

            // e.g. the token behind a running stdio session was revoked
            if (! $user instanceof User) {
                throw new AuthenticationException('This MCP token is no longer valid.');
            }

            return $user;
        }

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ], [
            'user_id.exists' => 'No user exists with that user_id.',
        ]);

        return User::query()->whereKey($validated['user_id'])->firstOrFail();
    }
}
