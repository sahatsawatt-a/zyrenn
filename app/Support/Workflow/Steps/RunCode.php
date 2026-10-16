<?php

namespace App\Support\Workflow\Steps;

use App\Models\Workflow\CodeLibrary;
use App\Support\Workflow\Code\CodeLibraries;
use App\Support\Workflow\Code\CodeSettings;
use App\Support\Workflow\Code\PythonRunner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use stdClass;

/**
 * Run code: JavaScript -- or Python, where the app's admins allow it --
 * someone wrote, run on what came before, giving out what it returns. It
 * reads trigger, input and node("key") as a formula does, uses only the
 * libraries chosen for it, and console.log (print) writes in the run's log.
 *
 * None of it runs here. JavaScript is handed to the code runner
 * (docker/code), a container of its own that runs it in an interpreter with
 * nothing to reach -- no network, no files, no process -- stops it when its
 * time is up, and keeps it to a fixed memory; Python to the Python runner,
 * likewise. Only what it is given goes in; only what it returns comes back.
 */
final class RunCode implements Step
{
    /** How long it may run, in seconds, unless the step says. */
    public const SECONDS = 5;

    /** How much it may be given to read, as JSON. */
    private const MOST_READ = 2 * 1024 * 1024;

    public function run(StepContext $step): StepResult
    {
        $code = (string) $step->setting('code');
        $language = $step->setting('language') === CodeLibrary::PYTHON ? CodeLibrary::PYTHON : CodeLibrary::JAVASCRIPT;

        if (trim($code) === '') {
            throw new StepFailed('There is no code to run.');
        }

        if ($language === CodeLibrary::PYTHON && ! CodeSettings::pythonOn()) {
            throw new StepFailed('Python is switched off by the app\'s admins.');
        }

        // As long as the step says, up to what the admins allow
        $seconds = max(1, min(CodeSettings::mostSeconds($language), (int) ($step->setting('seconds') ?: self::SECONDS)));
        $seen = $step->scope->all();
        // {} when no step came before, not []
        $seen = [...$seen, 'steps' => (object) $seen['steps']];
        $data = json_encode($seen, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($data === false || strlen($data) > self::MOST_READ) {
            throw new StepFailed('What the steps before it gave is more than 2 MB: too much for code to read at once.');
        }

        $names = array_values(array_filter((array) $step->setting('libraries'), is_string(...)));
        $libraries = CodeLibraries::forRun($language, $names);

        if (count($libraries) < count($names)) {
            $have = array_map(fn ($library) => is_array($library) ? $library['name'] : $library, $libraries);

            throw new StepFailed('It uses '.implode(', ', array_diff($names, $have)).', which the app\'s admins have taken away or switched off.');
        }

        [$answer, $body] = $language === CodeLibrary::PYTHON
            ? self::python($code, $seen, $seconds, $libraries)
            : self::javascript($code, $data, $seconds, $libraries);

        foreach ((array) ($answer['logs'] ?? []) as $line) {
            $step->log('info', (string) $line);
        }

        if (! ($answer['ok'] ?? false)) {
            throw new StepFailed(($answer['error'] ?? 'It failed.').(isset($answer['line']) ? " (line {$answer['line']})" : ''));
        }

        // A record is given out as it is -- an empty one too, which comes back as [] otherwise
        $record = $body === null ? null : (json_decode($body)->value ?? null);

        return new StepResult($record instanceof stdClass || (is_array($answer['value'] ?? null) && ! array_is_list($answer['value']))
            ? $answer['value']
            : ['value' => $answer['value'] ?? null]);
    }

    /**
     * @param  list<array{name: string, source: string}>|list<string>  $libraries
     * @return array{0: array<mixed>, 1: string}
     */
    private static function javascript(string $code, string $data, int $seconds, array $libraries): array
    {
        try {
            $response = Http::timeout($seconds + 10)
                ->withBody('{"seconds":'.$seconds.',"code":'.json_encode($code, JSON_INVALID_UTF8_SUBSTITUTE).',"libraries":'.json_encode($libraries, JSON_INVALID_UTF8_SUBSTITUTE).',"data":'.$data.'}', 'application/json')
                ->post(rtrim((string) config('services.code.url'), '/').'/run');
        } catch (ConnectionException) {
            throw new StepFailed('The code runner could not be reached: it runs as the "code" container.', retryable: true);
        }

        $answer = $response->json();

        if (! $response->successful() || ! is_array($answer)) {
            throw new StepFailed('The code runner could not run it: '.(is_array($answer) ? ($answer['error'] ?? $response->status()) : $response->status()).'.', retryable: $response->serverError());
        }

        return [$answer, $response->body()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{name: string, source: string}>|list<string>  $libraries
     * @return array{0: array<mixed>, 1: null}
     */
    private static function python(string $code, array $data, int $seconds, array $libraries): array
    {
        if (! class_exists(PythonRunner::class)) {
            throw new StepFailed('The Python runner is not set up on this server yet.');
        }

        /** @var list<string> $libraries */
        $answer = PythonRunner::run($code, $data, $seconds, $libraries, CodeSettings::get()['python_memory']);

        // The runner down or busy is not the code's failing: tried again later
        if (($answer['retryable'] ?? false) === true) {
            throw new StepFailed((string) ($answer['error'] ?? 'The Python runner could not run it now.'), retryable: true);
        }

        return [$answer, null];
    }
}
