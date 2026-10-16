<?php

namespace App\Mcp\Tools;

use App\Mcp\Scopes;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
     * Offered only when the key in use allows it (Scopes): one whose parts
     * were chosen sees, and may call, just those tools. The admin server's
     * tools are for whoever may use that server at all.
     */
    public function shouldRegister(): bool
    {
        $user = Auth::user();

        return $this->global || ! $user instanceof User || Scopes::allows($user->currentAccessToken(), $this);
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

    /**
     * The project a call names in "project", one the user is in, for what
     * belongs to a project alone ($whose: "Tickets"). $changing, when the call
     * changes something, says what only its owners and editors can do.
     */
    protected function projectCalled(Request $request, string $whose, ?string $changing = null): Project
    {
        $named = trim((string) $request->get('project', ''));

        if ($named === '') {
            throw ValidationException::withMessages([
                'project' => "{$whose} belong to a project: pass its ref_id or name as \"project\" (see list-projects).",
            ]);
        }

        $project = $this->projectNamed($this->targetUser($request), $named);
        $role = $project->getRelationValue('pivot')?->getAttribute('role');

        if ($changing !== null && ! in_array($role, [Project::OWNER, Project::EDITOR], true)) {
            throw ValidationException::withMessages([
                'project' => "The user is a {$role} in \"{$project->name}\": only its owners and editors {$changing}.",
            ]);
        }

        return $project;
    }

    /**
     * One of the user's projects, by ref_id or, failing that, by name.
     */
    protected function projectNamed(User $user, string $named): Project
    {
        $project = $user->projects()->where('projects.ref_id', $named)->first();

        if ($project) {
            return $project;
        }

        $matches = $user->projects()
            ->where(DB::raw('lower(projects.name)'), mb_strtolower($named))
            ->get();

        return match ($matches->count()) {
            1 => $matches->first(),
            0 => throw ValidationException::withMessages([
                'project' => "The user is in no project \"{$named}\". See list-projects.",
            ]),
            default => throw ValidationException::withMessages([
                'project' => "The user is in several projects called \"{$named}\"; pass one's ref_id instead (see list-projects).",
            ]),
        };
    }
}
