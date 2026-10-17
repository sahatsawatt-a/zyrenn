<?php

namespace Tests\Feature;

use App\Models\Board\Board;
use App\Models\Note\Note;
use App\Models\Table\Table;
use App\Models\User;
use App\Support\Dashboard\Previews;
use App\Support\Markdown\TiptapMarkdown;
use App\Support\Table\TableStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardPreviewsTest extends TestCase
{
    use RefreshDatabase;

    private function note(User $user, string $title, string $markdown): Note
    {
        $note = Note::factory()->for($user)->make(['title' => $title]);
        $note->content = TiptapMarkdown::toDoc($markdown);
        $note->save();

        return $note;
    }

    /** @param  array<string, mixed>  $fields */
    private static function item(string $id, string $kind, float $x, float $y, float $width, float $height, array $fields = []): array
    {
        return ['id' => $id, 'kind' => $kind, 'x' => $x, 'y' => $y, 'width' => $width, 'height' => $height,
            'fill' => '#ffffff', 'stroke' => '#334155', ...$fields];
    }

    public function test_a_note_shows_its_first_heading_and_its_words_without_markdown(): void
    {
        $user = User::factory()->create();
        $note = $this->note($user, 'Plan', implode("\n\n", [
            '# Week one',
            'The **sites** and their [checks](https://example.com), see [[Budget]].',
            '```mermaid',
            'graph TD; A-->B',
            '```',
            '- [ ] Send the *deck*',
        ]));

        $previews = Previews::of(collect([['kind' => 'note', 'ref_id' => $note->ref_id]]));

        $this->assertSame([
            "note:{$note->ref_id}" => [
                'kind' => 'note',
                'heading' => 'Week one',
                'text' => 'The sites and their checks, see Budget. Send the deck',
            ],
        ], $previews);
    }

    public function test_a_note_heading_that_only_repeats_the_title_is_left_out_and_long_text_is_cut_at_a_word(): void
    {
        $long = str_repeat('word ', 60);

        $opening = Previews::opening("# Plan\n\n{$long}", 'Plan');

        $this->assertNull($opening['heading']);
        $this->assertLessThanOrEqual(Previews::NOTE_CHARS + 1, mb_strlen($opening['text']));
        $this->assertStringEndsWith('word…', $opening['text']);
    }

    public function test_a_board_is_drawn_from_its_first_frame_placed_zero_to_one(): void
    {
        $board = Board::factory()->create(['content' => ['items' => [
            self::item('f1', 'frame', 0, 0, 1600, 900),
            self::item('f2', 'frame', 2000, 0, 1600, 900),
            self::item('a', 'rect', 100, 100, 200, 100, ['fill' => '#fde68a']),
            self::item('b', 'sticky', 700, 100, 200, 100, ['stroke' => 'transparent']),
            self::item('gone', 'rect', 400, 400, 50, 50, ['hidden' => true]),
            self::item('elsewhere', 'rect', 2100, 100, 200, 100),
            ['id' => 'line', 'kind' => 'arrow', 'from' => ['item' => 'a'], 'to' => ['item' => 'b']],
            ['id' => 'away', 'kind' => 'arrow', 'from' => ['item' => 'a'], 'to' => ['item' => 'elsewhere']],
        ]]]);

        $drawing = Previews::of(collect([['kind' => 'board', 'ref_id' => $board->ref_id]]))["board:{$board->ref_id}"];

        // What is on frame 1, and only that: two boxes and the line between them
        $this->assertSame('board', $drawing['kind']);
        $this->assertCount(2, $drawing['boxes']);
        $this->assertSame(['rect', 'sticky'], array_column($drawing['boxes'], 'kind'));
        $this->assertSame('#fde68a', $drawing['boxes'][0]['fill']);
        $this->assertSame('transparent', $drawing['boxes'][1]['stroke']);
        $this->assertCount(1, $drawing['lines']);

        // Placed in the space they take up, with a little room round it
        foreach ($drawing['boxes'] as $box) {
            $this->assertGreaterThan(0, $box['x']);
            $this->assertLessThan(1, $box['x'] + $box['w']);
            $this->assertGreaterThan(0, $box['y']);
            $this->assertLessThan(1, $box['y'] + $box['h']);
        }

        // 800 across by 100 down, padded alike on every side
        $this->assertEqualsWithDelta(864 / 164, $drawing['aspect'], 0.01);
    }

    public function test_a_board_with_more_than_a_card_holds_keeps_its_biggest_and_a_bare_one_draws_nothing(): void
    {
        $items = [self::item('big', 'rect', 0, 0, 1000, 1000)];

        foreach (range(1, Previews::BOARD_ITEMS + 10) as $at) {
            $items[] = self::item("small{$at}", 'rect', $at * 20, 1100, 10, 10);
        }

        $drawing = Previews::drawing($items);

        $this->assertCount(Previews::BOARD_ITEMS, $drawing['boxes']);
        // The big one is among them
        $this->assertSame('rect', $drawing['boxes'][0]['kind']);
        $this->assertGreaterThan(0.5, $drawing['boxes'][0]['w']);
        $this->assertSame([], Previews::drawing([])['boxes']);
        $this->assertSame([], Previews::drawing([['kind' => 'not-an-item']])['boxes']);
    }

    public function test_a_table_shows_its_first_columns_and_how_many_rows_it_has(): void
    {
        $table = Table::factory()->create();

        foreach (['Version', 'Date', 'Notes', 'Approved by', 'Link'] as $label) {
            TableStorage::newColumn($table, ['label' => $label, 'type' => 'varchar']);
        }

        foreach (range(1, 3) as $row) {
            TableStorage::insertRow($table);
        }

        $empty = Table::factory()->create();

        $previews = Previews::of(collect([
            ['kind' => 'table', 'ref_id' => $table->ref_id],
            ['kind' => 'table', 'ref_id' => $empty->ref_id],
        ]));

        $this->assertSame(['kind' => 'table', 'columns' => ['ID', 'Version', 'Date', 'Notes'], 'rows' => 3], $previews["table:{$table->ref_id}"]);
        $this->assertSame(0, $previews["table:{$empty->ref_id}"]['rows']);
    }

    public function test_a_table_whose_rows_are_missing_still_has_a_card_with_none_counted(): void
    {
        $user = User::factory()->create();
        $kept = Table::factory()->for($user)->create();
        $broken = Table::factory()->for($user)->create();
        TableStorage::drop($broken);

        $previews = Previews::of(collect([
            ['kind' => 'table', 'ref_id' => $kept->ref_id],
            ['kind' => 'table', 'ref_id' => $broken->ref_id],
        ]));

        $this->assertSame(['ID'], $previews["table:{$broken->ref_id}"]['columns']);
        $this->assertSame(0, $previews["table:{$broken->ref_id}"]['rows']);
        $this->assertSame(0, $previews["table:{$kept->ref_id}"]['rows']);
    }

    public function test_every_preview_comes_from_a_query_or_two_for_each_kind_however_many_cards(): void
    {
        $user = User::factory()->create();
        $recent = collect();

        foreach (range(1, 4) as $at) {
            $recent->push(['kind' => 'note', 'ref_id' => $this->note($user, "N{$at}", "Line {$at}")->ref_id]);
            $recent->push(['kind' => 'board', 'ref_id' => Board::factory()->for($user)->create()->ref_id]);
            $recent->push(['kind' => 'table', 'ref_id' => Table::factory()->for($user)->create()->ref_id]);
        }

        DB::enableQueryLog();
        $previews = Previews::of($recent);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(12, $previews);
        // Notes, boards, tables, their columns, and every table's rows counted at once
        $this->assertSame(5, $queries);
    }
}
