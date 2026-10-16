<?php

namespace Tests\Unit;

use App\Support\Workflow\NodeTypes;
use App\Support\Workflow\Steps\Step;
use Tests\TestCase;

/**
 * Each kind of step says what runs it, beside the rest of what it is: one
 * place, so a kind the editor offers can never be one the engine can't run.
 */
class NodeTypesTest extends TestCase
{
    public function test_every_kind_of_step_names_a_step_that_runs_it()
    {
        foreach (NodeTypes::all() as $type => $node) {
            $this->assertTrue(is_a($node['runs'], Step::class, true), "{$type} runs {$node['runs']}, which is not a step");

            foreach ($node['kept'] ?? [] as $version => $old) {
                $this->assertTrue(is_a($old, Step::class, true), "{$type}@{$version} keeps {$old}, which is not a step");
            }
        }
    }

    public function test_the_editor_is_shown_the_kinds_without_what_runs_them()
    {
        $shown = NodeTypes::shown();

        $this->assertCount(count(NodeTypes::all()), $shown);
        $this->assertSame([], array_filter($shown, fn (array $node) => isset($node['runs']) || isset($node['kept'])));
    }
}
