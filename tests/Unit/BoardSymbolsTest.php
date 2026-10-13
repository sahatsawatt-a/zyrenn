<?php

namespace Tests\Unit;

use App\Support\Board\Symbols;
use Tests\TestCase;

/**
 * The symbol library as the server reads it. The canvas reads the same file
 * (resources/js/features/boards/circuit/symbols.ts), and its own tests place
 * the same pins at the same points: the two have to agree.
 */
class BoardSymbolsTest extends TestCase
{
    public function test_every_symbol_has_pins_on_its_edges()
    {
        $this->assertContains('resistor', Symbols::ids());

        foreach (Symbols::all() as $symbol) {
            $this->assertNotEmpty($symbol['pins'], $symbol['id']);

            foreach ($symbol['pins'] as $pin) {
                if (($symbol['role'] ?? '') === 'junction') {
                    continue;
                }

                $this->assertTrue(
                    in_array($pin['x'], [0, $symbol['width']], true) || in_array($pin['y'], [0, $symbol['height']], true),
                    "{$symbol['id']}.{$pin['name']}",
                );
            }
        }
    }

    public function test_pins_turn_with_the_part()
    {
        $resistor = ['symbol' => 'resistor', 'x' => 100, 'y' => 200, 'width' => 80, 'height' => 40, 'rotation' => 0];

        $this->assertEquals(
            ['1' => ['x' => 100.0, 'y' => 220.0], '2' => ['x' => 180.0, 'y' => 220.0]],
            Symbols::pins($resistor),
        );

        // Turned about the corner it is drawn from, as the canvas turns it
        $this->assertEquals(
            ['1' => ['x' => 80.0, 'y' => 200.0], '2' => ['x' => 80.0, 'y' => 280.0]],
            Symbols::pins([...$resistor, 'rotation' => 90]),
        );

        $this->assertSame('2', Symbols::nearestPin($resistor, ['x' => 400.0, 'y' => 0.0]));
    }

    public function test_a_board_is_the_box_the_canvas_draws()
    {
        // circuit.test.ts holds the canvas to the same numbers
        $esp32 = Symbols::find('esp32');

        $this->assertSame([160, 320], [$esp32['width'], $esp32['height']]);
        $this->assertCount(30, $esp32['pins']);
        $this->assertSame('GPIO4', Symbols::pinName($esp32, 'd4'));
        $this->assertEquals(
            ['x' => 160.0, 'y' => 220.0],
            Symbols::pins(['symbol' => 'esp32', 'x' => 0, 'y' => 0])['GPIO4'],
        );
    }

    public function test_a_chip_is_made_of_the_pins_in_its_id()
    {
        $chip = Symbols::find('chip:VCC,GND|OUT');

        $this->assertSame([120, 60], [$chip['width'], $chip['height']]);
        $this->assertSame(
            [['VCC', 'left'], ['GND', 'left'], ['OUT', 'right']],
            array_map(fn (array $pin) => [$pin['name'], $pin['side']], $chip['pins']),
        );

        // No pins is no chip, and neither is anything that is not a list of names
        $this->assertNull(Symbols::find('chip:'));
        $this->assertNull(Symbols::find('chip:<b>'));
        $this->assertNull(Symbols::find('flux-capacitor'));
    }

    public function test_a_pin_answers_to_its_other_names()
    {
        $diode = Symbols::find('diode');

        $this->assertSame('a', Symbols::pinName($diode, 'Anode'));
        $this->assertSame('k', Symbols::pinName($diode, '2'));
        $this->assertNull(Symbols::pinName($diode, 'gate'));
        $this->assertStringContainsString('npn (b c e)', Symbols::catalogue());
        // Boards are listed by name only: their pins are what is printed on them
        $this->assertStringContainsString('esp32, esp8266', Symbols::catalogue());
    }
}
