<?php

namespace App\Support\Browser;

use App\Models\Browser\BrowserRun;
use Illuminate\Support\Facades\DB;

/**
 * How long each step of a run took, and waited on the site, kept by the
 * step's own id -- the last few runs of each, so how long a step usually
 * needs can be told (and a "Gives up after" suggested from it). A trial run
 * keeps none.
 */
final class StepTimings
{
    /** How many runs of each step are kept. */
    public const KEPT = 50;

    private const TABLE = 'browser_step_timings';

    /** Keeps what each step of `$run` took, by the suite's steps as they were when it ran. */
    public static function keep(BrowserRun $run): void
    {
        if ($run->trial ?? false) {
            return;
        }

        $suite = $run->suite;
        $checks = array_column($suite->checks ?? [], null, 'id');
        $rows = [];

        foreach ($run->results ?? [] as $result) {
            $steps = $checks[$result['id'] ?? '']['steps'] ?? [];

            foreach ($result['steps'] ?? [] as $at => $done) {
                $step = $steps[$at] ?? null;

                if (! is_array($step) || ! isset($step['id']) || ! is_array($done)) {
                    continue;
                }

                $rows[] = [
                    'browser_suite_id' => $suite->id,
                    'browser_run_id' => $run->id,
                    'check_id' => (string) $result['id'],
                    'step_id' => (string) $step['id'],
                    'suite_version' => $run->suite_version,
                    'kind' => (string) $step['do'],
                    'took_ms' => max(0, (int) ($done['took'] ?? 0)),
                    'waited_ms' => isset($done['waited']) ? max(0, (int) $done['waited']) : null,
                    'ok' => (bool) ($done['ok'] ?? false),
                    'timed_out' => (bool) ($done['timedOut'] ?? false),
                    'created_at' => now(),
                ];
            }
        }

        if ($rows === []) {
            return;
        }

        DB::table(self::TABLE)->insert($rows);

        // Each step keeps only its last few runs
        foreach (array_unique(array_column($rows, 'step_id')) as $stepId) {
            $oldest = DB::table(self::TABLE)
                ->where('browser_suite_id', $suite->id)
                ->where('step_id', $stepId)
                ->orderByDesc('id')
                ->skip(self::KEPT)
                ->value('id');

            if ($oldest !== null) {
                DB::table(self::TABLE)->where('browser_suite_id', $suite->id)->where('step_id', $stepId)->where('id', '<=', $oldest)->delete();
            }
        }
    }
}
