<?php

namespace App\Mcp\Tools\Workflows;

use App\Mcp\Tools\WorkflowTool;
use App\Models\Workflow\Workflow;
use App\Support\Audit\Audit;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Permanently delete a workflow, with every version and run of it. It stops listening at once. Chat rooms and tables it wrote in stay.')]
class DeleteWorkflow extends WorkflowTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return ['ref_id' => $this->refIdArgument($schema)];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);
        $owner = $this->targetOwner($request, changes: true);
        $validated = $request->validate(['ref_id' => ['required', 'string', 'max:16']]);

        /** @var Workflow|null $workflow */
        $workflow = $this->find($owner, $validated['ref_id']);

        if (! $workflow) {
            return $this->notFound($validated['ref_id']);
        }

        Audit::record($workflow, 'workflow.deleted', $user, ['by' => 'mcp']);
        $title = $workflow->title ?: 'Untitled';
        $workflow->delete();

        return Response::text("Deleted workflow {$validated['ref_id']} (\"{$title}\").");
    }
}
