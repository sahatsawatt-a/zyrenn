<?php

namespace App\Mcp\Tools\BrowserChecks;

use App\Mcp\Tools\BrowserCheckTool;
use App\Support\Browser\SuiteRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
Stop a run of browser checks part way -- one started by mistake, or a demo that has filmed enough. A run
still waiting never starts. A running one stops after the step it is doing: the browser closes, the checks
already done keep their verdicts and pictures, a demo's film so far is kept (marked partial), and the run
ends "stopped" -- not "failed", and filing no bug tickets. Answers with the run as get-browser-run does;
a running one may take a few seconds more to end, so read it again with get-browser-run.
TEXT)]
class StopBrowserRun extends BrowserCheckTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $schema->string()->max(16)->description('The run\'s ref_id, as run-browser-suite gave it.')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $run = $this->runCalled($this->targetOwner($request, changes: true), trim((string) $request->get('ref_id')));

        if ($run->ended()) {
            return Response::error("Run {$run->ref_id} has already ended ({$run->state}).");
        }

        return Response::structured($this->runDescribed(SuiteRun::stop($run, $this->targetUser($request))));
    }
}
