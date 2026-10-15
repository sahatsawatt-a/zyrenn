<?php

namespace App\Mcp\Tools\BrowserChecks;

use App\Mcp\Tools\BrowserCheckTool;
use App\Support\Browser\BrowserCheck;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

#[IsReadOnly]
#[Description(<<<'TEXT'
Open a page in the real browser checks use, go through some steps first if given (logging in, say),
and see what is on the page they lead to: each field, button, link, tick box and piece of writing by
the very name a step's "target" would give it. Use it to write a check's next step without guessing.
"suite" lends its hidden values to steps that type {{name}}. Only public sites can be reached.
TEXT)]
class LookAtPage extends BrowserCheckTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'steps' => $schema->array()->items($this->stepSchema($schema))->description('The steps that lead to the page, starting with {"do": "open", "url": "https://..."}.')->required(),
            'suite' => $schema->string()->max(200)->description('A suite whose hidden values the steps may type.'),
            'size' => $schema->string()->enum(BrowserCheck::SIZES)->description('The screen to draw it on: "desktop" (the default), "tablet" or "phone" -- a site may show other things on a phone.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $owner = $this->targetOwner($request);
        $validated = $request->validate([
            ...BrowserCheck::stepRules('steps', complete: false),
            'size' => ['nullable', 'in:'.implode(',', BrowserCheck::SIZES)],
        ]);
        $steps = $validated['steps'];

        if ($steps[0]['do'] !== 'open') {
            return Response::error('Start with {"do": "open", "url": "https://..."}, so there is a page to look at.');
        }

        if (($missing = BrowserCheck::unready(['title' => 'To get to the page', 'steps' => $steps])) !== null) {
            return Response::error($missing);
        }

        $named = trim((string) $request->get('suite', ''));
        $values = $named === '' ? [] : $this->suiteCalled($owner, $named)->valuesFor($steps);

        try {
            $look = BrowserCheck::look($steps, $values, $validated['size'] ?? 'desktop');
        } catch (RuntimeException $problem) {
            return Response::error($problem->getMessage());
        }

        return Response::structured($this->pageDescribed($look));
    }
}
