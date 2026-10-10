<?php

namespace App\Mcp\Tools\Workflows;

use App\Mcp\Tools\WorkflowTool;
use App\Models\Workflow\Workflow;
use App\Support\Audit\Audit;
use App\Support\Workflow\Listeners;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Switch a published workflow on, so its triggers -- a schedule, a chat, a webhook, a bot -- start runs by themselves, or off. What runs is what is published, never the draft.')]
class SwitchWorkflow extends WorkflowTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $this->refIdArgument($schema),
            'on' => $schema->boolean()->description('true to switch it on, false off.')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $owner = $this->targetOwner($request, changes: true);
        $validated = $request->validate([
            'ref_id' => ['required', 'string', 'max:16'],
            'on' => ['required', 'boolean'],
        ]);

        /** @var Workflow|null $workflow */
        $workflow = $this->find($owner, $validated['ref_id']);

        if (! $workflow) {
            return $this->notFound($validated['ref_id']);
        }

        if ($validated['on'] && $workflow->published_version_id === null) {
            return Response::error('Publish it first: what is switched on is what is published.');
        }

        $workflow->newQuery()->whereKey($workflow->id)->update(['enabled' => $validated['on']]);
        $workflow->enabled = $validated['on'];
        $workflow->syncOriginalAttribute('enabled');
        Listeners::rebuild($workflow);
        Audit::record($workflow, $workflow->enabled ? 'workflow.enabled' : 'workflow.disabled', $this->targetUser($request), ['by' => 'mcp']);

        return Response::structured($this->answer($workflow));
    }
}
