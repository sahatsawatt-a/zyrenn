<?php

namespace App\Support\Workflow\Steps;

use App\Models\Browser\BrowserSuite;
use App\Support\Browser\BrowserCheck;
use App\Support\Browser\SuiteRun;

/**
 * Run browser checks: one of the owner's suites, done in the browser now,
 * and what came of it given out -- whether every check passed, a sentence
 * saying how it went, and each check's verdict and film -- for the steps
 * after it to send on, or to branch on. A check that fails does not fail
 * the step; "passed" says so.
 */
final class BrowserChecks implements Step
{
    public function run(StepContext $step): StepResult
    {
        $named = trim($step->text('suite'));
        $suite = $step->owner()->browserSuites()
            ->where(fn ($query) => $query->where('ref_id', $named)->orWhere('title', $named))
            ->first();

        if (! $suite instanceof BrowserSuite) {
            throw new StepFailed("There is no suite of browser checks called \"{$named}\".");
        }

        $only = trim($step->text('check'));
        $checks = $only === ''
            ? []
            : array_column(array_filter($suite->checks, fn (array $check) => $check['title'] === $only), 'id');

        if ($only !== '' && $checks === []) {
            throw new StepFailed("The suite \"{$suite->title}\" has no check called \"{$only}\".");
        }

        foreach ($suite->checks as $check) {
            if ($checks === [] || in_array($check['id'], $checks, true)) {
                $missing = BrowserCheck::unready($check);

                if ($missing !== null) {
                    throw new StepFailed("Before it can run -- {$missing}.");
                }
            }
        }

        $film = $step->setting('film') === 'yes';
        $run = SuiteRun::carryOut(SuiteRun::start(
            $suite,
            $step->actor(),
            SuiteRun::settings($film ? 'steady' : 'quick', captions: $film, record: $film, checks: $checks),
            SuiteRun::WORKFLOW,
        ));

        $results = $run->results ?? [];

        return new StepResult([
            'passed' => collect($results)->every(fn (array $check) => $check['ok']),
            'summary' => SuiteRun::summary($run),
            'failed' => collect($results)->reject(fn (array $check) => $check['ok'])->count(),
            'checks' => array_map(fn (array $check) => [
                'title' => $check['title'],
                'passed' => $check['ok'],
                'says' => SuiteRun::verdict($check),
                'film' => $check['video']['url'] ?? null,
            ], $results),
            'tickets' => array_column($run->tickets ?? [], 'number'),
        ]);
    }
}
