<?php

namespace App\Mcp\Tools\BrowserChecks;

use App\Mcp\Tools\BrowserCheckTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Read one suite of browser checks in full: each check\'s steps (what each does, and in words), when it runs by itself, whether a failure files a ticket, the names of its hidden values (never their values), and its last few runs.')]
class GetBrowserSuite extends BrowserCheckTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'suite' => $schema->string()->max(200)->description('The suite\'s ref_id or name.')->required(),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $suite = $this->suiteCalled($this->targetOwner($request), trim((string) $request->get('suite')));

        return Response::structured($this->suiteDescribed($suite) + [
            'runs' => $suite->runs()->latest('id')->limit(5)->get()->map(fn ($run) => $this->runDescribed($run))->all(),
        ]);
    }
}
