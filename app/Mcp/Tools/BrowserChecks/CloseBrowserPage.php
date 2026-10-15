<?php

namespace App\Mcp\Tools\BrowserChecks;

use App\Mcp\Tools\BrowserCheckTool;
use App\Support\Browser\LivePages;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Close a page open-browser-page opened, once the check is built: what was kept stays in the check.')]
class CloseBrowserPage extends BrowserCheckTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'page' => $schema->string()->max(40)->description('The page, as open-browser-page gave it.')->required(),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        LivePages::close($this->targetUser($request), trim((string) $request->get('page')));

        return Response::structured(['closed' => true]);
    }
}
