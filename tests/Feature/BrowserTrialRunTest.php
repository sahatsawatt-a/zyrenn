<?php

namespace Tests\Feature;

use App\Events\BrowserRunChanged;
use App\Jobs\RunBrowserChecks;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\BrowserChecks\RunBrowserSuite;
use App\Models\Browser\BrowserRun;
use App\Models\Browser\BrowserSuite;
use App\Models\Drive\DriveFile;
use App\Models\Project;
use App\Models\Ticket\Ticket;
use App\Models\User;
use App\Support\Browser\SuiteRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A run that is only a try, while a check is being written: done and
 * followed as any other, then kept nowhere -- not in the suite's runs, its
 * health or its checks' last results, no tickets, nothing in the Drive --
 * and taken away a while after it ends.
 */
class BrowserTrialRunTest extends TestCase
{
    use RefreshDatabase;

    private const PICTURE = 'data:image/jpeg;base64,/9j/4AAQ';

    private function suiteOf(Project $project, User $by): BrowserSuite
    {
        $suite = (new BrowserSuite([
            'title' => 'Shop',
            'checks' => [['id' => 'login', 'title' => 'Logging in', 'steps' => [
                ['do' => 'open', 'url' => 'https://shop.example.com'],
                ['do' => 'click', 'target' => 'Log in'],
            ]]],
        ]))->ownedBy($project, $by);
        $suite->settings = ['every' => 'off', 'tickets' => true];
        $suite->save();

        return $suite;
    }

    /** The browser finds no "Log in", and says so with a picture. */
    private function browserFails(): void
    {
        Http::fake(['chrome:3000/check' => Http::response([
            'steps' => [['ok' => true, 'took' => 300], ['ok' => false, 'took' => 10000, 'error' => 'Nothing showing "Log in" turned up in 10 seconds.', 'shot' => self::PICTURE]],
            'url' => 'https://shop.example.com/', 'title' => 'Shop', 'refused' => [], 'video' => null,
        ])]);
    }

    public function test_a_try_is_followed_as_any_run_and_kept_nowhere()
    {
        Storage::fake(DriveFile::DISK);
        $this->browserFails();
        $owner = User::factory()->create();
        $project = Project::start($owner, 'Customer');
        $suite = $this->suiteOf($project, $owner);

        // Asked to film and keep, as a try it does neither
        $started = $this->actingAs($owner)->postJson(route('checks.runs.store', $suite), [
            'speed' => 'quick', 'checks' => ['login'], 'trial' => true, 'record' => true, 'keep' => true,
        ])->assertCreated()->assertJsonPath('trial', true);
        $run = BrowserRun::where('ref_id', $started->json('ref_id'))->sole();
        $this->assertFalse($run->settings['record']);
        $this->assertFalse($run->settings['keep']);

        (new RunBrowserChecks($run->id))->handle();

        // Followed by its own address, as any run, with its picture as it came
        $this->getJson(route('checks.runs.show', $run))
            ->assertOk()
            ->assertJsonPath('state', 'failed')
            ->assertJsonPath('trial', true)
            ->assertJsonPath('results.0.steps.1.shot', self::PICTURE)
            ->assertJsonPath('folder', null);

        // Kept nowhere: no tickets, nothing in the Drive, not in the suite's runs
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, DriveFile::count());
        $this->assertNull($run->fresh()->drive_folder_id);
        $this->getJson(route('checks.suites.show', $suite))
            ->assertJsonCount(0, 'runs')
            ->assertJsonPath('latest', []);
        $this->assertSame(0, $suite->runs()->count());

        // While a real run of it is listed, and files its ticket
        $real = SuiteRun::carryOut(SuiteRun::start($suite, $owner, SuiteRun::settings('quick', keep: true)));
        $this->getJson(route('checks.suites.show', $suite))
            ->assertJsonCount(1, 'runs')
            ->assertJsonPath('runs.0.ref_id', $real->ref_id);
        $this->assertSame(1, Ticket::count());
    }

    public function test_the_page_is_told_a_run_is_only_a_try()
    {
        Event::fake([BrowserRunChanged::class]);
        $owner = User::factory()->create();
        $suite = $this->suiteOf(Project::start($owner, 'Customer'), $owner);

        SuiteRun::start($suite, $owner, SuiteRun::settings('quick'), trial: true)->tell();

        Event::assertDispatched(BrowserRunChanged::class, fn (BrowserRunChanged $event) => $event->broadcastWith()['trial'] === true);
    }

    public function test_an_assistant_tries_a_check_but_never_films_a_try()
    {
        $this->browserFails();
        $owner = User::factory()->create();
        $project = Project::start($owner, 'Customer');
        $this->suiteOf($project, $owner);

        UserServer::actingAs($owner)->tool(RunBrowserSuite::class, ['project' => $project->ref_id, 'suite' => 'Shop', 'as' => 'demo', 'try' => true])
            ->assertHasErrors(['never filmed']);

        UserServer::actingAs($owner)->tool(RunBrowserSuite::class, ['project' => $project->ref_id, 'suite' => 'Shop', 'try' => true])
            ->assertOk()
            ->assertSee('Only a try');
        $this->assertTrue(BrowserRun::sole()->trial);
    }

    public function test_a_try_is_taken_away_an_hour_after_it_ends()
    {
        $owner = User::factory()->create();
        $suite = $this->suiteOf(Project::start($owner, 'Customer'), $owner);
        $done = function (BrowserRun $run) {
            $run->forceFill(['state' => BrowserRun::PASSED, 'ended_at' => now()])->save();

            return $run;
        };

        $try = $done(SuiteRun::start($suite, $owner, SuiteRun::settings('quick'), trial: true));
        $kept = $done(SuiteRun::start($suite, $owner, SuiteRun::settings('quick')));

        $this->travel(SuiteRun::TRIAL_KEPT_MINUTES - 1)->minutes();
        $this->assertSame(0, SuiteRun::forgetTries());

        $this->travel(2)->minutes();
        $this->artisan('checks:forget-tries')->expectsOutput('1 tries taken away')->assertSuccessful();
        $this->assertFalse(BrowserRun::whereKey($try->id)->exists());
        $this->assertTrue(BrowserRun::whereKey($kept->id)->exists());
    }
}
