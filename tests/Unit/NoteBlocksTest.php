<?php

namespace Tests\Unit;

use App\Support\Markdown\TiptapMarkdown;
use App\Support\Note\NoteBlockProblem;
use App\Support\Note\NoteBlocks;
use Tests\TestCase;

class NoteBlocksTest extends TestCase
{
    /**
     * A note as Markdown, with an id on each block: its own ids, in order.
     *
     * @return array<string, mixed>
     */
    private function note(string $markdown): array
    {
        return NoteBlocks::withIds(TiptapMarkdown::toDoc($markdown));
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return list<string>
     */
    private function ids(array $doc): array
    {
        return array_column(NoteBlocks::outline($doc), 'id');
    }

    public function test_every_block_gets_an_id_of_its_own_and_keeps_it()
    {
        $doc = $this->note("# Plan\n\nFirst.\n\n- one\n- two");

        $ids = $this->ids($doc);
        $this->assertCount(3, $ids);
        $this->assertCount(3, array_unique($ids));
        $this->assertMatchesRegularExpression('/^[a-z0-9]{8}$/', $ids[0]);

        // The list's items carry ids too
        $this->assertNotNull($doc['content'][2]['content'][0]['attrs']['id'] ?? null);

        $this->assertSame($doc, NoteBlocks::withIds($doc));
    }

    public function test_a_repeated_id_is_replaced_on_the_later_block()
    {
        $doc = $this->note("One.\n\nTwo.");
        $doc['content'][1]['attrs']['id'] = $doc['content'][0]['attrs']['id'];

        [$first, $second] = $this->ids(NoteBlocks::withIds($doc));

        $this->assertSame($doc['content'][0]['attrs']['id'], $first);
        $this->assertNotSame($first, $second);
    }

    public function test_the_outline_says_what_each_block_is_in_a_few_words()
    {
        $doc = $this->note("## Budget\n\n".str_repeat('word ', 40)."\n\n- a\n- b\n\n```mermaid\ngraph TD\n```");

        $outline = NoteBlocks::outline($doc);

        $this->assertSame('heading 2', $outline[0]['type']);
        $this->assertSame('Budget', $outline[0]['text']);
        $this->assertLessThanOrEqual(83, mb_strlen($outline[1]['text']));
        $this->assertSame(2, $outline[2]['items']);
        $this->assertSame('mermaid', $outline[3]['type']);
    }

    public function test_a_section_is_its_heading_and_what_follows_up_to_the_next_as_big()
    {
        $doc = $this->note("# Plan\n\n## Budget\n\nMoney.\n\n### Detail\n\nMore.\n\n## Team\n\nPeople.");

        $section = NoteBlocks::section($doc, 'budget');

        $this->assertSame(['Budget', 'Money.', 'Detail', 'More.'], array_map(
            fn (array $block) => trim(NoteBlocks::markdown($block), "# \n"),
            $section,
        ));
    }

    public function test_a_list_is_shown_with_each_item_under_its_own_id()
    {
        $doc = $this->note("- [ ] buy milk\n- [x] call Ada");

        [$list] = NoteBlocks::present(NoteBlocks::find($doc, [$this->ids($doc)[0]]));

        $this->assertSame('taskList', $list['type']);
        $this->assertSame('- [ ] buy milk', $list['items'][0]['markdown']);
        $this->assertSame('- [x] call Ada', $list['items'][1]['markdown']);
    }

    public function test_replacing_a_block_keeps_its_id_and_the_rest_untouched()
    {
        $doc = $this->note("# Plan\n\nOld words.\n\nAfter.");
        [$heading, $old, $after] = $this->ids($doc);

        $done = NoteBlocks::apply($doc, [['op' => 'replace', 'block' => $old, 'markdown' => 'New **words**.']]);

        $this->assertSame([$heading, $old, $after], $this->ids($done['doc']));
        $this->assertSame('New **words**.', NoteBlocks::markdown($done['doc']['content'][1]));
        $this->assertSame([$old], $done['changed']);
        $this->assertSame('replace', $done['edits'][0]['do']);
    }

    public function test_inserting_appending_deleting_and_moving_by_id()
    {
        $doc = $this->note("A.\n\nB.\n\nC.");
        [$a, $b, $c] = $this->ids($doc);

        $done = NoteBlocks::apply($doc, [
            ['op' => 'insert_after', 'block' => $a, 'markdown' => 'A2.'],
            ['op' => 'prepend', 'markdown' => 'Start.'],
            ['op' => 'append', 'markdown' => 'End.'],
            ['op' => 'delete', 'block' => $b],
            ['op' => 'move', 'block' => $c, 'before' => $a],
        ]);

        $this->assertSame(
            ['Start.', 'C.', 'A.', 'A2.', 'End.'],
            array_map(NoteBlocks::markdown(...), $done['doc']['content']),
        );
        // The three new ones, and the one moved
        $this->assertCount(4, $done['changed']);
        $this->assertContains($c, $done['changed']);
    }

    public function test_markdown_given_for_a_list_item_becomes_list_items()
    {
        $doc = $this->note("- one\n- two");
        $two = $doc['content'][0]['content'][1]['attrs']['id'];

        $done = NoteBlocks::apply($doc, [['op' => 'insert_after', 'block' => $two, 'markdown' => "- three\n- four"]]);

        $this->assertSame("- one\n- two\n- three\n- four", NoteBlocks::markdown($done['doc']['content'][0]));
    }

    public function test_a_change_that_cant_be_made_makes_none()
    {
        $doc = $this->note('A.');

        $this->expectException(NoteBlockProblem::class);
        $this->expectExceptionMessage('Change 2 (delete): there is no block "nothere"');

        NoteBlocks::apply($doc, [
            ['op' => 'append', 'markdown' => 'B.'],
            ['op' => 'delete', 'block' => 'nothere'],
        ]);
    }

    public function test_an_item_moves_only_among_items()
    {
        $doc = $this->note("A.\n\n- one");
        $item = $doc['content'][1]['content'][0]['attrs']['id'];

        $this->expectExceptionMessage('a list item moves among list items');

        NoteBlocks::apply($doc, [['op' => 'move', 'block' => $item, 'after' => $this->ids($doc)[0]]]);
    }
}
