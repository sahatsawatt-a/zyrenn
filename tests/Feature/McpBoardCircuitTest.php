<?php

namespace Tests\Feature;

use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Boards\CreateBoard;
use App\Mcp\Tools\Boards\UpdateBoard;
use App\Models\Board\Board;
use App\Models\User;
use App\Support\Board\BoardItemProblem;
use App\Support\Board\CircuitLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * A circuit written as text and laid out on the server: the browser's own
 * reading and layout, run by Node (collab/circuit.mjs), so a circuit put on a
 * board through MCP comes out as it does in a note.
 */
class McpBoardCircuitTest extends TestCase
{
    use RefreshDatabase;

    private const LED = "V1: battery 9V\nR1: 330\nD1: led red\nV1 -- R1 -- D1 -- V1";

    /**
     * The items stored on a board, keyed by id.
     *
     * @return array<string, array<string, mixed>>
     */
    private function stored(Board $board): array
    {
        return array_column($board->refresh()->content['items'], null, 'id');
    }

    /** What Node would answer for one resistor between a rail and ground. */
    private function fakeLayout(): void
    {
        Process::fake(['*' => Process::result(json_encode(['ok' => true, 'items' => [
            ['id' => 'i1', 'kind' => 'symbol', 'symbol' => 'resistor', 'x' => 40, 'y' => -20, 'width' => 80, 'height' => 40, 'text' => "R1\n10 kΩ"],
            ['id' => 'i2', 'kind' => 'symbol', 'symbol' => 'ground', 'x' => 140, 'y' => 0, 'width' => 40, 'height' => 40, 'text' => ''],
            ['id' => 'i3', 'kind' => 'arrow', 'from' => ['item' => 'i1', 'pin' => '2'], 'to' => ['item' => 'i2', 'pin' => '1'], 'endHead' => 'none'],
        ]]))]);
    }

    public function test_a_circuit_written_as_text_is_laid_out_on_a_new_board()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, ['title' => 'LED', 'circuit' => self::LED])
            ->assertOk();

        $items = $this->stored($user->boards()->sole());
        $parts = array_filter($items, fn (array $item) => $item['kind'] === 'symbol');
        $wires = array_filter($items, fn (array $item) => $item['kind'] === 'arrow');

        // Each part under its own name, as the library draws it, on the grid
        $this->assertSame(['d1' => 'led', 'r1' => 'resistor', 'v1' => 'battery'], collect($parts)->map->symbol->sortKeys()->all());
        $this->assertSame("R1\n330 Ω", $items['r1']['text']);

        foreach ($parts as $part) {
            $this->assertSame(0.0, fmod($part['x'], 20));
            $this->assertSame(0.0, fmod($part['y'], 20));
        }

        // Three parts in a loop: three wires, each from a pin to a pin
        $this->assertCount(3, $wires);

        foreach ($wires as $wire) {
            $this->assertNotEmpty($wire['from']['pin']);
            $this->assertNotEmpty($wire['to']['pin']);
            $this->assertSame('none', $wire['endHead']);
        }
    }

    public function test_a_circuit_that_cannot_be_read_says_which_line_and_makes_no_board()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, ['title' => 'Broken', 'circuit' => "R1: 10k\nR1 -- Q7.b"])
            ->assertHasErrors(['The circuit cannot be read. Line 2: Q7 is not a part: write "Q7: …" on a line of its own first.']);

        $this->assertSame(0, $user->boards()->count());
    }

    public function test_a_circuit_is_added_under_what_a_board_has_with_ids_of_its_own()
    {
        $this->fakeLayout();
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateBoard::class, ['title' => 'Notes', 'items' => [
                ['id' => 'r1', 'kind' => 'rect', 'text' => 'Already here', 'x' => 100, 'y' => 100, 'width' => 200, 'height' => 140],
            ]])
            ->assertOk();

        $board = $user->boards()->sole();

        UserServer::actingAs($user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_circuit' => "R1: 10k\n5V -- R1 -- GND"])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                // "r1" is taken by the box: the resistor is the second of the name
                ->where('added', ['r1-2', 'w1', 'w2'])
                ->etc());

        $items = $this->stored($board);

        // Under the box, in line with its left edge, and still wired
        $this->assertEquals([100, 320], [$items['r1-2']['x'], $items['r1-2']['y']]);
        $this->assertEquals([200, 340], [$items['w1']['x'], $items['w1']['y']]);
        $this->assertSame(['r1-2', '2'], [$items['w2']['from']['item'], $items['w2']['from']['pin']]);
        $this->assertSame('w1', $items['w2']['to']['item']);
        $this->assertSame('Already here', $items['r1']['text']);

        // ...or where it is asked for
        UserServer::actingAs($user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_circuit' => "R1: 10k\n5V -- R1 -- GND", 'circuit_at' => ['x' => 1000, 'y' => 45]])
            ->assertOk();

        $items = $this->stored($board);

        $this->assertEquals([1000, 60], [$items['r1-3']['x'], $items['r1-3']['y']]);
    }

    public function test_a_board_is_left_alone_when_the_circuit_cannot_be_laid_out()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)->tool(CreateBoard::class, ['title' => 'Notes', 'items' => [['id' => 'a', 'kind' => 'sticky', 'text' => 'Here']]])->assertOk();

        $board = $user->boards()->sole();
        $before = $board->content;

        // Node not there, or fallen over: said plainly, and nothing changes
        Process::fake(['*' => Process::result('', 'Error: Cannot find module', 1)]);

        UserServer::actingAs($user)
            ->tool(UpdateBoard::class, ['ref_id' => $board->ref_id, 'add_items' => [['kind' => 'sticky']], 'add_circuit' => self::LED])
            ->assertHasErrors(['A circuit cannot be laid out here just now. Place its parts as items of kind "symbol" instead.']);

        $this->assertSame($before, $board->fresh()->content);
    }

    public function test_the_text_goes_to_node_and_nowhere_else()
    {
        $this->fakeLayout();

        CircuitLayout::specs("R1: 10k\n5V -- R1 -- GND; rm -rf /");

        // As what it reads, never as part of what is run
        Process::assertRan(fn ($process) => $process->command === ['node', '--import', './collab/ts-loader.mjs', 'collab/circuit.mjs']
            && $process->input === "R1: 10k\n5V -- R1 -- GND; rm -rf /"
            && $process->path === base_path());

        $this->expectException(BoardItemProblem::class);
        Process::fake(['*' => Process::result(json_encode(['ok' => false, 'error' => 'Line 1: nothing to draw.']))]);

        CircuitLayout::specs('');
    }

    public function test_the_labs_page_puts_a_circuit_on_a_board_of_ones_own()
    {
        $this->fakeLayout();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('labs'))->assertOk();

        $response = $this->actingAs($user)->post(route('labs.circuit-board'), ['circuit' => "R1: 10k\n5V -- R1 -- GND"]);

        $board = $user->boards()->sole();

        $response->assertRedirect(route('boards.show', $board));
        $this->assertSame(['r1', 'w1', 'w2'], array_keys($this->stored($board)));

        Process::fake(['*' => Process::result(json_encode(['ok' => false, 'error' => 'Line 2: no.']))]);

        $this->actingAs($user)
            ->post(route('labs.circuit-board'), ['circuit' => 'nonsense'])
            ->assertSessionHasErrors(['circuit' => 'The circuit cannot be read. Line 2: no.']);
        $this->assertSame(1, $user->boards()->count());
    }
}
