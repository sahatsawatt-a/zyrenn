<?php

namespace App\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowNodeRun;
use App\Models\Workflow\WorkflowRun;
use App\Models\Workflow\WorkflowRunLog;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Audit\Audit;
use App\Support\Workflow\Engine;
use App\Support\Workflow\References;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Running a workflow from its editor, and looking at how its runs went.
 */
class WorkflowRunController extends Controller
{
    /**
     * Its latest runs, newest first.
     */
    public function index(Workflow $workflow): JsonResponse
    {
        Gate::authorize('view', $workflow);

        return response()->json([
            'runs' => $workflow->runs()
                ->with(['version:id,number,kind', 'starter:id,name'])
                ->latest('id')
                ->limit(30)
                ->get()
                ->map(fn (WorkflowRun $run) => $this->summary($run)),
        ]);
    }

    /**
     * Run the draft as it was last saved, from its "Run by hand" trigger. The
     * draft is frozen into a test version first -- or the frozen copy of it
     * already made is reused -- so the run keeps what it ran even as the
     * draft goes on changing.
     */
    public function store(Request $request, Workflow $workflow): JsonResponse
    {
        Gate::authorize('update', $workflow);

        $validated = $request->validate(['revision' => ['required', 'integer', 'min:0']]);

        if ($validated['revision'] !== $workflow->revision) {
            return response()->json(['message' => __('Someone else changed this workflow since you opened it.')], 409);
        }

        $problems = References::allProblems($workflow);

        if ($problems !== []) {
            return response()->json(['message' => __('Fix what stands in the way first.'), 'problems' => $problems], 422);
        }

        if (! collect($workflow->draft()['nodes'])->contains('type', 'trigger.manual')) {
            return response()->json(['message' => __('Add a "Run by hand" trigger to run it from here.')], 422);
        }

        $version = $workflow->versions()
            ->where('revision', $workflow->revision)
            ->latest('id')
            ->first() ?? $workflow->freeze(WorkflowVersion::TEST, $request->user());

        $run = Engine::start($workflow, $version, $request->user());
        Audit::record($workflow, 'workflow.run', $request->user(), ['run' => $run->ref_id]);

        return response()->json(['run' => $this->detail($run->fresh() ?? $run)], 201);
    }

    /**
     * One run: where each of its steps stands, what each was given and gave
     * out, and its log.
     */
    public function show(WorkflowRun $run): JsonResponse
    {
        Gate::authorize('view', $run->workflow);

        return response()->json(['run' => $this->detail($run)]);
    }

    /**
     * Approve or reject a step that asks for it; the run carries on that way.
     */
    public function answer(Request $request, WorkflowRun $run, string $node): JsonResponse
    {
        Gate::authorize('update', $run->workflow);

        $validated = $request->validate([
            'approved' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $step = $run->steps()->where('node_id', $node)->firstOrFail();

        try {
            Engine::answer($step, $validated['approved'], $request->user(), $validated['note'] ?? null);
            Audit::record($run->workflow, $validated['approved'] ? 'workflow.approved' : 'workflow.rejected', $request->user(), ['run' => $run->ref_id, 'step' => $step->node_key]);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['run' => $this->detail($run->fresh() ?? $run)]);
    }

    /**
     * Stop a run that has not ended.
     */
    public function cancel(Request $request, WorkflowRun $run): JsonResponse
    {
        Gate::authorize('update', $run->workflow);

        Engine::cancel($run, $request->user());
        Audit::record($run->workflow, 'workflow.stopped', $request->user(), ['run' => $run->ref_id]);

        return response()->json(['run' => $this->detail($run->fresh() ?? $run)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(WorkflowRun $run): array
    {
        return [
            ...$run->only(['ref_id', 'reason', 'error', 'trigger', 'created_at', 'started_at', 'finished_at']),
            'status' => $run->status->value,
            'version' => ['number' => $run->version->number, 'kind' => $run->version->kind],
            'started_by' => $run->starter?->name,
            'duration_ms' => $this->duration($run->started_at, $run->finished_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(WorkflowRun $run): array
    {
        $run->loadMissing(['version:id,number,kind', 'starter:id,name']);

        return [
            ...$this->summary($run),
            'can' => ['act' => Gate::allows('update', $run->workflow)],
            'steps' => $run->steps()->orderBy('id')->get()->map(fn (WorkflowNodeRun $step) => [
                ...$step->only(['node_id', 'node_key', 'node_type', 'handle', 'attempt', 'input', 'output', 'error', 'started_at', 'finished_at', 'waiting_for', 'resume_at', 'wait']),
                'status' => $step->status->value,
                'duration_ms' => $this->duration($step->started_at, $step->finished_at),
            ]),
            'logs' => $run->logs()->orderBy('id')->get()->map(fn (WorkflowRunLog $log) => [
                ...$log->only(['level', 'message', 'created_at']),
                'node_id' => $log->workflow_node_run_id === null
                    ? null
                    : $run->steps()->whereKey($log->workflow_node_run_id)->value('node_id'),
            ]),
        ];
    }

    private function duration(?CarbonInterface $from, ?CarbonInterface $to): ?int
    {
        return $from === null || $to === null ? null : (int) round($from->diffInMilliseconds($to));
    }
}
