<?php

namespace App\Support\Workflow\Steps;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * PromptX's calls that run flows, each a kind of step of its own: calling
 * a flow's webhook -- leaving it to run, or waiting for what it answers --
 * running its draft to try it, trying a run again, stopping runs, and
 * answering a run that waits for a person.
 *
 * Each starts or changes a run, so each is recorded and never simply made
 * twice.
 */
final class PromptxRun implements Step
{
    /** The longest a step waits for a flow to answer, in seconds. */
    public const MOST_WAIT = 110;

    public function run(StepContext $step): StepResult
    {
        // A webhook is PromptX's open door: no account is needed to knock
        if (in_array($step->node['type'], ['promptx.flow_run', 'promptx.flow_test'], true)) {
            return new StepResult(self::call($step, $step->node['type'] === 'promptx.flow_test'));
        }

        $account = PromptxApi::account($step);
        $in = PromptxApi::project($step, $account);

        return new StepResult(match ($step->node['type']) {
            'promptx.run_retry' => self::retry($step, $in),
            'promptx.runs_cancel' => self::cancel($step, $in),
            'promptx.run_resume' => self::resume($step, $in),

            default => throw new StepFailed("This app doesn't know the PromptX step \"{$step->node['type']}\"."),
        });
    }

    /**
     * Calls a flow's webhook with a body, and gives out what it answered:
     * at once, as the run starts, or once the flow answers -- what its
     * "Return Response" step returns -- or the time is up. The draft is run
     * the second way, and PromptX keeps no run of it.
     *
     * @return array{status: int|null, ok: bool, answer: mixed, timed_out: bool}
     */
    private static function call(StepContext $step, bool $draft): array
    {
        $id = PromptxCall::id($step, 'flow', 'flow');
        $wait = $draft || $step->setting('wait') === 'yes';
        $seconds = max(1, min(self::MOST_WAIT, (int) ($step->setting('seconds') ?: 30)));
        $url = PromptxApi::FLOWS.'/webhooks/'.$id.($draft ? '/draft/sync' : ($wait ? '/sync' : ''));
        $body = self::body($step, 'body');

        try {
            $answer = $step->deliver('promptx', $id, null, fn () => Http::timeout($wait ? $seconds : 30)->acceptJson()
                ->withBody($body, 'application/json')
                ->post($url));
        } catch (ConnectionException $e) {
            if ($wait && str_contains($e->getMessage(), 'timed out')) {
                return ['status' => null, 'ok' => false, 'answer' => null, 'timed_out' => true];
            }

            throw StepFailed::unreached($e, 'PromptX');
        }

        return [
            'status' => $answer->status(),
            'ok' => $answer->successful(),
            'answer' => $answer->json() ?? $answer->body(),
            'timed_out' => false,
        ];
    }

    /**
     * Tries a run again: from the step it failed at, as the same run, or
     * from the top as a new run of the flow as it is now.
     *
     * @param  array{token: string, project: string}  $in
     * @return array<string, mixed>
     */
    private static function retry(StepContext $step, array $in): array
    {
        $id = PromptxCall::id($step, 'run', 'run');
        $strategy = $step->setting('from') === 'top' ? 'ON_LATEST_VERSION' : 'FROM_FAILED_STEP';
        $answer = PromptxApi::send($step, $id, fn () => PromptxApi::as($in['token'])
            ->post(PromptxApi::FLOWS.'/flow-runs/'.$id.'/retry', ['strategy' => $strategy, 'projectId' => $in['project']]))->json();

        return is_array($answer) && isset($answer['id']) ? PromptxCall::summary($answer) : ['id' => $id, 'status' => null];
    }

    /**
     * Stops runs waiting to start or waiting part way.
     *
     * @param  array{token: string, project: string}  $in
     * @return array{cancelled: list<string>}
     */
    private static function cancel(StepContext $step, array $in): array
    {
        $given = $step->template('runs');
        $ids = is_array($given)
            ? array_map(fn ($one) => is_array($one) ? (string) ($one['id'] ?? '') : (string) $one, $given)
            : preg_split('/[\s,]+/', (string) $given);
        $ids = array_values(array_unique(array_filter((array) $ids)));

        foreach ($ids as $id) {
            if (preg_match('/^[A-Za-z0-9_-]+$/', $id) !== 1) {
                throw new StepFailed("\"{$id}\" is not a PromptX run id.");
            }
        }

        if ($ids === []) {
            throw new StepFailed('There are no runs to stop: their ids.');
        }

        PromptxApi::send($step, implode(',', $ids), fn () => PromptxApi::as($in['token'])
            ->post(PromptxApi::FLOWS.'/flow-runs/cancel', ['flowRunIds' => $ids, 'projectId' => $in['project']]));

        return ['cancelled' => $ids];
    }

    /**
     * Answers a run that waits -- for an approval, or for what a person
     * fills in -- so it goes on.
     *
     * @param  array{token: string, project: string}  $in
     * @return array{status: int, answer: mixed}
     */
    private static function resume(StepContext $step, array $in): array
    {
        $id = PromptxCall::id($step, 'run', 'run');
        $request = PromptxCall::id($step, 'request', 'request');
        $answer = PromptxApi::send($step, $id, fn () => PromptxApi::as($in['token'])
            ->withBody(self::body($step, 'body'), 'application/json')
            ->post(PromptxApi::FLOWS.'/flow-runs/'.$id.'/requests/'.$request));

        return ['status' => $answer->status(), 'answer' => $answer->json() ?? $answer->body()];
    }

    /**
     * A body to send, as JSON: what was typed, or a value worked out.
     *
     * @throws StepFailed
     */
    private static function body(StepContext $step, string $setting): string
    {
        $value = $step->template($setting);

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return '{}';
            }

            json_decode($value);

            return json_last_error() === JSON_ERROR_NONE ? $value : throw new StepFailed('The body should be JSON, like {"name": "Ada"}.');
        }

        return $value === [] || $value === null ? '{}' : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
