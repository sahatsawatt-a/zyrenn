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
#[Description('How a run of browser checks went, or is going: a sentence for the whole run, each check\'s verdict (where it stopped and why), the Drive addresses of its pictures and films, and any bug tickets it filed.')]
class GetBrowserRun extends BrowserCheckTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $schema->string()->max(16)->description('The run\'s ref_id, as run-browser-suite gave it.')->required(),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        return Response::structured($this->runDescribed($this->runCalled($this->targetOwner($request), trim((string) $request->get('ref_id')))));
    }
}
