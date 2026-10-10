<?php

namespace App\Mcp\Tools\Workflows;

use App\Mcp\Tools\WorkflowTool;
use App\Models\Workflow\Workflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TEXT'
Get a workflow: its steps as the draft has them (key, kind, name, settings, on_error), how they are
joined ("from" step key, the "way" out it takes, "to" step key), what stands in the way of publishing
or running it, the version published and whether it is switched on, its latest runs, and its
"revision" -- pass it to update-workflow so a change is never made over someone else's. A run's steps
and what each gave out: get-workflow-run.
TEXT)]
class GetWorkflow extends WorkflowTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return ['ref_id' => $this->refIdArgument($schema)];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $owner = $this->targetOwner($request);
        $validated = $request->validate(['ref_id' => ['required', 'string', 'max:16']]);

        /** @var Workflow|null $workflow */
        $workflow = $this->find($owner, $validated['ref_id']);

        if (! $workflow) {
            return $this->notFound($validated['ref_id']);
        }

        return Response::structured($this->described($workflow));
    }
}
