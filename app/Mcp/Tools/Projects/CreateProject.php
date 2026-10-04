<?php

namespace App\Mcp\Tools\Projects;

use App\Mcp\Tools\UserTool;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Start a project: a shared space with notes, boards, tables and a Drive of its own, with the user as its owner. Pass its ref_id or name as "project" to any other tool to work in it. Others are added to it from its settings page in the app.')]
class CreateProject extends UserTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->max(255)->description('The project\'s name.')->required(),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $user = $this->targetUser($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $project = Project::start($user, $validated['name']);

        return Response::structured([
            'ref_id' => $project->ref_id,
            'name' => $project->name,
            'role' => Project::OWNER,
            'url' => route('projects.show', $project),
        ]);
    }
}
