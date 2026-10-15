<?php

namespace App\Mcp\Tools\BrowserChecks;

use App\Mcp\Tools\BrowserCheckTool;
use App\Models\Browser\BrowserSuite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the suites of browser checks -- the user\'s own, or a project\'s -- each with its checks by name, when it runs by itself, and how its last run went.')]
class ListBrowserSuites extends BrowserCheckTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): ResponseFactory
    {
        $owner = $this->targetOwner($request);

        return Response::structured([
            'suites' => $owner->browserSuites()->latest('updated_at')->get()->map(function (BrowserSuite $suite) {
                $last = $suite->runs()->latest('id')->first();

                return [
                    'ref_id' => $suite->ref_id,
                    'title' => $suite->title,
                    'checks' => array_column($suite->checks, 'title'),
                    'runs_by_itself' => $suite->settings['every'] ?? 'off',
                    'last_run' => $last ? ['ref_id' => $last->ref_id, 'state' => $last->state, 'at' => $last->created_at?->toIso8601String()] : null,
                ];
            })->all(),
        ]);
    }
}
