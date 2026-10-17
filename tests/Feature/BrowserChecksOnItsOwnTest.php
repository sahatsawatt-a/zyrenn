<?php

namespace Tests\Feature;

use App\Jobs\RunBrowserChecks;
use App\Models\Browser\BrowserRun;
use App\Models\Browser\BrowserSuite;
use App\Models\Drive\DriveFile;
use App\Models\Project;
use App\Models\Ticket\Ticket;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Browser\SuiteRun;
use App\Support\Workflow\Engine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Browser checks that look after themselves: run on a timetable, filing a
 * bug ticket when a check fails, typing hidden values without ever showing
 * them, and run from a workflow.
 */
class BrowserChecksOnItsOwnTest extends TestCase
{
    use RefreshDatabase;

    private const PICTURE = 'data:image/jpeg;base64,/9j/4AAQ';

    /** @param  array<string, mixed>  $settings */
    private function suiteOf(User|Project $owner, User $by, array $settings = []): BrowserSuite
    {
        $suite = (new BrowserSuite([
            'title' => 'Shop',
            'checks' => [['id' => 'login', 'title' => 'Logging in', 'steps' => [
                ['do' => 'open', 'url' => 'https://shop.example.com'],
                ['do' => 'type', 'target' => 'Password', 'text' => '{{password}}'],
                ['do' => 'click', 'target' => 'Log in'],
            ]]],
        ]))->ownedBy($owner, $by);
        $suite->settings = $settings ?: null;
        $suite->save();

        return $suite;
    }

    private function browserSays(bool $ok): void
    {
        Http::fake(['chrome:3000/check' => Http::response([
            'steps' => $ok
                ? [['ok' => true, 'took' => 300], ['ok' => true, 'took' => 40], ['ok' => true, 'took' => 50]]
                : [['ok' => true, 'took' => 300], ['ok' => true, 'took' => 40], ['ok' => false, 'took' => 10000, 'error' => 'Nothing showing "Log in" turned up in 10 seconds.', 'shot' => self::PICTURE]],
            'url' => 'https://shop.example.com/',
            'title' => 'Shop',
            'refused' => [],
            'video' => null,
        ])]);
    }

    public function test_a_timetable_says_when_it_next_runs_in_its_own_time_zone()
    {
        $user = User::factory()->create();
        $suite = $this->suiteOf($user, $user);
        // A Friday, 10:00 in Bangkok
        $friday = CarbonImmutable::parse('2026-10-16 03:00:00', 'UTC');

        $suite->settings = ['every' => 'day', 'at' => '09:00', 'zone' => 'Asia/Bangkok'];
        $this->assertSame('2026-10-17 02:00', $suite->nextRunAfter($friday)?->format('Y-m-d H:i'));

        $suite->settings = ['every' => 'weekdays', 'at' => '09:00', 'zone' => 'Asia/Bangkok'];
        $this->assertSame('2026-10-19 02:00', $suite->nextRunAfter($friday)?->format('Y-m-d H:i'));

        $suite->settings = ['every' => 'hour', 'at' => '00:30', 'zone' => 'Asia/Bangkok'];
        $this->assertSame('2026-10-16 03:30', $suite->nextRunAfter($friday)?->format('Y-m-d H:i'));

        $suite->settings = ['every' => 'off'];
        $this->assertNull($suite->nextRunAfter($friday));
    }

    public function test_settings_and_hidden_values_are_kept_and_a_value_is_never_sent_back()
    {
        $user = User::factory()->create();
        $suite = $this->suiteOf($user, $user);

        $this->actingAs($user)->patchJson(route('checks.suites.update', $suite), [
            'settings' => ['every' => 'day', 'at' => '09:00', 'zone' => 'Asia/Bangkok', 'tickets' => true],
            'values' => [['name' => 'password', 'value' => 'secret_sauce']],
        ])->assertOk()->assertJsonPath('next_run_at', fn ($at) => $at !== null);

        $suite->refresh();
        // One's own suite has no project to file tickets in
        $this->assertFalse($suite->settings['tickets']);
        $this->assertSame(['password' => 'secret_sauce'], $suite->values());
        $this->assertStringNotContainsString('secret_sauce', (string) DB::table('browser_suites')->value('secret_values'));

        // Left out, a value stays as it was
        $this->actingAs($user)->patchJson(route('checks.suites.update', $suite), ['values' => [['name' => 'password']]])->assertOk();
        $this->assertSame(['password' => 'secret_sauce'], $suite->fresh()->values());

        $shown = $this->actingAs($user)->getJson(route('checks.suites.show', $suite))
            ->assertJsonPath('values', [['name' => 'password', 'set' => true]])
            ->assertJsonPath('settings.every', 'day')
            ->getContent();
        $this->assertStringNotContainsString('secret_sauce', (string) $shown);
    }

    public function test_a_hidden_value_is_typed_but_what_the_step_says_never_shows_it()
    {
        $this->browserSays(true);
        $user = User::factory()->create();
        $suite = $this->suiteOf($user, $user);
        $suite->secret_values = [['name' => 'password', 'value' => 'secret_sauce']];
        $suite->save();

        $run = SuiteRun::carryOut(SuiteRun::start($suite, $user, SuiteRun::settings('watch', captions: true)));

        Http::assertSent(fn (Request $request) => $request['steps'][1] === [
            'do' => 'type', 'target' => 'Password', 'text' => 'secret_sauce', 'say' => 'Type "{{password}}" into "Password"',
        ]);
        $this->assertSame('Type "{{password}}" into "Password"', $run->results[0]['steps'][1]['say']);
        $this->assertStringNotContainsString('secret_sauce', json_encode($run->results));
    }

    public function test_a_suite_whose_time_has_come_runs_by_itself_once()
    {
        Queue::fake();
        $user = User::factory()->create();
        $suite = $this->suiteOf($user, $user, ['every' => 'hour', 'at' => '00:00', 'zone' => 'UTC']);
        $suite->forceFill(['next_run_at' => now()->subMinute()])->save();
        $later = $this->suiteOf($user, $user, ['every' => 'hour', 'at' => '00:00', 'zone' => 'UTC']);
        $later->forceFill(['next_run_at' => now()->addHour()])->save();

        $this->artisan('browser-checks:due')->assertSuccessful();
        $this->artisan('browser-checks:due')->assertSuccessful();

        $run = BrowserRun::sole();
        $this->assertSame($suite->id, $run->suite_id);
        $this->assertSame(SuiteRun::TIMETABLE, $run->started_by);
        $this->assertSame('quick', $run->settings['speed']);
        $this->assertTrue($suite->fresh()->next_run_at->isFuture());
        Queue::assertPushed(RunBrowserChecks::class, 1);
    }

    public function test_a_failing_check_files_a_bug_ticket_once_with_its_picture()
    {
        Storage::fake(DriveFile::DISK);
        $this->browserSays(false);
        $owner = User::factory()->create();
        $project = Project::start($owner, 'Customer');
        $suite = $this->suiteOf($project, $owner, ['every' => 'off', 'tickets' => true]);

        $first = SuiteRun::carryOut(SuiteRun::start($suite, $owner, SuiteRun::settings('quick', keep: true), SuiteRun::TIMETABLE));
        $second = SuiteRun::carryOut(SuiteRun::start($suite, $owner, SuiteRun::settings('quick', keep: true), SuiteRun::TIMETABLE));

        $ticket = Ticket::sole();
        $this->assertSame(Ticket::BUG, $ticket->type);
        $this->assertSame('Browser check fails: Logging in (Shop)', $ticket->title);
        $this->assertStringContainsString('Failed at step 3, Click "Log in"', (string) $ticket->body);
        $this->assertStringStartsWith('/drive/files/', $ticket->pictures[0]['src']);
        // The check is how to make it happen again
        $this->assertSame([$suite->id, 'login'], [$ticket->browser_suite_id, $ticket->browser_check]);
        $this->assertTrue($first->tickets[0]['new']);
        $this->assertFalse($second->tickets[0]['new']);

        $this->actingAs($owner)->getJson(route('checks.runs.show', $second))
            ->assertJsonPath('started_by', 'timetable')
            ->assertJsonPath('tickets.0.number', $ticket->number);
    }

    public function test_a_workflow_runs_a_suite_and_can_go_by_whether_it_passed()
    {
        config(['queue.default' => 'database']);
        $this->browserSays(false);
        $user = User::factory()->create();
        $suite = $this->suiteOf($user, $user);
        $suite->secret_values = [['name' => 'password', 'value' => 'secret_sauce']];
        $suite->save();

        $nodes = [
            ['id' => 'start', 'key' => 'start', 'type' => 'trigger.manual', 'position' => ['x' => 0, 'y' => 0], 'config' => ['payload' => '{"n": 1}']],
            ['id' => 'checks', 'key' => 'checks', 'type' => 'browser.run', 'position' => ['x' => 200, 'y' => 0], 'config' => ['suite' => 'Shop', 'film' => 'no']],
        ];
        $workflow = Workflow::factory()->steps($nodes, [['id' => 'e', 'source' => 'start', 'sourceHandle' => 'out', 'target' => 'checks']])->for($user)->create();
        $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $user), $user);
        $this->work();

        $step = $run->fresh()->steps()->where('node_key', 'checks')->sole();
        $this->assertSame('succeeded', $step->status->value);
        $this->assertFalse($step->output['passed']);
        $this->assertSame(1, $step->output['failed']);
        $this->assertStringStartsWith('Failed: 1 of 1 check did not -- Logging in: Failed at step 3', $step->output['summary']);
        $this->assertSame(SuiteRun::WORKFLOW, BrowserRun::sole()->started_by);
    }
}
