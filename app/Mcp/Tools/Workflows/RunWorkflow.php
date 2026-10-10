<?php

namespace App\Mcp\Tools\Workflows;

use App\Mcp\Tools\WorkflowTool;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Audit\Audit;
use App\Support\Workflow\Engine;
use App\Support\Workflow\References;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
Run a workflow from its "Run by hand" trigger, as pressing Run in the editor does: the draft as last
saved by default, or "published" for what is published. "data", a JSON object, is what the trigger
gives out (trigger.name and so on); left out, the trigger's own test data. With "wait" (seconds, up
to 60) it answers once the run has ended or the time is up, with each step's status and output;
otherwise at once, and get-workflow-run follows it. Steps that send messages or change tables do so
for real.
TEXT)]
class RunWorkflow extends WorkflowTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $this->refIdArgument($schema),
            'version' => $schema->string()->enum(['draft', 'published'])->default('draft')->description('Which to run.'),
            'data' => $schema->object()->description('What the "Run by hand" trigger gives out, e.g. {"name": "Ada"}.'),
            'wait' => $schema->integer()->min(0)->max(60)->default(0)->description('Seconds to wait for it to end.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $owner = $this->targetOwner($request, changes: true);
        $validated = $request->validate([
            'ref_id' => ['required', 'string', 'max:16'],
            'version' => ['nullable', 'in:draft,published'],
            'data' => ['nullable', 'array'],
            'wait' => ['nullable', 'integer', 'min:0', 'max:60'],
        ]);

        /** @var Workflow|null $workflow */
        $workflow = $this->find($owner, $validated['ref_id']);

        if (! $workflow) {
            return $this->notFound($validated['ref_id']);
        }

        $user = $this->targetUser($request);
        $published = ($validated['version'] ?? 'draft') === 'published';

        if ($published && $workflow->publishedVersion === null) {
            return Response::error('It is not published yet; run the draft, or publish it first.');
        }

        if (! $published && ($problems = References::allProblems($workflow)) !== []) {
            return Response::error('Fix what stands in the way first: '.implode(' ', array_column($problems, 'message')));
        }

        $version = $published
            ? $workflow->publishedVersion
            : ($workflow->versions()->where('revision', $workflow->revision)->latest('id')->first() ?? $workflow->freeze(WorkflowVersion::TEST, $user));

        /** @var list<array{type: string}> $nodes */
        $nodes = $version->definition['nodes'] ?? [];

        if (! in_array('trigger.manual', array_column($nodes, 'type'), true)) {
            return Response::error('It has no "Run by hand" trigger to start it from here.');
        }

        $run = Engine::start($workflow, $version, $user, 'manual', null, isset($validated['data']) ? $validated['data'] : null);
        Audit::record($workflow, 'workflow.run', $user, ['run' => $run->ref_id, 'by' => 'mcp']);

        $until = microtime(true) + (int) ($validated['wait'] ?? 0);

        while (microtime(true) < $until && ! $run->refresh()->status->isFinal()) {
            usleep(250_000);
        }

        return Response::structured($this->report($run->fresh() ?? $run));
    }
}
