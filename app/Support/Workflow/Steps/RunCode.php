<?php

namespace App\Support\Workflow\Steps;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use stdClass;

/**
 * Run code: JavaScript someone wrote, run on what came before, giving out
 * what it returns. It reads trigger, input and node("key") as a formula
 * does, and console.log writes in the run's log.
 *
 * None of it runs here. It is handed to the code runner (docker/code), a
 * container of its own that runs it in an interpreter with nothing to reach
 * -- no network, no files, no process -- stops it when its time is up, and
 * keeps it to a fixed memory. Only what it is given goes in; only what it
 * returns comes back.
 */
final class RunCode implements Step
{
    /** How long it may run, in seconds, unless the step says; and the most a step may say. */
    public const SECONDS = 5;

    private const MOST_SECONDS = 30;

    /** How much it may be given to read, as JSON. */
    private const MOST_READ = 2 * 1024 * 1024;

    public function run(StepContext $step): StepResult
    {
        $code = (string) $step->setting('code');

        if (trim($code) === '') {
            throw new StepFailed('There is no code to run.');
        }

        $seconds = max(1, min(self::MOST_SECONDS, (int) ($step->setting('seconds') ?: self::SECONDS)));
        $seen = $step->scope->all();
        // {} when no step came before, not []
        $data = json_encode([...$seen, 'steps' => (object) $seen['steps']], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($data === false || strlen($data) > self::MOST_READ) {
            throw new StepFailed('What the steps before it gave is more than 2 MB: too much for code to read at once.');
        }

        try {
            $response = Http::timeout($seconds + 10)
                ->withBody('{"seconds":'.$seconds.',"code":'.json_encode($code, JSON_INVALID_UTF8_SUBSTITUTE).',"data":'.$data.'}', 'application/json')
                ->post(rtrim((string) config('services.code.url'), '/').'/run');
        } catch (ConnectionException) {
            throw new StepFailed('The code runner could not be reached: it runs as the "code" container.', retryable: true);
        }

        $answer = $response->json();

        if (! $response->successful() || ! is_array($answer)) {
            throw new StepFailed('The code runner could not run it: '.(is_array($answer) ? ($answer['error'] ?? $response->status()) : $response->status()).'.', retryable: $response->serverError());
        }

        foreach ((array) ($answer['logs'] ?? []) as $line) {
            $step->log('info', (string) $line);
        }

        if (! ($answer['ok'] ?? false)) {
            throw new StepFailed(($answer['error'] ?? 'It failed.').(isset($answer['line']) ? " (line {$answer['line']})" : ''));
        }

        // A record is given out as it is -- an empty one too, which comes back as [] otherwise
        $record = json_decode($response->body())->value ?? null;

        return new StepResult($record instanceof stdClass ? $answer['value'] : ['value' => $answer['value'] ?? null]);
    }
}
