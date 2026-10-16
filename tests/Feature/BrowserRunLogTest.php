<?php

namespace Tests\Feature;

use App\Jobs\RunBrowserChecks;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\BrowserChecks\GetBrowserRun;
use App\Models\Browser\BrowserRun;
use App\Models\Browser\BrowserSuite;
use App\Models\User;
use App\Support\Browser\SuiteRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * Each run's log: what each step met in the browser, kept for the app's
 * admins -- hidden values blotted out, and held to a few hundred lines.
 */
class BrowserRunLogTest extends TestCase
{
    use RefreshDatabase;

    private function suite(User $user): BrowserSuite
    {
        $steps = [['do' => 'open', 'url' => 'https://example.com'], ['do' => 'type', 'target' => 'Password', 'text' => '{{password}}']];
        $suite = (new BrowserSuite(['title' => 'Shop', 'checks' => [
            ['id' => 'a', 'title' => 'Sign in', 'steps' => $steps],
            ['id' => 'b', 'title' => 'Cart', 'steps' => $steps],
        ]]))->ownedBy($user, $user);
        $suite->take(['values' => [['name' => 'password', 'value' => 'hunter2-secret']]])->save();

        return $suite;
    }

    /** @param  list<string>  $lines */
    private function browser(array $lines = ['+0.4s console.error: Uncaught TypeError: x is undefined']): void
    {
        Http::fake(['chrome:3000/check' => Http::response([
            'steps' => [['ok' => true, 'took' => 300, 'waited' => 250], ['ok' => true, 'took' => 40]],
            'url' => 'https://example.com/', 'title' => 'Example', 'refused' => [], 'video' => null,
            'log' => [
                ['chapter' => 0, 'step' => 0, 'from' => 10, 'to' => 310, 'url' => 'https://example.com/', 'waited' => 250, 'lines' => $lines],
                ['chapter' => 0, 'step' => 1, 'from' => 320, 'to' => 360, 'url' => 'https://example.com/hunter2-secret', 'lines' => ['+0.4s console.log: typed hunter2-secret']],
            ],
        ])]);
    }

    private function runOf(BrowserSuite $suite, User $user): BrowserRun
    {
        $run = SuiteRun::start($suite, $user, SuiteRun::settings('quick'));
        (new RunBrowserChecks($run->id))->handle();

        return $run->refresh();
    }

    public function test_each_step_is_kept_with_what_it_met_and_hidden_values_blotted_out()
    {
        $this->browser();
        $user = User::factory()->create();

        $run = $this->runOf($this->suite($user), $user);

        $this->assertSame(['Sign in', 'Sign in', 'Cart', 'Cart'], array_column($run->log, 'check'));
        $this->assertSame([0, 1, 0, 1], array_column($run->log, 'step'));
        $this->assertSame(['from' => 10, 'to' => 310, 'waited' => 250], array_intersect_key($run->log[0], array_flip(['from', 'to', 'waited'])));
        $this->assertSame(['+0.4s console.error: Uncaught TypeError: x is undefined'], $run->log[0]['lines']);

        // Never a hidden value, wherever it turned up
        $this->assertStringNotContainsString('hunter2', (string) json_encode($run->log));
        $this->assertSame('https://example.com/•••', $run->log[1]['url']);
        $this->assertSame(['+0.4s console.log: typed •••'], $run->log[1]['lines']);
    }

    public function test_a_runs_log_is_held_to_a_few_hundred_short_lines()
    {
        $this->browser(array_map(fn (int $at) => "+0.{$at}s console.error: ".str_repeat('x', 500), range(1, 300)));
        $user = User::factory()->create();

        $run = $this->runOf($this->suite($user), $user);
        $lines = array_merge(...array_column($run->log, 'lines'));

        $this->assertLessThanOrEqual(SuiteRun::LOG_LINES, count($lines) + count($run->log));
        $this->assertStringStartsWith('… ', $run->log[0]['lines'][array_key_last($run->log[0]['lines'])]);
        $this->assertLessThanOrEqual(240, max(array_map('mb_strlen', $lines)));
    }

    public function test_a_films_log_is_split_among_its_checks()
    {
        Http::fake(['chrome:3000/check' => Http::response([
            'steps' => [['ok' => true, 'took' => 1], ['ok' => true, 'took' => 1], ['ok' => true, 'took' => 1], ['ok' => true, 'took' => 1]],
            'chapters' => [[['ok' => true, 'took' => 1], ['ok' => true, 'took' => 1]], [['ok' => true, 'took' => 1], ['ok' => true, 'took' => 1]]],
            'url' => 'https://example.com/', 'title' => 'Example', 'refused' => [], 'video' => null,
            'log' => [
                ['chapter' => 0, 'step' => 0, 'from' => 0, 'to' => 5, 'url' => 'https://example.com/', 'lines' => ['a']],
                ['chapter' => 1, 'step' => 0, 'from' => 6, 'to' => 9, 'url' => 'https://example.com/cart', 'lines' => ['b']],
            ],
        ])]);
        $user = User::factory()->create();
        $run = SuiteRun::start($this->suite($user), $user, SuiteRun::settings('steady', record: true, oneFilm: true));

        (new RunBrowserChecks($run->id))->handle();

        $this->assertSame([['Sign in', 'a'], ['Cart', 'b']], array_map(fn (array $entry) => [$entry['check'], $entry['lines'][0]], $run->refresh()->log));
    }

    public function test_only_the_apps_admins_read_it()
    {
        $this->browser();
        $user = User::factory()->create();
        $run = $this->runOf($this->suite($user), $user);

        $this->actingAs($user)->getJson(route('checks.runs.show', $run))->assertJsonPath('log', null);
        UserServer::actingAs($user)->tool(GetBrowserRun::class, ['ref_id' => $run->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->missing('log')->etc());

        $user->forceFill(['is_admin' => true])->save();

        $this->actingAs($user)->getJson(route('checks.runs.show', $run))->assertJsonPath('log.0.check', 'Sign in');
        UserServer::actingAs($user)->tool(GetBrowserRun::class, ['ref_id' => $run->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('log.0.waited', 250)->etc());
    }
}
