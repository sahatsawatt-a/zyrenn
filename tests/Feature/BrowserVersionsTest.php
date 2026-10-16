<?php

namespace Tests\Feature;

use App\Jobs\RunBrowserChecks;
use App\Models\Browser\BrowserRun;
use App\Models\Browser\BrowserSuite;
use App\Models\User;
use App\Support\Browser\StepTimings;
use App\Support\Browser\SuiteRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A suite's checks kept as versions -- each step with an id of its own that
 * lasts through edits, each run marked with the version it ran, an earlier
 * version put back -- and how long each step took and waited, by its id.
 */
class BrowserVersionsTest extends TestCase
{
    use RefreshDatabase;

    private function suite(User $user): BrowserSuite
    {
        $suite = (new BrowserSuite(['title' => 'Shop', 'checks' => []]))->ownedBy($user, $user);
        $suite->take(['checks' => [['id' => 'home', 'title' => 'Home', 'steps' => [
            ['do' => 'open', 'url' => 'https://example.com'],
            ['do' => 'see', 'target' => 'Welcome'],
        ]]]])->save();

        return $suite;
    }

    public function test_each_step_keeps_its_id_through_edits_and_a_rewrite()
    {
        $user = User::factory()->create();
        $suite = $this->suite($user);
        [$open, $see] = array_column($suite->checks[0]['steps'], 'id');

        // A step put first, sent with the ids the page has
        $this->actingAs($user)->patchJson(route('checks.suites.update', $suite), ['checks' => [['id' => 'home', 'title' => 'Home', 'steps' => [
            ['do' => 'click', 'target' => 'Accept cookies'],
            ['id' => $open, 'do' => 'open', 'url' => 'https://example.com'],
            ['id' => $see, 'do' => 'see', 'target' => 'Welcome back'],
        ]]]])->assertOk();
        $ids = array_column($suite->fresh()->checks[0]['steps'], 'id');
        $this->assertSame([$open, $see], array_slice($ids, 1));
        $this->assertNotContains($ids[0], [$open, $see]);

        // Written again whole without ids (as over MCP): the same steps keep theirs
        $again = $suite->fresh();
        $again->take(['checks' => [['id' => 'home', 'title' => 'Home', 'steps' => [
            ['do' => 'click', 'target' => 'Accept cookies'],
            ['do' => 'open', 'url' => 'https://example.com'],
            ['do' => 'see', 'target' => 'Welcome back'],
        ]]]])->save();
        $this->assertSame($ids, array_column($again->fresh()->checks[0]['steps'], 'id'));
    }

    public function test_changes_are_kept_as_versions_and_an_earlier_one_comes_back()
    {
        Http::fake(['chrome:3000/check' => Http::response(['steps' => [['ok' => true, 'took' => 100], ['ok' => true, 'took' => 80]], 'url' => 'https://example.com/', 'title' => 'Shop', 'refused' => [], 'video' => null])]);
        $user = User::factory()->create();
        $this->actingAs($user);
        $suite = $this->suite($user);
        $this->assertSame(1, $suite->fresh()->version);

        // Edits in a row, before any run used them: one version
        $suite->fresh()->take(['checks' => [['id' => 'home', 'title' => 'Home page', 'steps' => $suite->fresh()->checks[0]['steps']]]])->save();
        $this->assertSame(1, $suite->fresh()->version);
        $this->assertSame('Home page', $suite->versions()->sole()->checks[0]['title']);

        // A run marks the version it tested, and the next change is a version of its own
        $run = SuiteRun::start($suite->fresh(), $user, SuiteRun::settings('quick'));
        (new RunBrowserChecks($run->id))->handle();
        $this->assertSame(1, $run->fresh()->suite_version);
        $suite->fresh()->take(['checks' => [['id' => 'home', 'title' => 'Front', 'steps' => $suite->fresh()->checks[0]['steps']]]])->save();
        $this->assertSame(2, $suite->fresh()->version);

        $this->getJson(route('checks.suites.versions', $suite))->assertOk()
            ->assertJsonPath('current', 2)
            ->assertJsonPath('versions.0.version', 2)
            ->assertJsonPath('versions.1.titles', ['Home page'])
            ->assertJsonPath('versions.1.runs', 1);

        // Put back: as a new version, nothing lost
        $this->postJson(route('checks.suites.versions.restore', [$suite, 1]))->assertOk()
            ->assertJsonPath('version', 3)
            ->assertJsonPath('checks.0.title', 'Home page');
        $this->assertSame([3, 2, 1], $suite->versions()->orderByDesc('version')->pluck('version')->all());

        // Only those who may change it put one back
        $this->actingAs(User::factory()->create())->postJson(route('checks.suites.versions.restore', [$suite, 2]))->assertForbidden();
    }

    public function test_each_step_of_a_run_keeps_how_long_it_took_and_waited()
    {
        Http::fake(['chrome:3000/check' => Http::response(['steps' => [
            ['ok' => true, 'took' => 1200, 'waited' => 900],
            ['ok' => false, 'took' => 10100, 'waited' => 10000, 'timedOut' => true, 'error' => 'Nothing showing "Welcome" turned up in 10 seconds.'],
        ], 'url' => 'https://example.com/', 'title' => 'Shop', 'refused' => [], 'video' => null])]);
        $user = User::factory()->create();
        $suite = $this->suite($user);
        [$open, $see] = array_column($suite->checks[0]['steps'], 'id');

        $run = SuiteRun::start($suite, $user, SuiteRun::settings('quick'));
        (new RunBrowserChecks($run->id))->handle();

        $rows = DB::table('browser_step_timings')->orderBy('id')->get();
        $this->assertSame([$open, $see], $rows->pluck('step_id')->all());
        $this->assertSame([1200, 10100], $rows->pluck('took_ms')->map(fn ($ms) => (int) $ms)->all());
        $this->assertSame([900, 10000], $rows->pluck('waited_ms')->map(fn ($ms) => (int) $ms)->all());
        $this->assertSame([false, true], $rows->pluck('timed_out')->map(fn ($out) => (bool) $out)->all());
        $this->assertSame(1, (int) $rows->first()->suite_version);

        // A try keeps none
        $trial = SuiteRun::start($suite, $user, SuiteRun::settings('quick'));
        $trial->forceFill(['trial' => true])->save();
        (new RunBrowserChecks($trial->id))->handle();
        $this->assertSame(2, DB::table('browser_step_timings')->count());
    }

    public function test_only_the_last_runs_of_each_step_are_kept()
    {
        Http::fake(['chrome:3000/check' => Http::response(['steps' => [['ok' => true, 'took' => 100], ['ok' => true, 'took' => 80]], 'url' => 'https://example.com/', 'title' => 'Shop', 'refused' => [], 'video' => null])]);
        $user = User::factory()->create();
        $suite = $this->suite($user);
        $run = SuiteRun::start($suite, $user, SuiteRun::settings('quick'));
        (new RunBrowserChecks($run->id))->handle();
        $run = BrowserRun::sole();

        foreach (range(1, StepTimings::KEPT + 3) as $ignored) {
            StepTimings::keep($run);
        }

        $this->assertSame(StepTimings::KEPT, DB::table('browser_step_timings')->where('step_id', $suite->checks[0]['steps'][0]['id'])->count());
    }
}
