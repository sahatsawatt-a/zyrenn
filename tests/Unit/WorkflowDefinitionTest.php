<?php

namespace Tests\Unit;

use App\Support\Formula\FormulaError;
use App\Support\Workflow\Definition;
use App\Support\Workflow\DefinitionInvalid;
use App\Support\Workflow\NodeStatus;
use App\Support\Workflow\NodeTypes;
use App\Support\Workflow\RunScope;
use App\Support\Workflow\RunStatus;
use App\Support\Workflow\Template;
use Tests\TestCase;

/**
 * What a workflow definition may be: refused outright when it isn't a
 * workflow, kept as a draft when it is one but unfinished, and published
 * only when nothing stands in the way.
 */
class WorkflowDefinitionTest extends TestCase
{
    private function node(string $id, string $type, array $more = []): array
    {
        return ['id' => $id, 'key' => $id, 'type' => $type, 'position' => ['x' => 0, 'y' => 0], ...$more];
    }

    private function edge(string $from, string $to, ?string $handle = null): array
    {
        return ['id' => "{$from}-{$handle}-{$to}", 'source' => $from, 'sourceHandle' => $handle, 'target' => $to];
    }

    /**
     * start → check (If) → true: log_big, false: log_small
     */
    private function branching(string $condition = 'trigger.total > 100'): array
    {
        return Definition::normalize([
            'nodes' => [
                $this->node('start', 'trigger.manual', ['config' => ['payload' => '{"total": 120}']]),
                $this->node('check', 'logic.if', ['config' => ['condition' => $condition]]),
                $this->node('log_big', 'output.log', ['config' => ['message' => 'Big: {{ trigger.total }}']]),
                $this->node('log_small', 'output.log', ['config' => ['message' => 'Small']]),
            ],
            'edges' => [
                $this->edge('start', 'check'),
                $this->edge('check', 'log_big', 'true'),
                $this->edge('check', 'log_small', 'false'),
            ],
        ]);
    }

    private function messages(array $definition): array
    {
        return array_column(Definition::problems($definition), 'message');
    }

    public function test_a_service_with_many_steps_sets_every_one_of_them_out_in_a_part()
    {
        foreach (NodeTypes::sections() as $group => $parts) {
            $placed = array_merge(...array_values($parts));
            $kinds = array_keys(array_filter(NodeTypes::all(), fn (array $type) => $type['group'] === $group));

            // Each of the group's kinds once, and nothing that is not one
            $this->assertSame([], array_diff($kinds, $placed), "{$group}: in no part");
            $this->assertSame([], array_diff($placed, $kinds), "{$group}: not a kind of it");
            $this->assertSame($placed, array_values(array_unique($placed)), "{$group}: in two parts");
        }
    }

    public function test_a_new_workflow_starts_with_a_trigger_and_nothing_in_its_way()
    {
        $blank = Definition::blank();

        $this->assertSame('trigger.manual', $blank['nodes'][0]['type']);
        $this->assertSame([], Definition::problems($blank));
    }

    public function test_a_kept_definition_is_filled_in_to_its_defaults_and_loses_what_is_unknown()
    {
        $kept = Definition::normalize([
            'nodes' => [$this->node('log', 'output.log', ['config' => ['message' => 'Hi', 'secret' => 'x'], 'extra' => true])],
            'edges' => [],
            'whatever' => 1,
        ]);

        $this->assertSame(['message' => 'Hi', 'level' => 'info'], $kept['nodes'][0]['config']);
        $this->assertSame('Log', $kept['nodes'][0]['label']);
        $this->assertSame(1, $kept['nodes'][0]['nodeVersion']);
        $this->assertSame('stop', $kept['nodes'][0]['onError']);
        $this->assertArrayNotHasKey('extra', $kept['nodes'][0]);
        $this->assertArrayNotHasKey('whatever', $kept);
        // Kept as it is the second time round
        $this->assertSame($kept, Definition::normalize($kept));
    }

    public function test_what_is_not_a_workflow_at_all_is_refused_with_every_reason()
    {
        try {
            Definition::normalize([
                'nodes' => [
                    $this->node('a', 'trigger.manual'),
                    $this->node('a', 'output.log'),
                    $this->node('b', 'no.such.thing'),
                    $this->node('c', 'output.log', ['key' => 'Not A Key']),
                    $this->node('d', 'output.log', ['nodeVersion' => 99]),
                    $this->node('e', 'output.log', ['config' => ['level' => 'shouting']]),
                ],
                'edges' => [],
            ]);
            $this->fail('It was kept.');
        } catch (DefinitionInvalid $e) {
            $this->assertCount(5, $e->errors);
            $this->assertStringContainsString('Two steps have the id a', implode(' ', $e->errors));
            $this->assertStringContainsString('"no.such.thing"', implode(' ', $e->errors));
            $this->assertStringContainsString('version this app does not have', implode(' ', $e->errors));
            $this->assertStringContainsString('not one of its choices', implode(' ', $e->errors));
        }
    }

    public function test_a_connection_must_join_two_steps_that_are_there_by_ways_they_have()
    {
        $nodes = [$this->node('start', 'trigger.manual'), $this->node('check', 'logic.if'), $this->node('log', 'output.log')];

        $refused = function (array $edge, string $expected) use ($nodes) {
            try {
                Definition::normalize(['nodes' => $nodes, 'edges' => [$edge]]);
                $this->fail("Kept: {$expected}");
            } catch (DefinitionInvalid $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        };

        $refused($this->edge('start', 'ghost'), "isn't there");
        $refused($this->edge('log', 'log'), 'back to itself');
        $refused($this->edge('check', 'log', 'maybe'), 'no way out called "maybe"');
        $refused($this->edge('log', 'start'), 'it starts a run');

        // An If's ways out must be named; a single way out is "out" when left unnamed
        $refused($this->edge('check', 'log'), 'no way out called null');
        $kept = Definition::normalize(['nodes' => $nodes, 'edges' => [$this->edge('start', 'check'), ['id' => 'again', 'source' => 'start', 'target' => 'check']]]);
        $this->assertSame('out', $kept['edges'][0]['sourceHandle']);
        $this->assertSame('in', $kept['edges'][0]['targetHandle']);
        // The same connection twice is kept once
        $this->assertCount(1, $kept['edges']);
    }

    public function test_a_finished_workflow_has_nothing_in_its_way()
    {
        $this->assertSame([], Definition::problems($this->branching()));
    }

    public function test_an_unfinished_draft_is_kept_but_says_what_stands_in_the_way_of_publishing()
    {
        $draft = Definition::normalize([
            'nodes' => [
                $this->node('check', 'logic.if'),
                $this->node('orphan', 'output.log', ['config' => ['message' => 'Hi']]),
            ],
            'edges' => [$this->edge('check', 'orphan', 'true')],
        ]);

        $messages = $this->messages($draft);

        $this->assertContains('Nothing starts this workflow. Add a trigger, such as "Run by hand".', $messages);
        $this->assertContains('Give it a Condition.', $messages);
        $this->assertContains('Nothing leads to this step, so it would never run.', $messages);
    }

    public function test_a_formula_that_cannot_be_read_is_named_with_where_it_went_wrong()
    {
        $problems = Definition::problems($this->branching('trigger.total >'));

        $this->assertSame('check', $problems[0]['node']);
        $this->assertStringStartsWith('Condition: ', $problems[0]['message']);

        $broken = Definition::normalize([
            'nodes' => [
                $this->node('start', 'trigger.manual', ['config' => ['payload' => '[1, 2]']]),
                $this->node('log', 'output.log', ['config' => ['message' => 'Total {{ trigger.total']]),
            ],
            'edges' => [$this->edge('start', 'log')],
        ]);

        $this->assertSame([
            ['message' => 'Test data: it should be a JSON object, like {"name": "Ada"}.', 'node' => 'start'],
            ['message' => 'Message: A {{ at character 7 is never closed with }}.', 'node' => 'log'],
        ], Definition::problems($broken));
    }

    public function test_a_step_reads_only_the_output_of_steps_before_it()
    {
        $definition = Definition::normalize([
            'nodes' => [
                $this->node('start', 'trigger.manual'),
                $this->node('first', 'data.set', ['config' => ['fields' => [['name' => 'total', 'value' => '{{ node("second").x }}']]]]),
                $this->node('second', 'data.set', ['config' => ['fields' => [['name' => 'x', 'value' => '{{ node("first").total }} {{ node("nobody").y }}']]]]),
            ],
            'edges' => [$this->edge('start', 'first'), $this->edge('first', 'second')],
        ]);

        $this->assertSame([
            ['message' => 'Values: node("second") does not come before this step, so its output isn\'t there yet.', 'node' => 'first'],
            ['message' => 'Values: there is no step with the key "nobody".', 'node' => 'second'],
        ], Definition::problems($definition));
    }

    public function test_values_need_names_a_formula_can_read_once_each()
    {
        $definition = Definition::normalize([
            'nodes' => [
                $this->node('start', 'trigger.manual'),
                $this->node('set', 'data.set', ['config' => ['fields' => [
                    ['name' => 'total', 'value' => '1'],
                    ['name' => 'total', 'value' => '2'],
                    ['name' => 'grand total', 'value' => '3'],
                    ['name' => '', 'value' => '4'],
                ]]]),
            ],
            'edges' => [$this->edge('start', 'set')],
        ]);

        $this->assertSame([
            'Values: "total" is named twice.',
            'Values: "grand total" can only have letters, digits and _, so a formula can read it.',
            'Values: every value needs a name.',
        ], $this->messages($definition));
    }

    public function test_steps_that_go_round_in_a_circle_are_named_in_order()
    {
        $definition = Definition::normalize([
            'nodes' => [
                $this->node('start', 'trigger.manual'),
                $this->node('a', 'output.log', ['label' => 'A', 'config' => ['message' => 'a']]),
                $this->node('b', 'output.log', ['label' => 'B', 'config' => ['message' => 'b']]),
            ],
            'edges' => [$this->edge('start', 'a'), $this->edge('a', 'b'), $this->edge('b', 'a')],
        ]);

        $this->assertContains('These steps go round in a circle: A → B.', $this->messages($definition));
    }

    public function test_a_definition_past_its_limits_is_refused()
    {
        $this->expectException(DefinitionInvalid::class);

        Definition::normalize(['nodes' => array_map(fn ($i) => $this->node("n{$i}", 'output.log'), range(1, 201))]);
    }

    public function test_formulas_are_found_in_a_template()
    {
        $this->assertSame(['input.id', 'node("a").total * 2'], Template::formulas('Order {{ input.id }} is {{node("a").total * 2}}'));
        $this->assertSame([], Template::formulas('No formulas here'));
    }

    public function test_only_the_listed_moves_between_states_are_allowed()
    {
        $this->assertTrue(RunStatus::Queued->canBecome(RunStatus::Running));
        $this->assertFalse(RunStatus::Succeeded->canBecome(RunStatus::Running));
        $this->assertTrue(RunStatus::Cancelled->isFinal());

        $this->assertTrue(NodeStatus::Pending->canBecome(NodeStatus::Skipped));
        $this->assertFalse(NodeStatus::Pending->canBecome(NodeStatus::Running));
        // A worker that died hands its step back
        $this->assertTrue(NodeStatus::Running->canBecome(NodeStatus::Queued));
        $this->assertFalse(NodeStatus::Skipped->canBecome(NodeStatus::Queued));
        $this->assertTrue(NodeStatus::Skipped->letsChildrenRun());
        $this->assertFalse(NodeStatus::Failed->letsChildrenRun());
    }

    public function test_a_template_that_is_one_formula_keeps_its_kind_and_any_other_becomes_text()
    {
        $scope = new RunScope(['name' => 'Ada', 'items' => [1, 2], 'total' => 10.5], ['ok' => true], ['fetch' => ['id' => 7]]);

        $this->assertSame([1, 2], Template::render('{{ trigger.items }}', $scope));
        $this->assertSame(21, Template::render('{{ trigger.total * 2 }}', $scope));
        $this->assertTrue(Template::render('{{ input.ok }}', $scope));
        // With anything around it, it is text
        $this->assertSame(' true ', Template::render(' {{ input.ok }} ', $scope));
        $this->assertSame('Ada has [1,2], #7', Template::render('{{ trigger.name }} has {{ trigger.items }}, #{{ node("fetch").id }}', $scope));
        $this->assertSame('No formulas', Template::render('No formulas', $scope));
    }

    public function test_every_part_the_test_data_offers_can_be_read_by_the_path_it_drags()
    {
        $scope = new RunScope(
            ['photos' => [['file_id' => 'a'], ['file_id' => 'b']], 'Cost (THB)' => 120, 'and' => 'x'],
            null,
            ['fetch' => ['rows' => [3, 4, 5]]],
        );

        $this->assertSame('a', Template::render('{{ item(trigger.photos, 1).file_id }}', $scope));
        $this->assertSame('b', Template::render('{{ item(trigger.photos, -1).file_id }}', $scope));
        $this->assertSame(4, Template::render('{{ item(node("fetch").rows, 2) }}', $scope));
        $this->assertNull(Template::render('{{ item(node("fetch").rows, 9) }}', $scope));
        $this->assertSame(120, Template::render('{{ trigger.[Cost (THB)] }}', $scope));
        // A name that is also a word is written in [ ]
        $this->assertSame('x', Template::render('{{ trigger.[and] }}', $scope));
    }

    public function test_a_step_reads_only_what_its_run_has_given_it()
    {
        $scope = new RunScope([], null, [], ['skipped' => true]);

        foreach ([
            '{{ trigger.missing }}' => 'There is no "missing" here.',
            '{{ node("skipped").x }}' => 'node("skipped") was skipped in this run, so it gave nothing.',
            '{{ node("later").x }}' => 'node("later") is not a step before this one.',
            '{{ secrets }}' => 'Nothing is called "secrets". A step can read trigger, input, and node("key").',
        ] as $template => $message) {
            try {
                Template::render($template, $scope);
                $this->fail("Worked out: {$template}");
            } catch (FormulaError $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }
}
