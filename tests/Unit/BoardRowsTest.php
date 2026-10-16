<?php

namespace Tests\Unit;

use App\Support\Board\BoardItems;
use App\Support\Board\BoardRows;
use App\Support\Board\Shapes;
use App\Support\Board\Templates;
use Tests\TestCase;

/**
 * Rows as the server reads and lays them out. The canvas does the same sums
 * (resources/js/features/boards/model/rows.ts) and its tests hold the
 * same numbers: the two have to agree, or a connector an MCP client draws to
 * a column lands on the one above it.
 */
class BoardRowsTest extends TestCase
{
    public function test_rows_are_read_with_ids_of_their_own()
    {
        $rows = BoardRows::fromSpec([
            ['name' => 'Order ID', 'type' => 'int', 'key' => 'PK'],
            ['name' => 'order id', 'type' => 'int'],
            ['name' => '', 'type' => str_repeat('x', 200), 'key' => 'nonsense', 'nullable' => true],
            'not a row',
        ], 'table');

        $this->assertSame(['order_id', 'order_id_2', 'row'], array_column($rows, 'id'));
        $this->assertSame(['pk', '', ''], array_column($rows, 'mark'));
        $this->assertSame(80, mb_strlen($rows[2]['type']));
        $this->assertTrue($rows[2]['nullable']);
    }

    public function test_a_member_with_brackets_is_a_method_unless_told_otherwise()
    {
        $rows = BoardRows::fromSpec([
            ['name' => 'area()'],
            ['name' => 'count()', 'method' => false],
            ['name' => 'size', 'visibility' => '#'],
        ], 'class');

        $this->assertSame([true, false, false], array_column($rows, 'method'));
        $this->assertSame(['+', '+', '#'], array_column($rows, 'mark'));
    }

    public function test_a_row_is_found_by_its_id_or_its_name()
    {
        $item = ['rows' => BoardRows::fromSpec([['id' => 'a', 'name' => 'Email'], ['name' => 'a']], 'table')];

        $this->assertSame('Email', BoardRows::named($item, 'a')['name']);
        $this->assertSame('a', BoardRows::named($item, ' email ')['id']);
        $this->assertNull(BoardRows::named($item, 'phone'));
    }

    public function test_the_library_says_which_shapes_hold_rows()
    {
        $this->assertSame('table', BoardRows::kindOf('table'));
        $this->assertSame('class', BoardRows::kindOf('class'));
        $this->assertNull(BoardRows::kindOf('lane'));
        $this->assertNull(BoardRows::kindOf('rect'));
        $this->assertTrue(Shapes::find('lane')['header']);
        $this->assertTrue(Shapes::find('lifeline')['slide']);
    }

    public function test_every_template_names_shapes_and_rows_that_are_there()
    {
        foreach (Templates::all() as $template) {
            $rows = [];

            foreach ($template['items'] as $item) {
                $this->assertContains($item['kind'], BoardItems::kinds(), $template['id']);

                if (isset($item['rows'])) {
                    $rows[$item['id']] = array_column($item['rows'], 'name');
                }
            }

            foreach ($template['items'] as $item) {
                foreach (['from', 'to'] as $end) {
                    if (isset($item[$end]['pin'])) {
                        $this->assertContains($item[$end]['pin'], $rows[$item[$end]['item']], $template['id']);
                    }
                }
            }
        }
    }
}
