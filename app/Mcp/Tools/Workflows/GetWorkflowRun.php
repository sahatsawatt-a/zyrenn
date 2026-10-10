<?php

namespace App\Mcp\Tools\Workflows;

use App\Mcp\Tools\WorkflowTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Get one run: where it and each of its steps stand, the way each took, what each gave out (long outputs cut short -- pass "step" with a step\'s key for all of that one), any error, and its log.')]
class GetWorkflowRun extends WorkflowTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'run' => $schema->string()->max(16)->description('The run\'s ref_id (from list-workflow-runs or run-workflow).')->required(),
            'step' => $schema->string()->max(40)->description('A step\'s key: its output in full.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $owner = $this->targetOwner($request);
        $validated = $request->validate([
            'run' => ['required', 'string', 'max:16'],
            'step' => ['nullable', 'string', 'max:40'],
        ]);

        $run = $this->findRun($owner, $validated['run']);

        if (! $run) {
            return Response::error("Run {$validated['run']} was not found.");
        }

        return Response::structured($this->report($run, $validated['step'] ?? null));
    }
}
