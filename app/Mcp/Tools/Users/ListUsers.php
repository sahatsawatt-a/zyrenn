<?php

namespace App\Mcp\Tools\Users;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List users so you can pick a user_id for the note tools. Optionally search by name or email.')]
class ListUsers extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Only users whose name or email contains this text.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(25)->description('Maximum number of users to return.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->when($validated['search'] ?? null, fn ($query, string $search) => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('email', "%{$search}%"))
            ->withCount('notes')
            ->orderBy('id')
            ->limit($validated['limit'] ?? 25)
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'notes_count' => $user->notes_count,
            ])
            ->all();

        return Response::structured(['users' => $users]);
    }
}
