<?php

namespace App\Mcp\Tools\BrowserChecks;

use App\Mcp\Tools\BrowserCheckTool;
use App\Support\Browser\BrowserCheck;
use App\Support\Browser\LivePages;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use RuntimeException;

#[Description(<<<'TEXT'
Open a page in the real browser and keep it open, to build a check on it step by step as a person
clicking through it would: do-browser-step does each next step there, once, and answers with what is
on the page then. Give "check" to build on that check of the suite -- the page opens at its steps so
far, and do-browser-step with "keep" adds each step that works to it (a check of that name is made,
from "steps", when there is none). Or give only "steps" to try a page without keeping anything.
One open page a user: opening another closes it. It closes itself after five quiet minutes, and
close-browser-page closes it when done.
TEXT)]
class OpenBrowserPage extends BrowserCheckTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'suite' => $schema->string()->max(200)->description('The suite\'s ref_id or name: its hidden values are what {{name}} types.')->required(),
            'check' => $schema->string()->max(200)->description('The check to build on, by name.'),
            'steps' => $schema->array()->items($this->stepSchema($schema))->description('Where to start, beginning with {"do": "open", "url": "https://..."}. Left out with "check", the check\'s own steps.'),
            'size' => $schema->string()->enum(BrowserCheck::SIZES)->description('The screen: "desktop", "tablet" or "phone". Left out, the check\'s own, or desktop.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);
        $suite = $this->suiteCalled($this->targetOwner($request, changes: true), trim((string) $request->get('suite')));
        $title = trim((string) $request->get('check', ''));
        $given = $request->get('steps');
        $check = $title === '' ? null : collect($suite->checks)->firstWhere('title', $title);

        if ($given !== null) {
            validator(['steps' => $given], BrowserCheck::stepRules('steps', complete: false))->validate();
        }

        if ($title !== '' && $check === null) {
            if (! is_array($given)) {
                return Response::error("\"{$suite->title}\" has no check called \"{$title}\": give \"steps\" to start it with.");
            }

            $check = ['id' => Str::lower(Str::random(8)), 'title' => $title, 'size' => $request->get('size') ?? 'desktop', 'steps' => $given];
            $suite->take(['checks' => [...$suite->checks, $check]])->save();
        }

        $steps = $check !== null && $given === null ? $check['steps'] : ($given ?? []);
        $size = $request->get('size') ?? $check['size'] ?? 'desktop';

        try {
            $page = LivePages::open($user, $suite, array_values($steps), $size, $check['id'] ?? null, $title);
        } catch (RuntimeException $problem) {
            return Response::error($problem->getMessage());
        }

        return Response::structured([
            'page' => $page['id'],
            'building' => $check === null ? null : $this->checkDescribed($this->checkCalled($suite, $check['title'])),
            ...$this->pageDescribed($page),
        ]);
    }
}
