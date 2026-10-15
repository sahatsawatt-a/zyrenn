<?php

namespace App\Support\Browser;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The app's own browser suites (tests/e2e), as the runner leaves them: it
 * writes each run down in storage/framework/testing/e2e/runs as it goes, and,
 * started with `node tests/e2e/run.mjs --listen`, takes runs asked for here
 * from requests/ and says it is waiting in listener.json.
 *
 * Nothing here starts a process: the runner is on this machine, beside the
 * browser it drives, and only reads what is left for it.
 */
class BrowserTests
{
    /** A runner that has not said it is there for this long has gone. */
    private const GONE_AFTER_MS = 8000;

    /** Where the runner leaves things; somewhere else for a test of this. */
    public static ?string $root = null;

    public static function folder(string $path = ''): string
    {
        $root = self::$root ?? storage_path('framework/testing/e2e');

        return $root.($path === '' ? '' : '/'.$path);
    }

    /**
     * The runner waiting for runs, when there is one: its suites, how long each
     * took last, and the run it is busy with.
     *
     * @return array{since: int, busy: ?string, suites: list<string>, alone: list<string>, timings: array<string, float>}|null
     */
    public static function listener(): ?array
    {
        $said = self::read(self::folder('listener.json'));

        if ($said === null || (int) ($said['beat'] ?? 0) < now()->getTimestampMs() - self::GONE_AFTER_MS) {
            return null;
        }

        return [
            'since' => (int) $said['since'],
            'busy' => $said['busy'] ?? null,
            'suites' => array_values($said['suites'] ?? []),
            'alone' => array_values($said['alone'] ?? []),
            'timings' => (array) ($said['timings'] ?? []),
        ];
    }

    /**
     * The runs kept, newest first, each in short: when, from where, and how
     * many suites passed and failed.
     *
     * @return list<array<string, mixed>>
     */
    public static function runs(): array
    {
        $runs = [];

        foreach (glob(self::folder('runs/*.json')) ?: [] as $file) {
            $run = self::read($file);

            if ($run === null) {
                continue;
            }

            $states = array_count_values(array_column($run['suites'] ?? [], 'state'));
            $runs[] = [
                'id' => $run['id'],
                'from' => $run['from'] ?? 'cli',
                'began' => $run['began'] ?? null,
                'ended' => $run['ended'] ?? null,
                'suites' => count($run['suites'] ?? []),
                'passed' => $states['passed'] ?? 0,
                'failed' => $states['failed'] ?? 0,
                'stopped' => (bool) ($run['stopped'] ?? false),
            ];
        }

        usort($runs, fn ($a, $b) => strcmp($b['id'], $a['id']));

        return $runs;
    }

    /**
     * One run as the runner wrote it, with the pictures each suite took.
     *
     * @return array<string, mixed>|null
     */
    public static function run(string $id): ?array
    {
        if (! self::isId($id)) {
            return null;
        }

        $run = self::read(self::folder("runs/{$id}.json"));

        if ($run === null) {
            return null;
        }

        foreach ($run['suites'] as &$suite) {
            $suite['shots'] = array_map(
                fn (string $path) => basename($path),
                glob(self::folder("runs/{$id}/{$suite['name']}/*.png")) ?: [],
            );
        }

        return $run;
    }

    /**
     * Asks the runner for a run of `suites` (all of them when none), in
     * `lanes` lanes, or one at a time in a window to `watch`. Answers with
     * the run's id.
     *
     * @param  list<string>  $suites
     */
    public static function ask(array $suites, int $lanes, bool $watch): string
    {
        $id = now()->utc()->format('Ymd\THis').Str::lower(Str::random(4));
        File::ensureDirectoryExists(self::folder('requests'));
        File::put(self::folder("requests/{$id}.json"), json_encode([
            'suites' => $suites,
            'lanes' => $lanes,
            'watch' => $watch,
        ], JSON_THROW_ON_ERROR));

        return $id;
    }

    /** Asks the runner to stop a run, the suites going now and the rest. */
    public static function stop(string $id): void
    {
        if (self::isId($id)) {
            File::put(self::folder("requests/{$id}.stop"), '');
        }
    }

    /** Where one of a run's pictures is, when it is one. */
    public static function shot(string $id, string $suite, string $file): ?string
    {
        if (! self::isId($id) || ! preg_match('/^[a-z0-9-]+$/', $suite) || ! preg_match('/^[\w.-]+\.png$/', $file)) {
            return null;
        }

        $path = self::folder("runs/{$id}/{$suite}/{$file}");

        return is_file($path) ? $path : null;
    }

    private static function isId(string $id): bool
    {
        return (bool) preg_match('/^\d{8}T\d{6}[a-z0-9]{4}$/', $id);
    }

    /** @return array<string, mixed>|null */
    private static function read(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
