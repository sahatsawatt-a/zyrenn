<?php

namespace Tests\Feature;

use App\Jobs\RunBrowserChecks;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\BrowserChecks\GetBrowserRun;
use App\Mcp\Tools\BrowserChecks\SaveBrowserSuite;
use App\Mcp\Tools\BrowserChecks\StopBrowserRun;
use App\Models\Browser\BrowserRun;
use App\Models\Browser\BrowserSuite;
use App\Models\Drive\DriveFile;
use App\Models\Project;
use App\Models\Ticket\Ticket;
use App\Models\User;
use App\Support\Browser\SuiteRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * A run of browser checks stopped part way: taken out of the queue, or ended
 * after the step in hand, keeping what it did -- and why a run is waiting.
 */
class BrowserRunStopTest extends TestCase
{
    use RefreshDatabase;

    // The start of a real film, enough to be known as one
    private const FILM = 'data:video/webm;base64,GkXfo59ChoEBQveBAULygQRC84EIQoKEd2VibUKHgQJChYECGFOAZwEAAAAAAfkC';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Sam']);
    }

    /** Two checks of two steps each, kept by `$owner`. */
    private function suite(User|Project|null $owner = null): BrowserSuite
    {
        $steps = [['do' => 'open', 'url' => 'https://example.com'], ['do' => 'click', 'target' => 'Next']];
        $suite = (new BrowserSuite([
            'title' => 'Shop',
            'checks' => [
                ['id' => 'a', 'title' => 'Signing in', 'steps' => $steps],
                ['id' => 'b', 'title' => 'Buying', 'steps' => $steps],
            ],
        ]))->ownedBy($owner ?? $this->user, $this->user);
        $suite->save();

        return $suite;
    }

    /** @param  array<string, mixed>  $settings */
    private function runOf(BrowserSuite $suite, array $settings = []): BrowserRun
    {
        return SuiteRun::start($suite, $this->user, [...SuiteRun::settings('quick'), ...$settings]);
    }

    /** @param  list<array<string, mixed>>  $steps */
    private static function answer(array $steps, bool $halted = false, ?string $video = null): array
    {
        return ['steps' => $steps, 'url' => 'https://example.com/', 'title' => 'Example', 'refused' => [], 'video' => $video, 'halted' => $halted];
    }

    public function test_a_waiting_run_stopped_never_starts()
    {
        Http::fake();
        $run = $this->runOf($this->suite());

        $this->actingAs($this->user)->postJson(route('checks.runs.stop', $run))
            ->assertOk()
            ->assertJsonPath('state', BrowserRun::STOPPED)
            ->assertJsonPath('stopped', 'Stopped by Sam before it started');

        // The queue gets to it, and leaves it be
        (new RunBrowserChecks($run->id))->handle();

        Http::assertNothingSent();
        $this->assertSame(BrowserRun::STOPPED, $run->refresh()->state);
        $this->assertNull($run->started_at);
        $this->assertSame($this->user->id, $run->stopped_by);
    }

    public function test_a_running_run_stops_after_the_step_in_hand_and_files_no_tickets()
    {
        $project = Project::start($this->user, 'Customer');
        $suite = $this->suite($project);
        $suite->forceFill(['settings' => ['every' => 'off', 'tickets' => true]])->save();
        $run = $this->runOf($suite);

        Http::fake([
            'chrome:3000/check/stop' => Http::response(['ok' => true, 'running' => true]),
            'chrome:3000/check' => function () use ($run) {
                // Asked to stop while the first check is on its first step
                SuiteRun::stop($run, $this->user);

                return Http::response(self::answer([['ok' => true, 'took' => 300]], halted: true));
            },
        ]);

        (new RunBrowserChecks($run->id))->handle();

        $run->refresh();
        $this->assertSame(BrowserRun::STOPPED, $run->state);
        $this->assertCount(1, $run->results);
        $this->assertTrue($run->results[0]['stopped']);
        $this->assertSame('Stopped after step 1 of 2', SuiteRun::verdict($run->results[0]));
        $this->assertSame('Stopped by Sam at "Signing in", step 1 of 2 -- 0 checks done.', SuiteRun::summary($run));
        $this->assertSame(0, Ticket::count());
        $this->assertNull($run->tickets);

        // The browser was told, by the run's own name for it, and the second check never asked for
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/check/stop') && $request['token'] === $run->ref_id);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/check') && $request['token'] === $run->ref_id);
        Http::assertSentCount(2);
    }

    public function test_a_run_stopped_between_checks_starts_no_more_and_keeps_the_verdicts_it_has()
    {
        $run = $this->runOf($this->suite());

        Http::fake([
            // Unreachable: the run still stops before its next check
            'chrome:3000/check/stop' => Http::response(['error' => 'down'], 503),
            'chrome:3000/check' => function () use ($run) {
                SuiteRun::stop($run, $this->user);

                return Http::response(self::answer([['ok' => true, 'took' => 300], ['ok' => true, 'took' => 40]]));
            },
        ]);

        (new RunBrowserChecks($run->id))->handle();

        $run->refresh();
        $this->assertSame(BrowserRun::STOPPED, $run->state);
        $this->assertSame([true], array_column($run->results, 'ok'));
        $this->assertSame('Stopped by Sam after "Signing in" -- 1 check done.', SuiteRun::summary($run));
    }

    public function test_a_film_cut_short_is_kept_and_marked_partial()
    {
        Storage::fake(DriveFile::DISK);
        $run = $this->runOf($this->suite(), SuiteRun::settings('steady', captions: true, record: true, oneFilm: true));
        Http::fake(['chrome:3000/check' => Http::response([
            ...self::answer([['ok' => true, 'took' => 300], ['ok' => true, 'took' => 40], ['ok' => true, 'took' => 50]], halted: true, video: self::FILM),
            // The second check was on its first step when it was stopped
            'chapters' => [[['ok' => true, 'took' => 300], ['ok' => true, 'took' => 40]], [['ok' => true, 'took' => 50]]],
        ])]);

        (new RunBrowserChecks($run->id))->handle();

        $run->refresh();
        $this->assertSame(BrowserRun::STOPPED, $run->state);
        $this->assertSame([true, false], array_column($run->results, 'ok'));
        $this->assertArrayNotHasKey('stopped', $run->results[0]);
        $this->assertTrue($run->results[1]['stopped']);
        $this->assertSame('Shop (partial).webm', $run->results[0]['video']['name']);
        $this->assertTrue($run->results[0]['video']['partial']);
        $this->assertSame(['Shop (partial).webm'], $this->user->driveFiles()->where('name', 'like', '%.webm')->pluck('name')->all());
    }

    public function test_only_who_may_run_a_suite_may_stop_its_run_and_not_once_it_has_ended()
    {
        $run = $this->runOf($this->suite());

        $this->actingAs(User::factory()->create())->postJson(route('checks.runs.stop', $run))->assertForbidden();

        $run->forceFill(['state' => BrowserRun::PASSED, 'ended_at' => now()])->save();
        $this->actingAs($this->user)->postJson(route('checks.runs.stop', $run))->assertJsonValidationErrors('run');
    }

    public function test_an_assistant_stops_a_run_it_started_by_mistake()
    {
        Http::fake();
        $run = $this->runOf($this->suite());
        $server = UserServer::actingAs($this->user);

        $server->tool(StopBrowserRun::class, ['ref_id' => $run->ref_id])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('state', BrowserRun::STOPPED)
                ->where('summary', 'Stopped by Sam before it started.')
                ->etc());

        $server->tool(StopBrowserRun::class, ['ref_id' => $run->ref_id])->assertHasErrors(['already ended']);
    }

    public function test_a_waiting_run_says_what_it_is_waiting_for()
    {
        $suite = $this->suite();
        $going = $this->runOf($suite);
        $going->forceFill(['state' => BrowserRun::RUNNING, 'started_at' => now()])->save();
        $waiting = $this->runOf($suite);

        $this->actingAs($this->user)->getJson(route('checks.runs.show', $waiting))
            ->assertJsonPath('waiting_for', 'Waiting for a run going now: runs of browser checks go one at a time.');

        UserServer::actingAs($this->user)->tool(GetBrowserRun::class, ['ref_id' => $waiting->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('summary', 'Not started yet. Waiting for a run going now: runs of browser checks go one at a time.')
                ->etc());

        // Nothing ahead of it for a while: the worker is not there to take it
        $going->forceFill(['state' => BrowserRun::PASSED, 'ended_at' => now()])->save();
        $this->travel(2)->minutes();

        $this->assertSame('Nothing is ahead of it, yet it has not started: the browser-check worker may not be running.', SuiteRun::waitingFor($waiting->refresh()));
    }

    public function test_a_hidden_value_sent_empty_keeps_the_one_it_had()
    {
        $suite = $this->suite();
        $suite->take(['values' => [['name' => 'email', 'value' => 'sam@example.com'], ['name' => 'password', 'value' => 'secret']]])->save();

        UserServer::actingAs($this->user)->tool(SaveBrowserSuite::class, [
            'suite' => $suite->ref_id,
            'hidden_values' => [['name' => 'email', 'value' => ''], ['name' => 'password']],
        ])->assertOk();

        $this->assertSame(['email' => 'sam@example.com', 'password' => 'secret'], $suite->refresh()->values());
    }
}
