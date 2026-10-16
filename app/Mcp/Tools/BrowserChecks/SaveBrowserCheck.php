<?php

namespace App\Mcp\Tools\BrowserChecks;

use App\Mcp\Tools\BrowserCheckTool;
use App\Support\Browser\BrowserCheck;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
Write one whole check of a suite at once: made when the suite has no check of that name, replaced
when it does -- the suite's other checks are left as they are. "steps" are all of it, in order,
starting with {"do": "open", "url": "https://..."}; see save-browser-suite for what a step is. To add
to a check or change a step of it, edit-browser-check; to build one step by step on the page itself,
open-browser-page and do-browser-step.
TEXT)]
class SaveBrowserCheck extends BrowserCheckTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'suite' => $schema->string()->max(200)->description('The suite\'s ref_id or name.')->required(),
            'check' => $schema->string()->max(200)->description('What it checks, e.g. "Logging in": the check of that name is made or replaced.')->required(),
            'size' => $schema->string()->enum(BrowserCheck::SIZES)->description('The screen it is done on: "desktop" (the default), "tablet" or "phone".'),
            'steps' => $schema->array()->items($this->stepSchema($schema))->description('Every step, in order.')->required(),
            'as' => $schema->string()->max(40)->description('In a suite that signs in (save-browser-suite "sign_ins"): which sign-in it starts as, when not the first. "" for the first again.'),
            'fresh' => $schema->boolean()->description('true: it starts signed out, in a suite that signs in -- a check of the sign-in page itself.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $suite = $this->suiteCalled($this->targetOwner($request, changes: true), trim((string) $request->get('suite')));
        $title = trim((string) $request->get('check'));

        if ($title === '') {
            return Response::error('A check needs a name: "check".');
        }

        $before = collect($suite->checks)->firstWhere('title', $title);
        $as = $request->get('as') ?? $before['as'] ?? null;
        $check = [
            'title' => $title,
            'size' => $request->get('size') ?? $before['size'] ?? 'desktop',
            'steps' => $request->get('steps') ?? [],
            ...(is_string($as) && $as !== '' ? ['as' => $as] : []),
            ...(($request->get('fresh') ?? $before['fresh'] ?? false) === true ? ['fresh' => true] : []),
        ];

        if (isset($check['as']) && ! in_array($check['as'], array_column($suite->signIns(), 'name'), true)) {
            return Response::error('This suite has no sign-in called "'.$check['as'].'". Its sign-ins: '.(implode(', ', array_column($suite->signIns(), 'name')) ?: 'none -- add them with save-browser-suite "sign_ins"').'.');
        }
        $checks = $before === null
            ? [...$suite->checks, $check]
            : array_map(fn (array $one) => $one['title'] === $title ? $check + ['id' => $one['id']] : $one, $suite->checks);

        validator(['checks' => $checks], $suite::rules(checkIds: false))->validate();
        $suite->take(['checks' => $checks])->save();

        return Response::structured([
            'suite' => $suite->title,
            ($before === null ? 'made' : 'replaced') => $this->checkDescribed($this->checkCalled($suite, $title)),
        ]);
    }
}
