<?php

namespace Tests\Feature;

use App\Jobs\RunBrowserChecks;
use App\Models\Browser\BrowserSuite;
use App\Models\User;
use App\Support\Browser\BrowserCheck;
use App\Support\Browser\SuiteRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Steps that hold the pointer: pressed down, moved -- straight, through
 * points, along an SVG path -- and let go, with other steps between; or a
 * drag in one. Kept with only what each says, said in words, and sent to the
 * browser as they are.
 */
class BrowserHoldStepsTest extends TestCase
{
    use RefreshDatabase;

    private function suiteOf(User $user): BrowserSuite
    {
        $suite = (new BrowserSuite(['title' => 'Board', 'checks' => []]))->ownedBy($user, $user);
        $suite->save();

        return $suite;
    }

    public function test_a_press_a_move_and_a_drag_are_kept_with_what_they_say_and_said_in_words()
    {
        $user = User::factory()->create();
        $suite = $this->suiteOf($user);

        $this->actingAs($user)->patchJson(route('checks.suites.update', $suite), ['checks' => [['id' => 'draw', 'title' => 'Draw', 'steps' => [
            ['do' => 'open', 'url' => 'https://example.com'],
            ['do' => 'drag', 'target' => 'Card', 'onto' => 'Done', 'path' => '', 'keys' => ''],
            ['do' => 'down', 'target' => 'the board', 'selector' => 'canvas', 'at' => '0.2,0.5', 'keys' => 'Shift', 'onto' => 'left over'],
            ['do' => 'move', 'target' => 'the board', 'selector' => 'canvas', 'motion' => 'curve', 'points' => '0.5,0.3 500ms 0.8,0.5', 'duration' => 1.5],
            ['do' => 'press', 'key' => 'Escape'],
            ['do' => 'up', 'target' => 'left over'],
            ['do' => 'drag', 'target' => 'the board', 'motion' => 'path', 'path' => 'M 0 0 a 60 60 0 1 0 120 0 M 0 40 l 50 0', 'units' => 'px', 'clicks' => 2, 'pointer' => 'pen', 'button' => 'right'],
            ['do' => 'move', 'by' => '120, -40'],
        ]]]])->assertOk();

        $steps = $this->withoutStepIds($suite->fresh()->checks[0]['steps']);
        $this->assertSame([
            ['do' => 'open', 'url' => 'https://example.com'],
            // Only what was given: an empty path or keys is none
            ['do' => 'drag', 'target' => 'Card', 'onto' => 'Done'],
            ['do' => 'down', 'target' => 'the board', 'at' => '0.2,0.5', 'keys' => 'Shift', 'selector' => 'canvas'],
            ['do' => 'move', 'target' => 'the board', 'motion' => 'curve', 'points' => '0.5,0.3 500ms 0.8,0.5', 'duration' => '1.5', 'selector' => 'canvas'],
            ['do' => 'press', 'key' => 'Escape'],
            ['do' => 'up'],
            ['do' => 'drag', 'target' => 'the board', 'motion' => 'path', 'path' => 'M 0 0 a 60 60 0 1 0 120 0 M 0 40 l 50 0', 'units' => 'px', 'button' => 'right', 'clicks' => '2', 'pointer' => 'pen'],
            ['do' => 'move', 'target' => '', 'by' => '120, -40'],
        ], $steps);
        $this->assertSame([
            'Open example.com',
            'Drag "Card" onto "Done"',
            'Press down on "the board" holding Shift',
            'Move across "the board"',
            'Press Escape',
            'Let go',
            'Double-click and draw a path on "the board" with the right button, with a pen',
            'Move by 120, -40 px',
        ], array_map(BrowserCheck::say(...), $steps));
        $this->assertNull(BrowserCheck::unready(['title' => 'Draw', 'steps' => $steps]));
    }

    public function test_what_a_press_move_or_drag_is_missing_is_said_and_what_it_cannot_be_is_refused()
    {
        $missing = fn (array $step) => BrowserCheck::unready(['title' => 'Draw', 'steps' => [['do' => 'open', 'url' => 'https://a.example'], $step]]);
        $this->assertSame('Draw, step 2: say what to press down on', $missing(['do' => 'down']));
        $this->assertSame('Draw, step 2: say what to drag', $missing(['do' => 'drag']));
        $this->assertSame('Draw, step 2: say where to drag it to', $missing(['do' => 'drag', 'target' => 'Card']));
        $this->assertSame('Draw, step 2: say where to move to', $missing(['do' => 'move']));
        $this->assertSame('Draw, step 2: give the path to follow', $missing(['do' => 'move', 'motion' => 'path']));
        // A thing alone is enough to move to, and points alone to move through
        $this->assertNull($missing(['do' => 'move', 'target' => 'Menu']));
        $this->assertNull($missing(['do' => 'move', 'points' => '10,0 20,10']));
        $this->assertNull($missing(['do' => 'up']));

        $user = User::factory()->create();
        $suite = $this->suiteOf($user);
        $this->actingAs($user)->patchJson(route('checks.suites.update', $suite), ['checks' => [['id' => 'draw', 'title' => 'Draw', 'steps' => [
            ['do' => 'down', 'target' => 'Canvas', 'at' => 'the middle', 'keys' => 'Escape', 'clicks' => 3, 'pointer' => 'stylus', 'button' => 'back'],
            ['do' => 'move', 'motion' => 'zigzag', 'points' => '1,2 <b>', 'path' => 'M 0 0 <script>', 'units' => 'cm', 'by' => 'far', 'duration' => 30],
        ]]]])->assertJsonValidationErrors([
            'checks.0.steps.0.at', 'checks.0.steps.0.keys', 'checks.0.steps.0.clicks', 'checks.0.steps.0.pointer', 'checks.0.steps.0.button',
            'checks.0.steps.1.motion', 'checks.0.steps.1.points', 'checks.0.steps.1.path', 'checks.0.steps.1.units', 'checks.0.steps.1.by', 'checks.0.steps.1.duration',
        ]);
    }

    public function test_the_browser_is_sent_the_moves_as_they_are_written()
    {
        Http::fake(['chrome:3000/check' => Http::response([
            'steps' => [['ok' => true, 'took' => 300], ['ok' => true, 'took' => 40], ['ok' => true, 'took' => 5]],
            'url' => 'https://example.com/',
            'title' => 'Example',
            'refused' => [],
        ])]);
        $user = User::factory()->create();
        $suite = $this->suiteOf($user);
        $suite->take(['checks' => [['id' => 'draw', 'title' => 'Draw', 'steps' => [
            ['do' => 'open', 'url' => 'https://example.com'],
            ['do' => 'down', 'target' => '{{thing}}', 'at' => '0.1,0.9', 'keys' => 'Alt+Shift'],
            ['do' => 'drag', 'target' => 'Card', 'onto' => '{{column}}', 'motion' => 'curve', 'points' => '0.5,-1'],
        ]]], 'values' => [['name' => 'thing', 'value' => 'Canvas'], ['name' => 'column', 'value' => 'Done']]])->save();
        $run = $suite->runs()->create(['created_by' => $user->id, 'settings' => SuiteRun::settings('quick')]);

        (new RunBrowserChecks($run->id))->handle();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/check')
            && $request['steps'][1] === ['do' => 'down', 'target' => 'Canvas', 'at' => '0.1,0.9', 'keys' => 'Alt+Shift', 'say' => 'Press down on "{{thing}}" holding Alt+Shift']
            && $request['steps'][2] === ['do' => 'drag', 'target' => 'Card', 'onto' => 'Done', 'motion' => 'curve', 'points' => '0.5,-1', 'say' => 'Drag "Card" onto "{{column}}"']);
    }
}
