<?php

namespace App\Support\Workflow\Code;

use App\Jobs\InstallCodeLibrary;
use App\Models\User;
use App\Models\Workflow\CodeLibrary;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * The libraries "Run code" may use: added by an admin by name (and a
 * version, if one), installed in the background, and handed to a run when
 * its step names them.
 *
 * A JavaScript one is installed by the code runner (docker/code): fetched
 * from npm without its install scripts, bundled into one file, and tried in
 * the sandbox -- one that needs Node itself (files, sockets) is turned down
 * there. A Python one is the Python runner's to install.
 */
final class CodeLibraries
{
    /** npm's names: lower case, a scope or not. */
    private const JS_NAME = '#^(@[a-z0-9][a-z0-9._~-]*/)?[a-z0-9][a-z0-9._~-]*$#';

    /** Python's (PEP 508). */
    private const PY_NAME = '#^[A-Za-z0-9]([A-Za-z0-9._-]*[A-Za-z0-9])?$#';

    private const VERSION = '#^[A-Za-z0-9.^~<>=*!+,| -]{1,64}$#';

    /**
     * Add one, or ask again for one already there (at another version, or
     * after it failed). Installed by a job; the page follows it.
     */
    public static function add(string $language, string $name, ?string $asked, User $by): CodeLibrary
    {
        $name = trim($name);
        $asked = trim((string) $asked) ?: null;

        if (preg_match($language === CodeLibrary::PYTHON ? self::PY_NAME : self::JS_NAME, $name) !== 1 || strlen($name) > 214) {
            throw ValidationException::withMessages(['name' => $language === CodeLibrary::PYTHON
                ? 'That is not a Python package\'s name, as PyPI writes it: numpy, python-dateutil.'
                : 'That is not an npm package\'s name: lower case, like lodash or @scope/name.']);
        }

        if ($asked !== null && preg_match(self::VERSION, $asked) !== 1) {
            throw ValidationException::withMessages(['version' => 'A version is written like 4.17.21, ^2.0 or latest.']);
        }

        $library = CodeLibrary::firstOrNew(['language' => $language, 'name' => $name]);
        $library->fill(['asked' => $asked, 'status' => CodeLibrary::INSTALLING, 'error' => null]);
        $library->added_by ??= $by->id;
        $library->save();

        InstallCodeLibrary::dispatch($library->id);

        return $library;
    }

    /**
     * Install it now: ready with what was installed, or failed saying why.
     */
    public static function install(CodeLibrary $library): void
    {
        $got = $library->language === CodeLibrary::PYTHON ? self::python($library) : self::javascript($library);

        $library->fill($got['ok']
            ? ['status' => CodeLibrary::READY, 'error' => null, 'version' => $got['version'] ?? null, 'size' => $got['size'] ?? null, 'source' => $got['source'] ?? null]
            : ['status' => CodeLibrary::FAILED, 'error' => mb_substr((string) ($got['error'] ?? 'It could not be installed.'), 0, 1000)]);
        $library->save();
    }

    /**
     * What a run of `$language` is given of the libraries its step names:
     * for JavaScript, each with its bundle; for Python, the names.
     *
     * @param  list<string>  $names
     * @return list<array{name: string, source: string}>|list<string>
     */
    public static function forRun(string $language, array $names): array
    {
        if ($names === []) {
            return [];
        }

        $usable = CodeLibrary::query()->usable($language)->whereIn('name', $names)->orderBy('name')->get();

        return array_values($language === CodeLibrary::PYTHON
            ? $usable->map(fn (CodeLibrary $library) => $library->name)->all()
            : $usable->map(fn (CodeLibrary $library) => ['name' => $library->name, 'source' => (string) $library->source])->all());
    }

    /**
     * Those a step may choose from now, by language.
     *
     * @return array<string, list<array{name: string, version: string|null}>>
     */
    public static function choices(): array
    {
        $choices = array_fill_keys(CodeLibrary::LANGUAGES, []);

        foreach (CodeLibrary::query()->where('status', CodeLibrary::READY)->where('enabled', true)->orderBy('name')->get(['language', 'name', 'version']) as $library) {
            $choices[$library->language][] = ['name' => $library->name, 'version' => $library->version];
        }

        return $choices;
    }

    /**
     * @return array{ok: bool, version?: string|null, size?: int|null, source?: string|null, error?: string}
     */
    private static function javascript(CodeLibrary $library): array
    {
        try {
            $answer = Http::timeout(200)->acceptJson()
                ->post(rtrim((string) config('services.code.url'), '/').'/install', ['name' => $library->name, 'version' => $library->asked]);
        } catch (ConnectionException) {
            return ['ok' => false, 'error' => 'The code runner could not be reached: it runs as the "code" container.'];
        }

        $got = $answer->json();

        if (! is_array($got) || ! isset($got['ok'])) {
            return ['ok' => false, 'error' => "The code runner answered with {$answer->status()}."];
        }

        return $got['ok'] === true
            ? ['ok' => true, 'version' => is_string($got['version'] ?? null) ? $got['version'] : null, 'size' => is_int($got['size'] ?? null) ? $got['size'] : null, 'source' => is_string($got['source'] ?? null) ? $got['source'] : null]
            : ['ok' => false, 'error' => is_string($got['error'] ?? null) ? $got['error'] : 'It could not be installed.'];
    }

    /**
     * @return array{ok: bool, version?: string|null, size?: int|null, error?: string}
     */
    private static function python(CodeLibrary $library): array
    {
        if (! class_exists(PythonRunner::class)) {
            return ['ok' => false, 'error' => 'The Python runner is not set up on this server yet.'];
        }

        return PythonRunner::install($library->name, $library->asked);
    }
}
