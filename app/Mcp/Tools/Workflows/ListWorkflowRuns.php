<?php

namespace App\Mcp\Tools\Workflows;

use App\Mcp\Tools\WorkflowTool;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List a workflow\'s runs, the latest first: how each ended, what started it, why it failed. Optionally only those with one status.')]
class ListWorkflowRuns extends WorkflowTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $this->refIdArgument($schema),
            'status' => $schema->string()->enum(['queued', 'running', 'waiting', 'succeeded', 'failed', 'cancelled'])->description('Only runs with this status.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(20),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $owner = $this->targetOwner($request);
        $validated = $request->validate([
            'ref_id' => ['required', 'string', 'max:16'],
            'status' => ['nullable', 'in:queued,running,waiting,succeeded,failed,cancelled'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var Workflow|null $workflow */
        $workflow = $this->find($owner, $validated['ref_id']);

        if (! $workflow) {
            return $this->notFound($validated['ref_id']);
        }

        return Response::structured([
            'runs' => $workflow->runs()
                ->when($validated['status'] ?? null, fn ($runs, string $status) => $runs->where('status', $status))
                ->latest('id')->limit($validated['limit'] ?? 20)->get()
                ->map(fn (WorkflowRun $run) => [
                    'ref_id' => $run->ref_id,
                    'status' => $run->status->value,
                    'trigger' => $run->trigger,
                    'reason' => $run->reason,
                    'error' => $run->error,
                    'started_at' => $run->started_at?->toIso8601String(),
                    'finished_at' => $run->finished_at?->toIso8601String(),
                ])->all(),
        ]);
    }
}
