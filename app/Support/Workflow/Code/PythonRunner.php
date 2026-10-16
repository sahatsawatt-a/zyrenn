<?php

namespace App\Support\Workflow\Code;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The Python runner (docker/python): Python someone wrote in a "Run code"
 * step, run by Pyodide -- CPython in WebAssembly -- in a container on a
 * network with no way out, a fresh interpreter in a process of its own for
 * each run, stopped at its time and killed past its memory.
 *
 * Having no way out, the runner cannot fetch what code imports: install()
 * fetches it here -- a package Pyodide ships, with what it depends on, or a
 * pure-Python wheel from PyPI -- and hands the files to the runner, which
 * keeps them for every run after.
 */
final class PythonRunner
{
    /** The Pyodide the runner was built with (docker/python/Dockerfile). */
    public const PYODIDE = '0.28.2';

    /** Where Pyodide's own packages are fetched from, for that version. */
    private const PACKAGES = 'https://cdn.jsdelivr.net/pyodide/v'.self::PYODIDE.'/full/';

    /** The largest file fetched for a library. */
    private const MOST_BYTES = 150 * 1024 * 1024;

    /**
     * Run the code on what came before: `trigger`, `input`, `steps` and
     * node("key") as a formula reads them. What it gives out is `result`
     * when the code sets it, else what its last line works out to; print()
     * goes to the logs.
     *
     * @param  array{trigger?: mixed, input?: mixed, steps?: mixed}  $data
     * @param  list<string>  $libraries  each a name, or name==version
     * @return array{ok: bool, value: mixed, logs: list<string>, error: string|null, line: int|null, retryable?: bool}
     */
    public static function run(string $code, array $data, int $seconds, array $libraries, int $memoryMb): array
    {
        // {} when no step came before, not []
        $body = json_encode([
            'code' => $code,
            'data' => [...$data, 'steps' => (object) ($data['steps'] ?? [])],
            'seconds' => $seconds,
            'libraries' => $libraries,
            'memory' => $memoryMb,
        ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            // Its time, and loading Python and its libraries before it
            $response = Http::timeout($seconds + 75)->withBody((string) $body, 'application/json')->post(self::url('/run'));
        } catch (ConnectionException) {
            return self::failed('The Python runner could not be reached: it runs as the "python" container.', retryable: true);
        }

        $answer = $response->json();

        if (! $response->successful() || ! is_array($answer)) {
            return self::failed('The Python runner could not run it: '.(is_array($answer) ? ($answer['error'] ?? $response->status()) : $response->status()).'.', retryable: $response->serverError());
        }

        return [
            'ok' => (bool) ($answer['ok'] ?? false),
            'value' => $answer['value'] ?? null,
            'logs' => array_values(array_map('strval', (array) ($answer['logs'] ?? []))),
            'error' => isset($answer['error']) ? (string) $answer['error'] : null,
            'line' => isset($answer['line']) ? (int) $answer['line'] : null,
        ];
    }

    /**
     * Make a library one code can import: a package Pyodide ships (numpy,
     * pandas, ...) at the version it ships, with what it depends on; or else
     * a pure-Python wheel from PyPI. Answers with the version kept and the
     * bytes it took, or why it could not be.
     *
     * @return array{ok: bool, version: string|null, size: int|null, error: string|null}
     */
    public static function install(string $name, ?string $version): array
    {
        $name = trim($name);
        $version = $version !== null && trim($version) !== '' ? trim($version) : null;

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $name) || ($version !== null && ! preg_match('/^[A-Za-z0-9._+!-]{1,40}$/', $version))) {
            return self::refused('That is not a library\'s name and version.');
        }

        try {
            $kept = self::kept();
            $shipped = self::lock()[self::normal($name)] ?? null;
            $done = is_array($shipped)
                ? self::fromPyodide($shipped, $version, $kept)
                : self::fromPyPi($name, $version, $kept);

            if (! $done['ok']) {
                return $done;
            }

            // Loaded once in a fresh interpreter: what it needs is all there
            $check = Http::timeout(90)->post(self::url('/check'), ['libraries' => [$name.($done['version'] && ! is_array($shipped) ? "=={$done['version']}" : '')]])->json();

            return is_array($check) && ($check['ok'] ?? false)
                ? $done
                : self::refused('It was fetched, but does not load: '.($check['error'] ?? 'the runner did not say why.'));
        } catch (ConnectionException) {
            return self::refused('The Python runner, or where libraries come from, could not be reached.');
        } catch (Throwable $problem) {
            report($problem);

            return self::refused('It could not be added: '.$problem->getMessage());
        }
    }

    /**
     * A package Pyodide ships, and everything it depends on, put in the
     * runner's cache: each file checked against the hash Pyodide gives it.
     *
     * @param  array<string, mixed>  $package
     * @param  array<string, int>  $kept
     * @return array{ok: bool, version: string|null, size: int|null, error: string|null}
     */
    private static function fromPyodide(array $package, ?string $version, array $kept): array
    {
        if ($version !== null && $version !== $package['version']) {
            return self::refused("Pyodide ships {$package['name']} {$package['version']}, and that is the version there is here.");
        }

        $lock = self::lock();
        $size = 0;

        foreach (self::withDepends($package, $lock) as $one) {
            $file = (string) $one['file_name'];

            if (! isset($kept[$file])) {
                $bytes = self::fetch(self::PACKAGES.rawurlencode($file));

                if (isset($one['sha256']) && hash('sha256', $bytes) !== $one['sha256']) {
                    return self::refused("{$file} came back other than Pyodide says it is.");
                }

                self::put($file, $bytes);
                $kept[$file] = strlen($bytes);
            }

            $size += $kept[$file];
        }

        return ['ok' => true, 'version' => (string) $package['version'], 'size' => $size, 'error' => null];
    }

    /**
     * A pure-Python wheel from PyPI, of the version asked for or the latest.
     * One that needs building for a platform cannot run in Pyodide.
     *
     * @param  array<string, int>  $kept
     * @return array{ok: bool, version: string|null, size: int|null, error: string|null}
     */
    private static function fromPyPi(string $name, ?string $version, array $kept): array
    {
        $response = Http::timeout(20)->acceptJson()->get('https://pypi.org/pypi/'.rawurlencode($name).($version !== null ? '/'.rawurlencode($version) : '').'/json');

        if ($response->status() === 404) {
            return self::refused($version !== null ? "PyPI has no {$name} {$version}." : "There is no {$name}: not in Pyodide, nor on PyPI.");
        }

        $release = (array) $response->throw()->json();
        $wheel = null;

        foreach ((array) ($release['urls'] ?? []) as $file) {
            if (is_array($file) && ($file['packagetype'] ?? '') === 'bdist_wheel' && preg_match('/-(py3|py2\.py3)-none-any\.whl$/', (string) ($file['filename'] ?? ''))) {
                $wheel = $file;

                break;
            }
        }

        if (! is_array($wheel)) {
            return self::refused("{$name} has no pure-Python wheel, and Pyodide does not ship it: it cannot run here.");
        }

        $file = (string) $wheel['filename'];

        if (! isset($kept[$file])) {
            $bytes = self::fetch((string) $wheel['url']);

            if (isset($wheel['digests']['sha256']) && hash('sha256', $bytes) !== $wheel['digests']['sha256']) {
                return self::refused("{$file} came back other than PyPI says it is.");
            }

            self::put($file, $bytes);
            $kept[$file] = strlen($bytes);
        }

        return ['ok' => true, 'version' => (string) ($release['info']['version'] ?? $version), 'size' => $kept[$file], 'error' => null];
    }

    /**
     * A package and what it depends on, each once.
     *
     * @param  array<string, mixed>  $package
     * @param  array<string, array<string, mixed>>  $lock
     * @return list<array<string, mixed>>
     */
    private static function withDepends(array $package, array $lock): array
    {
        $found = [];
        $next = [$package];

        while ($next !== []) {
            $one = array_shift($next);
            $key = self::normal((string) $one['name']);

            if (isset($found[$key])) {
                continue;
            }

            $found[$key] = $one;

            foreach ((array) ($one['depends'] ?? []) as $depends) {
                if (isset($lock[self::normal((string) $depends)])) {
                    $next[] = $lock[self::normal((string) $depends)];
                }
            }
        }

        return array_values($found);
    }

    /**
     * Pyodide's list of the packages it ships, by name as PyPI compares them.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function lock(): array
    {
        return Cache::remember('python-runner.lock.'.self::PYODIDE, now()->addDay(), function () {
            $byName = [];

            foreach ((array) Http::timeout(30)->get(self::PACKAGES.'pyodide-lock.json')->throw()->json('packages', []) as $key => $package) {
                if (is_array($package)) {
                    $byName[self::normal((string) ($package['name'] ?? $key))] = $package;
                }
            }

            return $byName;
        });
    }

    /**
     * What the runner keeps already: each file's name and size.
     *
     * @return array<string, int>
     */
    private static function kept(): array
    {
        $kept = [];

        foreach ((array) Http::timeout(10)->get(self::url('/files'))->throw()->json() as $file) {
            if (is_array($file) && is_string($file['name'] ?? null)) {
                $kept[$file['name']] = (int) ($file['size'] ?? 0);
            }
        }

        return $kept;
    }

    private static function fetch(string $url): string
    {
        $response = Http::timeout(120)->withOptions(['stream' => false])->get($url)->throw();
        $bytes = $response->body();

        if (strlen($bytes) > self::MOST_BYTES) {
            throw new RuntimeException('It is larger than 150 MB.');
        }

        return $bytes;
    }

    private static function put(string $file, string $bytes): void
    {
        Http::timeout(120)->withBody($bytes, 'application/octet-stream')->put(self::url('/files/'.rawurlencode($file)))->throw();
    }

    /** A name as PyPI compares names: "Typing_Extensions" is "typing-extensions". */
    private static function normal(string $name): string
    {
        return (string) preg_replace('/[-_.]+/', '-', strtolower($name));
    }

    private static function url(string $path): string
    {
        return rtrim((string) config('services.python.url'), '/').$path;
    }

    /** @return array{ok: false, value: null, logs: list<string>, error: string, line: null, retryable: bool} */
    private static function failed(string $error, bool $retryable = false): array
    {
        return ['ok' => false, 'value' => null, 'logs' => [], 'error' => $error, 'line' => null, 'retryable' => $retryable];
    }

    /** @return array{ok: false, version: null, size: null, error: string} */
    private static function refused(string $error): array
    {
        return ['ok' => false, 'version' => null, 'size' => null, 'error' => $error];
    }
}
