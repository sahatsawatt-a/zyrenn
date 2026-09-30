<?php

namespace App\Mcp\Tools\Projects;

use App\Mcp\Tools\UserTool;
use App\Models\Membership;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the projects the user is in: shared spaces with notes, boards, tables and a Drive of their own. Pass a project\'s ref_id or name as "project" to any other tool to work in it. A viewer can only read a project; owners and editors can change what is in it.')]
class ListProjects extends UserTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): ResponseFactory
    {
        $projects = $this->targetUser($request)
            ->projects()
            ->withCount('members')
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project) => [
                'ref_id' => $project->ref_id,
                'name' => $project->name,
                'role' => $project->getRelationValue('pivot') instanceof Membership ? $project->getRelationValue('pivot')->role : null,
                'members_count' => $project->members_count,
                'url' => route('projects.show', $project),
            ])
            ->all();

        return Response::structured(['projects' => $projects]);
    }
}
