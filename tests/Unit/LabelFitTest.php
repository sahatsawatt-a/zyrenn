<?php

namespace Tests\Unit;

use App\Support\Board\LabelFit;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A rich label's words as the server reads them to measure. The canvas reads
 * them the same (resources/js/features/boards/drawing/labels.ts, runsOf)
 * and its tests hold the same cases.
 */
class LabelFitTest extends TestCase
{
    /** @return list<array{string, bool, bool}> */
    private function runs(string $line): array
    {
        return (fn () => self::runs($line, false))->call(new LabelFit);
    }

    /**
     * @return iterable<string, array{string, list<array{string, bool, bool}>}>
     */
    public static function sharedCases(): iterable
    {
        $shared = json_decode((string) file_get_contents(__DIR__.'/../fixtures/rich-text-runs.json'), true);

        foreach ($shared['cases'] as $case) {
            yield $case['line'] => [$case['line'], $case['runs']];
        }
    }

    /**
     * The same cases the canvas is held to (labels.test.ts).
     *
     * @param  list<array{string, bool, bool}>  $runs
     */
    #[DataProvider('sharedCases')]
    public function test_a_line_is_read_as_the_canvas_reads_it(string $line, array $runs)
    {
        $this->assertSame($runs, $this->runs($line));
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
