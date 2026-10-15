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
Change one check of a suite without writing it out again: take steps out ("remove", by their numbers),
put one in place of another ("replace"), and add steps ("add") at the end or before step "at" -- done
in that order, the numbers being the steps as they were. "notes" sets what a step tells whoever watches
a demo video, leaving the step as it is ("" takes a note away); "waits" sets how long a demo stays on
a step, in seconds (null goes back to the default). Also "rename" it, or give it another
"size".
Answers with the check as it is now, each step numbered and in words.
TEXT)]
class EditBrowserCheck extends BrowserCheckTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'suite' => $schema->string()->max(200)->description('The suite\'s ref_id or name.')->required(),
            'check' => $schema->string()->max(200)->description('The check\'s name.')->required(),
            'remove' => $schema->array()->items($schema->integer()->min(1))->description('The numbers of the steps to take out, e.g. [3].'),
            'replace' => $schema->array()->items($schema->object([
                'step' => $schema->integer()->min(1)->required(),
                'with' => $this->stepSchema($schema)->required(),
            ]))->description('Steps put in place of others, by number.'),
            'notes' => $schema->array()->items($schema->object([
                'step' => $schema->integer()->min(1)->required(),
                'note' => $schema->string()->max(600)->required(),
            ]))->description('Notes put on steps, by number: [{"step": 2, "note": "Drop the company file here."}].'),
            'waits' => $schema->array()->items($schema->object([
                'step' => $schema->integer()->min(1)->required(),
                'wait' => $schema->number()->min(0)->max(30)->nullable()->required(),
            ]))->description('How long a demo video stays on steps, by number, in seconds: [{"step": 5, "wait": 4}]. null: the default again.'),
            'add' => $schema->array()->items($this->stepSchema($schema))->description('Steps to add, in order.'),
            'at' => $schema->integer()->min(1)->description('The number of the step the added ones go before; left out, they go at the end.'),
            'rename' => $schema->string()->max(200)->description('Its new name.'),
            'size' => $schema->string()->enum(BrowserCheck::SIZES)->description('The screen it is done on.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $suite = $this->suiteCalled($this->targetOwner($request, changes: true), trim((string) $request->get('suite')));
        $check = $this->checkCalled($suite, trim((string) $request->get('check')));
        $steps = $check['steps'];
        $count = count($steps);

        $remove = array_map('intval', (array) ($request->get('remove') ?? []));
        $replace = (array) ($request->get('replace') ?? []);
        $notes = (array) ($request->get('notes') ?? []);
        $waits = (array) ($request->get('waits') ?? []);

        foreach ([...$remove, ...array_map(fn ($one) => (int) ($one['step'] ?? 0), [...$replace, ...$notes, ...$waits])] as $number) {
            if ($number < 1 || $number > $count) {
                return Response::error("The check has steps 1 to {$count}: there is no step {$number}.");
            }
        }

        // By the numbers the steps had: what is replaced first, then what goes
        foreach ($replace as $one) {
            $steps[(int) $one['step'] - 1] = $one['with'];
        }

        foreach ($notes as $one) {
            $steps[(int) $one['step'] - 1]['note'] = (string) ($one['note'] ?? '');
        }

        foreach ($waits as $one) {
            $steps[(int) $one['step'] - 1]['wait'] = $one['wait'] ?? null;
        }

        $kept = [];

        foreach ($steps as $at => $step) {
            if (! in_array($at + 1, $remove, true)) {
                $kept[] = ['was' => $at + 1, 'step' => $step];
            }
        }

        $add = (array) ($request->get('add') ?? []);
        $before = $request->get('at');
        $place = $before === null
            ? count($kept)
            : collect($kept)->search(fn (array $one) => $one['was'] >= (int) $before);
        $place = $place === false ? count($kept) : $place;
        array_splice($kept, $place, 0, array_map(fn ($step) => ['was' => null, 'step' => $step], $add));

        $changed = [
            ...$check,
            'title' => trim((string) ($request->get('rename') ?? $check['title'])) ?: $check['title'],
            'size' => $request->get('size') ?? $check['size'] ?? 'desktop',
            'steps' => array_column($kept, 'step'),
        ];
        $checks = array_map(fn (array $one) => $one['id'] === $check['id'] ? $changed : $one, $suite->checks);

        validator(['checks' => $checks], $suite::rules())->validate();
        $suite->take(['checks' => $checks])->save();

        return Response::structured(['suite' => $suite->title, 'check' => $this->checkDescribed($this->checkCalled($suite, $changed['title']))]);
    }
}
