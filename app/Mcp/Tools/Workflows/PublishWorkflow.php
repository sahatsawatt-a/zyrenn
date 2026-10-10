<?php

namespace App\Mcp\Tools\Workflows;

use App\Mcp\Tools\WorkflowTool;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Audit\Audit;
use App\Support\Workflow\Listeners;
use App\Support\Workflow\References;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Publish a workflow\'s draft as its next version: what runs when it is switched on, or run with run-workflow version "published". Refused while anything stands in the way (get-workflow "problems"). Runs already going keep the version they started on.')]
class PublishWorkflow extends WorkflowTool
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

        $problems = References::allProblems($workflow);

        if ($problems !== []) {
            return Response::error('Fix what stands in the way first: '.implode(' ', array_column($problems, 'message')));
        }

        if ($workflow->publishedVersion === null || $workflow->hasUnpublishedChanges()) {
            $workflow->freeze(WorkflowVersion::PUBLISHED, $user);
            // A switched-on workflow listens with what was just published
            Listeners::rebuild($workflow->refresh());
            Audit::record($workflow, 'workflow.published', $user, ['version' => $workflow->publishedVersion?->number, 'by' => 'mcp']);
        }

        return Response::structured($this->described($workflow->refresh()));
    }
}
