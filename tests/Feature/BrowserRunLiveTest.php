<?php

namespace Tests\Feature;

use App\Events\BrowserRunChanged;
use App\Jobs\RunBrowserChecks;
use App\Models\Browser\BrowserRun;
use App\Models\Browser\BrowserSuite;
use App\Models\User;
use App\Support\Browser\SuiteRun;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What an open browser-checks page is told: each run of the suite, as it
 * moves on, on the suite's own channel -- small, with no steps or pictures.
 */
class BrowserRunLiveTest extends TestCase
{
    use RefreshDatabase;

    private const PICTURE = 'data:image/jpeg;base64,/9j/4AAQ';

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([BroadcastEvent::class]);
    }

    private function suite(User $user): BrowserSuite
    {
        $steps = [['do' => 'open', 'url' => 'https://example.com'], ['do' => 'shot']];
        $suite = (new BrowserSuite(['title' => 'Shop', 'checks' => [
            ['id' => 'a', 'title' => 'Home', 'steps' => $steps],
            ['id' => 'b', 'title' => 'Cart', 'steps' => $steps],
        ]]))->ownedBy($user, $user);
        $suite->save();

        return $suite;
    }

    /**
     * What was told of runs, in order.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function told(): Collection
    {
        return Bus::dispatched(BroadcastEvent::class)
            ->filter(fn (BroadcastEvent $job) => $job->event instanceof BrowserRunChanged)
            ->map(fn (BroadcastEvent $job) => [
                'channel' => $job->event->broadcastOn()->name,
                'as' => $job->event->broadcastAs(),
                ...$job->event->broadcastWith(),
            ])
            ->values();
    }

    public function test_a_run_tells_its_suites_channel_how_it_goes_until_it_ends()
    {
        Http::fake(['chrome:3000/check' => Http::response([
            'steps' => [['ok' => true, 'took' => 300], ['ok' => true, 'took' => 5, 'shot' => self::PICTURE]],
            'url' => 'https://example.com/', 'title' => 'Example', 'refused' => [], 'video' => null,
        ])]);
        $user = User::factory()->create();
        $suite = $this->suite($user);
        $run = SuiteRun::start($suite, $user, SuiteRun::settings('quick'));

        (new RunBrowserChecks($run->id))->handle();

        $told = $this->told();

        // Waiting, going, each check as it ends, and how it ended -- in order
        $this->assertSame("presence-checks.{$suite->ref_id}", $told->first()['channel']);
        $this->assertSame('run.changed', $told->first()['as']);
        $this->assertSame(['waiting', 'running'], $told->take(2)->pluck('state')->all());
        $this->assertSame('passed', $told->last()['state']);
        // A line for each check as it ends: none, then one, then both
        $this->assertSame([0, 1, 2], $told->map(fn (array $message) => count($message['checks']))->unique()->values()->all());
        $this->assertSame([['id' => 'a', 'ok' => true, 'done' => 2], ['id' => 'b', 'ok' => true, 'done' => 2]], $told->last()['checks']);
        $this->assertSame("/checks/runs/{$run->ref_id}", $told->last()['url']);

        // Newer each time, so one that arrives late is simply older
        $this->assertSame($told->pluck('seq')->sort()->values()->all(), $told->pluck('seq')->all());

        // Small enough for any socket: never a step, nor a picture
        foreach ($told as $message) {
            $this->assertStringNotContainsString('base64', (string) json_encode($message));
            $this->assertLessThan(2000, strlen((string) json_encode($message)));
        }
    }

    public function test_a_stop_is_told_at_once()
    {
        $user = User::factory()->create();
        $run = SuiteRun::start($this->suite($user), $user, SuiteRun::settings('quick'));

        $this->actingAs($user)->postJson(route('checks.runs.stop', $run))->assertOk();

        $this->assertSame(BrowserRun::STOPPED, $this->told()->last()['state']);
        $this->assertSame($run->ref_id, $this->told()->last()['run']);
    }
}
