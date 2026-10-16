<?php

namespace Tests\Feature;

use App\Jobs\RunBrowserChecks;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\BrowserChecks\GetBrowserSuite;
use App\Mcp\Tools\BrowserChecks\SaveBrowserCheck;
use App\Mcp\Tools\BrowserChecks\SaveBrowserSuite;
use App\Models\Browser\BrowserRun;
use App\Models\Browser\BrowserSuite;
use App\Models\Project;
use App\Models\Ticket\Ticket;
use App\Models\User;
use App\Support\Browser\SuiteRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * Signing in once a run: a suite's sign-in check is done first, and every
 * other check starts from the browser state it left -- held for the run
 * alone, never kept or shown.
 */
class BrowserSignInTest extends TestCase
{
    use RefreshDatabase;

    /** What a sign-in leaves: a session cookie and the token a site keeps in localStorage. */
    private const STATE = [
        'cookies' => [['name' => 'session', 'value' => 'cookie-secret-123', 'domain' => 'app.example.com', 'path' => '/']],
        'origins' => [['origin' => 'https://app.example.com', 'localStorage' => [['name' => 'promptx.session_token', 'value' => 'token-secret-456']]]],
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /** What a request to the browser said of `$key`, if it said anything. */
    private static function sent(Request $request, string $key): mixed
    {
        return $request->data()[$key] ?? null;
    }

    private function suite(User|Project|null $owner = null): BrowserSuite
    {
        $suite = (new BrowserSuite([
            'title' => 'App',
            'checks' => [
                ['id' => 'in', 'title' => 'Sign in', 'steps' => [
                    ['do' => 'open', 'url' => 'https://app.example.com/signin'],
                    ['do' => 'type', 'target' => 'Email', 'text' => '{{email}}'],
                ]],
                ['id' => 'admin', 'title' => 'Admin sign in', 'steps' => [['do' => 'open', 'url' => 'https://app.example.com/admin']]],
                ['id' => 'bill', 'title' => 'Billing', 'steps' => [['do' => 'open', 'url' => 'https://app.example.com/billing']]],
                ['id' => 'team', 'title' => 'Team', 'steps' => [['do' => 'open', 'url' => 'https://app.example.com/team']]],
            ],
        ]))->ownedBy($owner ?? $this->user, $this->user);
        $suite->take([
            'values' => [['name' => 'email', 'value' => 'sam@example.com']],
            'sign_ins' => [['name' => 'member', 'check' => 'Sign in'], ['name' => 'admin', 'check' => 'admin']],
        ])->save();

        return $suite;
    }

    /** The browser: a sign-in asked to keep its state leaves one, unless `$fails`. */
    private function browser(bool $fails = false): void
    {
        Http::fake(['chrome:3000/check' => function (Request $request) use ($fails) {
            $steps = self::sent($request, 'steps') ?? array_merge(...array_column(self::sent($request, 'chapters'), 'steps'));
            $done = array_map(fn () => ['ok' => true, 'took' => 100], $steps);

            if ($fails && self::sent($request, 'keepState')) {
                $done[1] = ['ok' => false, 'took' => 10000, 'error' => 'Nothing called "Email" turned up.'];
            }

            return Http::response([
                'steps' => $done,
                ...(isset($request->data()['chapters']) ? ['chapters' => array_map(fn (array $chapter) => array_map(fn () => ['ok' => true, 'took' => 100], $chapter['steps']), self::sent($request, 'chapters'))] : []),
                'url' => 'https://app.example.com/', 'title' => 'App', 'refused' => [], 'video' => null,
                ...(self::sent($request, 'keepState') && ! $fails ? ['state' => self::STATE] : []),
            ]);
        }]);
    }

    private function runOf(BrowserSuite $suite, array $settings = []): BrowserRun
    {
        $run = SuiteRun::start($suite, $this->user, [...SuiteRun::settings('quick'), ...$settings]);
        (new RunBrowserChecks($run->id))->handle();

        return $run->refresh();
    }

    public function test_a_run_signs_in_once_and_every_other_check_starts_signed_in()
    {
        $this->browser();

        $run = $this->runOf($this->suite());

        // Signed in once, as the one person the checks start as, with the hidden value typed
        $this->assertSame(BrowserRun::PASSED, $run->state);
        $this->assertSame(['Signing in as "member"', 'Billing', 'Team'], array_column($run->results, 'title'));
        $this->assertSame('member', $run->results[0]['sign_in']);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => self::sent($request, 'keepState') === true
            && self::sent($request, 'steps')[1]['text'] === 'sam@example.com'
            && $request['pace'] === ['glide' => 0, 'typing' => 0, 'pause' => 0]);
        Http::assertSent(fn (Request $request) => self::sent($request, 'steps')[0]['url'] === 'https://app.example.com/billing' && self::sent($request, 'state') === self::STATE && ! self::sent($request, 'keepState'));

        // The state lasts the run alone: kept nowhere
        foreach (['cookie-secret-123', 'token-secret-456'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) json_encode(BrowserRun::sole()->getAttributes()));
            $this->assertStringNotContainsString($secret, (string) json_encode(BrowserSuite::sole()->getAttributes()));
        }
    }

    public function test_a_check_starts_as_another_sign_in_or_signed_out()
    {
        $this->browser();
        $suite = $this->suite();
        $checks = $suite->checks;
        $checks[2]['as'] = 'admin';
        $checks[3]['fresh'] = true;
        $suite->take(['checks' => $checks])->save();

        $run = $this->runOf($suite);

        // Only whom a check needs is signed in as
        $this->assertSame(['Signing in as "admin"', 'Billing', 'Team'], array_column($run->results, 'title'));
        Http::assertSent(fn (Request $request) => self::sent($request, 'steps')[0]['url'] === 'https://app.example.com/team' && self::sent($request, 'state') === null);
        Http::assertNotSent(fn (Request $request) => self::sent($request, 'steps')[0]['url'] === 'https://app.example.com/signin');
    }

    public function test_a_sign_in_that_fails_is_said_once_and_the_checks_after_it_are_not_run()
    {
        $this->browser(fails: true);
        $project = Project::start($this->user, 'Customer');
        $suite = $this->suite($project);
        $suite->forceFill(['settings' => [...$suite->settings, 'every' => 'off', 'tickets' => true]])->save();

        $run = $this->runOf($suite);

        $this->assertSame(BrowserRun::FAILED, $run->state);
        $this->assertSame('Sign-in failed: Signing in as "member" -- Failed at step 2, Type "{{email}}" into "Email": Nothing called "Email" turned up. 2 checks not run.', SuiteRun::summary($run));
        $this->assertSame(['Not run: signing in as "member" failed.', 'Not run: signing in as "member" failed.'], array_column(array_slice($run->results, 1), 'problem'));
        Http::assertSentCount(1);

        // One ticket, for the sign-in: not one for every check it kept from running
        $this->assertSame(['Browser check fails: Signing in as "member" (App)'], Ticket::pluck('title')->all());
    }

    public function test_a_film_signs_in_first_off_camera_and_each_check_starts_as_it_says()
    {
        $this->browser();
        $suite = $this->suite();
        $checks = $suite->checks;
        $checks[3]['as'] = 'admin';
        $suite->take(['checks' => $checks])->save();

        $run = $this->runOf($suite, SuiteRun::settings('steady', captions: true, record: true, keep: false, oneFilm: true));

        $this->assertSame(BrowserRun::PASSED, $run->state);
        // Two sign-ins, unfilmed, then the one film
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => isset($request->data()['chapters'])
            && array_column(self::sent($request, 'chapters'), 'as') === ['member', 'admin']
            // (sent as a JSON object, so that none is {} and not [])
            && (array) $request->data()['states'] === ['member' => self::STATE, 'admin' => self::STATE]);
        Http::assertNotSent(fn (Request $request) => self::sent($request, 'keepState') && self::sent($request, 'record'));
    }

    public function test_a_sign_in_check_asked_for_alone_runs_as_itself()
    {
        $this->browser();

        $run = $this->runOf($this->suite(), ['checks' => ['in']]);

        $this->assertSame(['Sign in'], array_column($run->results, 'title'));
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => self::sent($request, 'state') === null && ! self::sent($request, 'keepState'));
    }

    public function test_an_assistant_sets_who_signs_in_and_whom_a_check_starts_as()
    {
        $suite = $this->suite();
        $server = UserServer::actingAs($this->user);

        $server->tool(SaveBrowserSuite::class, ['suite' => $suite->ref_id, 'sign_ins' => [['name' => 'owner', 'check' => 'Sign in']]])->assertOk();
        $this->assertSame([['name' => 'owner', 'check' => 'in']], $suite->refresh()->signIns());

        $server->tool(SaveBrowserSuite::class, ['suite' => $suite->ref_id, 'sign_ins' => [['name' => 'owner', 'check' => 'Nope']]])
            ->assertHasErrors(['which this suite does not have']);

        $server->tool(SaveBrowserCheck::class, ['suite' => $suite->ref_id, 'check' => 'Team', 'fresh' => true, 'steps' => [['do' => 'open', 'url' => 'https://app.example.com/team']]])->assertOk();
        $server->tool(SaveBrowserCheck::class, ['suite' => $suite->ref_id, 'check' => 'Billing', 'as' => 'stranger', 'steps' => [['do' => 'open', 'url' => 'https://app.example.com/billing']]])
            ->assertHasErrors(['no sign-in called "stranger"']);

        $server->tool(GetBrowserSuite::class, ['suite' => $suite->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('sign_ins', [['name' => 'owner', 'check' => 'Sign in']])
                ->where('checks.2.starts_as', 'owner')
                ->where('checks.3.starts_as', null)
                ->etc());
    }

    public function test_the_page_keeps_who_signs_in_by_the_checks_id()
    {
        $suite = $this->suite();

        $this->actingAs($this->user)->patchJson(route('checks.suites.update', $suite), ['sign_ins' => [['name' => 'member', 'check' => 'bill']]])->assertOk();
        $this->assertSame([['name' => 'member', 'check' => 'bill']], $suite->refresh()->signIns());

        // Its timetable changed: who signs in stays
        $this->actingAs($this->user)->patchJson(route('checks.suites.update', $suite), ['settings' => ['every' => 'day']])->assertOk();
        $this->actingAs($this->user)->getJson(route('checks.suites.show', $suite))
            ->assertJsonPath('sign_ins', [['name' => 'member', 'check' => 'bill']]);
    }
}
