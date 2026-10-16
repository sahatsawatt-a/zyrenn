<?php

namespace Tests\Feature;

use App\Jobs\RunBrowserChecks;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\BrowserChecks\SaveBrowserSuite;
use App\Models\Browser\BrowserSuite;
use App\Models\User;
use App\Support\Browser\Limits;
use App\Support\Browser\SuiteRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * How long a suite's checks wait for a slow site: set by whoever may change
 * the suite, up to the most an admin allows everyone -- and the browser told
 * it, with the time to answer in grown to match.
 */
class BrowserLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function suite(User $user): BrowserSuite
    {
        $suite = (new BrowserSuite(['title' => 'Slow shop', 'checks' => [
            ['id' => 'a', 'title' => 'Home', 'steps' => [['do' => 'open', 'url' => 'https://example.com']]],
        ]]))->ownedBy($user, $user);
        $suite->save();

        return $suite;
    }

    /** What the browser was told to wait, in ms, for one run of `$suite`. */
    private function sentFor(BrowserSuite $suite): array
    {
        Http::fake(['chrome:3000/check' => Http::response(['steps' => [['ok' => true, 'took' => 100]], 'url' => 'https://example.com/', 'title' => 'Shop', 'refused' => [], 'video' => null])]);
        $run = SuiteRun::start($suite, $suite->creator, SuiteRun::settings('quick'));
        (new RunBrowserChecks($run->id))->handle();

        $sent = null;
        Http::assertSent(function (Request $request) use (&$sent) {
            $sent = $request['limits'];

            return true;
        });

        return $sent;
    }

    public function test_a_suite_waits_the_default_until_it_sets_its_own()
    {
        $user = User::factory()->create();
        $suite = $this->suite($user);

        $this->assertSame(['step' => 10_000, 'check' => 90_000], $this->sentFor($suite));

        $this->actingAs($user)->patchJson(route('checks.suites.update', $suite), ['limits' => ['step' => 30, 'check' => 300]])->assertOk();
        $this->assertSame(['step' => 30, 'check' => 300], $suite->fresh()->settings['limits']);
        $this->assertSame(['step' => 30_000, 'check' => 300_000], $this->sentFor($suite->fresh()));

        // Kept when its timetable changes, and back to the default when cleared
        $this->patchJson(route('checks.suites.update', $suite), ['settings' => ['every' => 'day', 'at' => '08:00']])->assertOk();
        $this->assertSame(['step' => 30, 'check' => 300], $suite->fresh()->settings['limits']);
        $this->patchJson(route('checks.suites.update', $suite), ['limits' => ['step' => null, 'check' => null]])->assertOk();
        $this->assertArrayNotHasKey('limits', $suite->fresh()->settings);
    }

    public function test_no_suite_waits_longer_than_the_most_an_admin_allows()
    {
        $user = User::factory()->create();
        $suite = $this->suite($user);

        // Past the most: refused
        $this->actingAs($user)->patchJson(route('checks.suites.update', $suite), ['limits' => ['step' => Limits::MOST['step'] + 1]])
            ->assertJsonValidationErrors('limits.step');

        // Only an admin sets the most
        $this->patchJson(route('admin.checks.limits'), ['step' => 20, 'check' => 120])->assertForbidden();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($user)->patchJson(route('checks.suites.update', $suite), ['limits' => ['step' => 45, 'check' => 600]])->assertOk();
        $this->actingAs($admin)->patchJson(route('admin.checks.limits'), ['step' => 20, 'check' => 120])
            ->assertOk()
            ->assertJsonPath('most', ['step' => 20, 'check' => 120]);

        // A suite that set more than that waits the most, as it is now
        $this->assertSame(['step' => 20_000, 'check' => 120_000], $this->sentFor($suite->fresh()));

        // Not even an admin goes past the ceiling
        $this->patchJson(route('admin.checks.limits'), ['step' => Limits::CEILING['step'] + 1, 'check' => 120])->assertJsonValidationErrors('step');
    }

    public function test_the_page_says_what_may_be_chosen_and_only_an_admin_may_set_the_most()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('checks.index'))
            ->assertInertia(fn ($page) => $page->where('limits.most', Limits::MOST)->where('limits.default', Limits::DEFAULT)->missing('limits.set'));

        $user->forceFill(['is_admin' => true])->save();
        $this->get(route('checks.index'))->assertInertia(fn ($page) => $page->where('limits.set', route('admin.checks.limits')));
    }

    public function test_an_assistant_sets_a_suites_time_limits()
    {
        $user = User::factory()->create();
        $suite = $this->suite($user);

        UserServer::actingAs($user)->tool(SaveBrowserSuite::class, ['suite' => $suite->ref_id, 'time_limits' => ['step' => 25]])->assertOk();

        $this->assertSame(['step' => 25], $suite->fresh()->settings['limits']);
    }
}
