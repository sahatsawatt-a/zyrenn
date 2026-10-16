<?php

namespace Tests\Feature;

use App\Jobs\RunBrowserChecks;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\BrowserChecks\EditBrowserCheck;
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
 * A step that waits longer, or less, than its suite's steps: a slow page to
 * open, a long job to see through. Kept with the step, sent to the browser
 * with it, and never past the most an admin allows.
 */
class BrowserStepTimeoutTest extends TestCase
{
    use RefreshDatabase;

    private function suite(User $user, array $steps): BrowserSuite
    {
        $suite = (new BrowserSuite(['title' => 'Slow shop', 'checks' => [
            ['id' => 'a', 'title' => 'Home', 'steps' => $steps],
        ]]))->ownedBy($user, $user);
        $suite->save();

        return $suite;
    }

    /** The steps the browser was sent for one run of `$suite`. */
    private function sentFor(BrowserSuite $suite): array
    {
        Http::fake(['chrome:3000/check' => Http::response(['steps' => [['ok' => true, 'took' => 100], ['ok' => true, 'took' => 100]], 'url' => 'https://example.com/', 'title' => 'Shop', 'refused' => [], 'video' => null])]);
        $run = SuiteRun::start($suite, $suite->creator, SuiteRun::settings('quick'));
        (new RunBrowserChecks($run->id))->handle();

        $sent = null;
        Http::assertSent(function (Request $request) use (&$sent) {
            $sent = $request['steps'];

            return true;
        });

        return $sent;
    }

    public function test_a_steps_own_limit_is_kept_and_sent_with_it()
    {
        $user = User::factory()->create();
        $suite = $this->suite($user, [['do' => 'open', 'url' => 'https://example.com']]);

        $this->actingAs($user)->patchJson(route('checks.suites.update', $suite), ['checks' => [['id' => 'a', 'title' => 'Home', 'steps' => [
            ['do' => 'open', 'url' => 'https://example.com', 'timeout' => 40],
            ['do' => 'see', 'target' => 'Welcome'],
        ]]]])->assertOk();

        $this->assertSame('40', $suite->fresh()->checks[0]['steps'][0]['timeout']);
        $sent = $this->sentFor($suite->fresh());
        $this->assertSame('40', $sent[0]['timeout']);
        // One that sets none waits the suite's, as before
        $this->assertArrayNotHasKey('timeout', $sent[1]);
    }

    public function test_it_never_waits_past_the_most_an_admin_allows_now()
    {
        $user = User::factory()->create();
        $suite = $this->suite($user, [['do' => 'open', 'url' => 'https://example.com', 'timeout' => 50]]);
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->assertSame('50', $this->sentFor($suite)[0]['timeout']);

        // An admin lowering the most lowers a step that asked for more
        $this->actingAs($admin)->patchJson(route('admin.checks.limits'), ['step' => 20, 'check' => 120])->assertOk();
        $this->assertSame('20', $this->sentFor($suite->fresh())[0]['timeout']);
    }

    public function test_an_assistant_gives_a_step_its_own_limit_and_takes_it_away()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)->tool(SaveBrowserSuite::class, ['title' => 'Shop', 'checks' => [['title' => 'Home', 'steps' => [
            ['do' => 'open', 'url' => 'https://example.com', 'timeout' => 30],
            ['do' => 'see', 'target' => 'Welcome'],
        ]]]])->assertOk();
        $suite = BrowserSuite::query()->where('title', 'Shop')->firstOrFail();
        $this->assertSame('30', $suite->checks[0]['steps'][0]['timeout']);

        UserServer::actingAs($user)->tool(EditBrowserCheck::class, ['suite' => 'Shop', 'check' => 'Home', 'timeouts' => [
            ['step' => 1, 'timeout' => null],
            ['step' => 2, 'timeout' => 25],
        ]])->assertOk();

        $sent = $this->sentFor($suite->fresh());
        $this->assertArrayNotHasKey('timeout', $sent[0]);
        $this->assertSame('25', $sent[1]['timeout']);
    }

    public function test_a_limit_that_makes_no_sense_is_refused()
    {
        $user = User::factory()->create();
        $suite = $this->suite($user, [['do' => 'open', 'url' => 'https://example.com']]);

        foreach ([0, Limits::CEILING['step'] + 1, 'soon'] as $timeout) {
            $this->actingAs($user)->patchJson(route('checks.suites.update', $suite), ['checks' => [['id' => 'a', 'title' => 'Home', 'steps' => [
                ['do' => 'open', 'url' => 'https://example.com', 'timeout' => $timeout],
            ]]]])->assertJsonValidationErrors('checks.0.steps.0.timeout');
        }
    }
}
