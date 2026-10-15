<?php

namespace App\Mcp\Tools\BrowserChecks;

use App\Mcp\Tools\BrowserCheckTool;
use App\Support\Browser\BrowserCheck;
use App\Support\Browser\LivePages;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use RuntimeException;

#[Description(<<<'TEXT'
Do one step on a page open-browser-page opened, there and once, and see what came of it and what is
on the page now -- each field, button, link and piece of writing by the name a step goes by. With
"keep", a step that works is added to the check the page was opened for; one that fails is not, and
says why.
TEXT)]
class DoBrowserStep extends BrowserCheckTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'page' => $schema->string()->max(40)->description('The page, as open-browser-page gave it.')->required(),
            'step' => $this->stepSchema($schema)->required(),
            'keep' => $schema->boolean()->default(false)->description('Add it to the check being built, once it has worked.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);
        $id = trim((string) $request->get('page'));
        $suite = LivePages::suiteOf($user, $id);

        // Over MCP as on the page: only those who may change the suite
        if (! $user->can('update', $suite)) {
            return Response::error('Only the owners and editors of the suite\'s project can build on it.');
        }

        $validated = validator(['steps' => [$request->get('step')]], BrowserCheck::stepRules('steps'))->validate();

        try {
            $page = LivePages::step($user, $id, $validated['steps'][0], (bool) $request->get('keep', false));
        } catch (RuntimeException $problem) {
            return Response::error($problem->getMessage());
        }

        return Response::structured([
            'step' => BrowserCheck::say($validated['steps'][0]),
            'worked' => $page['step']['ok'],
            'why' => $page['step']['error'] ?? null,
            'kept' => (bool) ($page['kept'] ?? false),
            ...$this->pageDescribed($page),
        ]);
    }
}
