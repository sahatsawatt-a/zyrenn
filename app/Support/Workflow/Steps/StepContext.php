<?php

namespace App\Support\Workflow\Steps;

use App\Models\Owner;
use App\Models\User;
use App\Models\Workflow\WorkflowDelivery;
use App\Models\Workflow\WorkflowNodeRun;
use App\Models\Workflow\WorkflowRun;
use App\Models\Workflow\WorkflowRunLog;
use App\Support\Formula\Evaluator;
use App\Support\Formula\Formula;
use App\Support\Formula\FormulaError;
use App\Support\Workflow\RunScope;
use App\Support\Workflow\Template;
use Closure;

/**
 * Everything a running step may know and do: its own settings, worked out
 * on asking; what came before it; and the run's log. Settings it worked out
 * are remembered, so the run can show what it really ran with.
 *
 * @phpstan-import-type Node from \App\Support\Workflow\Definition
 */
final class StepContext
{
    /** @var array<string, mixed> */
    private array $workedOut = [];

    /**
     * @param  Node  $node
     * @param  array<string, mixed>|null  $waited
     */
    public function __construct(
        public readonly WorkflowRun $run,
        public readonly WorkflowNodeRun $nodeRun,
        public readonly array $node,
        public readonly RunScope $scope,
        public readonly mixed $input,
        // What it kept when it began waiting, and any answer since; null on its first go
        public readonly ?array $waited = null,
    ) {}

    /**
     * A setting as it was written.
     */
    public function setting(string $name): mixed
    {
        return $this->node['config'][$name] ?? null;
    }

    /**
     * A template setting worked out.
     *
     * @throws StepFailed when one of its formulas can't be worked out
     */
    public function template(string $name, ?string $template = null): mixed
    {
        try {
            return $this->workedOut[$name] = Template::render($template ?? (string) $this->setting($name), $this->scope);
        } catch (FormulaError $e) {
            throw new StepFailed($e->getMessage());
        }
    }

    /**
     * A formula setting worked out.
     *
     * @throws StepFailed when it can't be worked out
     */
    public function formula(string $name): mixed
    {
        try {
            return $this->workedOut[$name] = Template::value(Formula::evaluate((string) $this->setting($name), $this->scope));
        } catch (FormulaError $e) {
            throw new StepFailed($e->getMessage());
        }
    }

    /**
     * Whether a worked-out value counts as yes.
     */
    public static function truthy(mixed $value): bool
    {
        return Evaluator::truthy($value);
    }

    /**
     * Remember a worked-out value under a name, for the run to show.
     */
    public function workedOut(string $name, mixed $value): void
    {
        $this->workedOut[$name] = $value;
    }

    /**
     * What the step's settings came to.
     *
     * @return array<string, mixed>
     */
    public function settingsWorkedOut(): array
    {
        return $this->workedOut;
    }

    /**
     * Whose the workflow is: what its steps may reach.
     */
    public function owner(): Owner
    {
        return $this->run->workflow->owner();
    }

    /**
     * Who the run acts as (WorkflowRun::actor), when anyone can be found.
     *
     * @throws StepFailed when nobody can
     */
    public function actor(): User
    {
        return $this->run->actor() ?? throw new StepFailed('Nobody can be found for this workflow to act as.');
    }

    /**
     * A setting worked out and read as text.
     */
    public function text(string $name, ?string $template = null): string
    {
        return Template::text($this->template($name, $template));
    }

    /**
     * Send something outside, keeping a record of it: "sending" before the
     * call, "sent" or "failed" after -- so when a worker dies between, the
     * send is known to be unsure and is not simply made again.
     *
     * @template T
     *
     * @param  Closure(): T  $send
     * @return T
     */
    public function deliver(string $service, ?string $target, ?string $key, Closure $send): mixed
    {
        $delivery = WorkflowDelivery::create([
            'workflow_node_run_id' => $this->nodeRun->id,
            'service' => $service,
            'target' => $target === null ? null : mb_substr($target, 0, 191),
            'idempotency_key' => $key,
            'status' => 'sending',
        ]);

        try {
            $answer = $send();
        } catch (\Throwable $e) {
            $delivery->update(['status' => 'failed', 'response' => ['error' => mb_substr($e->getMessage(), 0, 500)]]);

            throw $e;
        }

        $delivery->update(['status' => 'sent']);

        return $answer;
    }

    /**
     * A key for this step's send that is the same however often it is tried,
     * for services that take one to deliver a repeat only once.
     */
    public function sendKey(): string
    {
        $hash = md5("workflow-step:{$this->nodeRun->id}");

        return sprintf('%s-%s-4%s-a%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 13, 3), substr($hash, 17, 3), substr($hash, 20, 12));
    }

    /**
     * Write a line in the run's log.
     */
    public function log(string $level, string $message): void
    {
        WorkflowRunLog::create([
            'workflow_run_id' => $this->run->id,
            'workflow_node_run_id' => $this->nodeRun->id,
            'level' => $level,
            'message' => mb_substr($message, 0, 10000),
        ]);
    }
}
