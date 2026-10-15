<?php

namespace Tests\Unit;

use App\Support\Board\LabelFit;
use Tests\TestCase;

/**
 * A rich label's words as the server reads them to measure. The canvas reads
 * them the same (resources/js/features/boards/composables/labels.ts, runsOf)
 * and its tests hold the same cases.
 */
class LabelFitTest extends TestCase
{
    /** @return list<array{string, bool, bool}> */
    private function runs(string $line): array
    {
        return (fn () => self::runs($line, false))->call(new LabelFit);
    }

    public function test_an_underscore_inside_a_word_is_only_an_underscore()
    {
        $this->assertSame([['compare_by handling and ROW_WEBHOOK_URL', false, false]], $this->runs('compare_by handling and ROW_WEBHOOK_URL'));
        $this->assertSame(['an ', 'aside', ' here, snake_case_name too'], array_column($this->runs('an _aside_ here, snake_case_name too'), 0));
    }

    public function test_code_and_escaped_marks_are_read_as_the_canvas_reads_them()
    {
        $this->assertSame([['set ', false, false], ['ROW_WEBHOOK_URL', false, true], [' and ', false, false], ['go', false, false]], $this->runs('set `ROW_WEBHOOK_URL` and *go*'));
        $this->assertSame([['_private_ and 2 * 3 and `x`', false, false]], $this->runs('\\_private\\_ and 2 \\* 3 and \\`x\\`'));
    }

    public function test_code_inside_bold_words_is_still_code()
    {
        $this->assertSame([['docker compose up', true, true], [' in ', false, false], ['code/', false, true]], $this->runs('**`docker compose up`** in `code/`'));
    }

    public function test_code_is_measured_in_mono_with_room_for_its_tint()
    {
        $item = ['kind' => 'text', 'rich' => true, 'width' => 120, 'height' => 20, 'fontSize' => 16, 'padding' => 0, 'fontFamily' => 'sans'];

        // Narrow letters fit on a line in Arial, not in mono
        $this->assertLessThan(
            LabelFit::needed([...$item, 'text' => '`iiiiiiiiiiiiiii`']),
            LabelFit::needed([...$item, 'text' => 'iiiiiiiiiiiiiii']),
        );
    }
}
