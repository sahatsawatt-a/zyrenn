<?php

namespace Tests\Feature;

use App\Jobs\RunBrowserChecks;
use App\Mcp\Servers\GlobalServer;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\BrowserChecks\CloseBrowserPage;
use App\Mcp\Tools\BrowserChecks\DoBrowserStep;
use App\Mcp\Tools\BrowserChecks\EditBrowserCheck;
use App\Mcp\Tools\BrowserChecks\GetBrowserRun;
use App\Mcp\Tools\BrowserChecks\GetBrowserSuite;
use App\Mcp\Tools\BrowserChecks\ListBrowserSuites;
use App\Mcp\Tools\BrowserChecks\LookAtPage;
use App\Mcp\Tools\BrowserChecks\OpenBrowserPage;
use App\Mcp\Tools\BrowserChecks\RunBrowserSuite;
use App\Mcp\Tools\BrowserChecks\SaveBrowserCheck;
use App\Mcp\Tools\BrowserChecks\SaveBrowserSuite;
use App\Models\Browser\BrowserRun;
use App\Models\Browser\BrowserSuite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * Browser checks over MCP: a page looked at for the names its steps go by,
 * a suite written and changed, run and followed -- the user's own, or a
 * project's for those allowed to change it.
 */
class McpBrowserChecksTest extends TestCase
{
    use RefreshDatabase;

    private const STEPS = [
        ['do' => 'open', 'url' => 'https://shop.example.com'],
        ['do' => 'type', 'target' => 'Password', 'text' => '{{password}}'],
        ['do' => 'click', 'target' => 'Log in'],
    ];

    public function test_a_page_is_looked_at_for_what_steps_can_go_by()
    {
        Http::fake(['chrome:3000/look' => Http::response([
            'steps' => [['ok' => true, 'took' => 300]],
            'url' => 'https://shop.example.com/',
            'title' => 'Shop',
            'refused' => [],
            'things' => [
                ['kind' => 'field', 'name' => 'Email', 'count' => 1, 'box' => ['x' => 0, 'y' => 0, 'w' => 10, 'h' => 10]],
                ['kind' => 'button', 'name' => 'Add to cart', 'count' => 6, 'box' => ['x' => 0, 'y' => 0, 'w' => 10, 'h' => 10]],
            ],
            'below' => 2,
            'shot' => 'data:image/jpeg;base64,/9j/',
            'size' => ['width' => 1280, 'height' => 800],
        ])]);

        UserServer::actingAs(User::factory()->create())
            ->tool(LookAtPage::class, ['steps' => [['do' => 'open', 'url' => 'https://shop.example.com']]])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('title', 'Shop')
                ->where('stopped', null)
                ->where('things', [
                    ['kind' => 'field', 'name' => 'Email'],
                    ['kind' => 'button', 'name' => 'Add to cart', 'count' => 6],
                ])
                ->where('further_down', 2)
                ->etc());

        UserServer::actingAs(User::factory()->create())
            ->tool(LookAtPage::class, ['steps' => [['do' => 'click', 'target' => 'Buy']]])
            ->assertHasErrors(['Start with {"do": "open"']);
    }

    public function test_a_suite_is_written_changed_and_read_without_its_hidden_values()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(SaveBrowserSuite::class, [
                'title' => 'Shop',
                'checks' => [['title' => 'Logging in', 'steps' => self::STEPS]],
                'runs_by_itself' => ['every' => 'day', 'at' => '09:00', 'zone' => 'Asia/Bangkok'],
                'hidden_values' => [['name' => 'password', 'value' => 'secret_sauce']],
            ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('title', 'Shop')
                ->where('checks.0.steps.1.says', 'Type "{{password}}" into "Password"')
                ->where('runs_by_itself.every', 'day')
                ->where('hidden_values', ['password'])
                ->etc());

        $suite = BrowserSuite::sole();
        $id = $suite->checks[0]['id'];
        $this->assertSame(['password' => 'secret_sauce'], $suite->values());

        // A check keeps its id by its name; a value left out keeps its value
        UserServer::actingAs($user)
            ->tool(SaveBrowserSuite::class, [
                'suite' => 'Shop',
                'checks' => [['title' => 'Logging in', 'steps' => [...self::STEPS, ['do' => 'shot']]]],
                'hidden_values' => [['name' => 'password']],
            ])
            ->assertOk();
        $suite->refresh();
        $this->assertSame($id, $suite->checks[0]['id']);
        $this->assertCount(4, $suite->checks[0]['steps']);
        $this->assertSame(['password' => 'secret_sauce'], $suite->values());

        UserServer::actingAs($user)
            ->tool(GetBrowserSuite::class, ['suite' => $suite->ref_id])
            ->assertOk()
            ->assertDontSee('secret_sauce');

        UserServer::actingAs($user)->tool(ListBrowserSuites::class, [])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('suites.0.checks', ['Logging in'])
                ->where('suites.0.runs_by_itself', 'day')
                ->etc());
    }

    public function test_a_suite_is_run_and_its_run_followed()
    {
        Http::fake(['chrome:3000/check' => Http::response([
            'steps' => [['ok' => true, 'took' => 300], ['ok' => true, 'took' => 40], ['ok' => true, 'took' => 50]],
            'url' => 'https://shop.example.com/',
            'title' => 'Shop',
            'refused' => [],
            'video' => null,
        ])]);
        $user = User::factory()->create();
        $suite = (new BrowserSuite(['title' => 'Shop', 'checks' => [['id' => 'a', 'title' => 'Logging in', 'steps' => self::STEPS]]]))->ownedBy($user, $user);
        $suite->save();

        // Run where it is asked, the queue doing it at once here
        UserServer::actingAs($user)
            ->tool(RunBrowserSuite::class, ['suite' => 'Shop', 'as' => 'test'])
            ->assertOk();
        $run = BrowserRun::sole();
        $this->assertSame('passed', $run->state);
        Http::assertSent(fn (Request $request) => $request['steps'][1]['text'] === '{{password}}');

        UserServer::actingAs($user)
            ->tool(GetBrowserRun::class, ['ref_id' => $run->ref_id])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('state', 'passed')
                ->where('summary', 'Passed: the check did what it should.')
                ->where('checks.0.says', 'Passed: 3 steps')
                ->etc());

        Queue::fake();
        UserServer::actingAs($user)
            ->tool(RunBrowserSuite::class, ['suite' => 'Shop', 'check' => 'Nope'])
            ->assertHasErrors(['no check called "Nope"']);
        UserServer::actingAs($user)
            ->tool(RunBrowserSuite::class, ['suite' => 'Shop', 'as' => 'demo'])
            ->assertOk();
        Queue::assertPushed(RunBrowserChecks::class);
        $demo = BrowserRun::latest('id')->first()->settings;
        // A demo goes steady, unless asked otherwise
        $this->assertTrue($demo['record']);
        $this->assertSame('steady', $demo['speed']);
        // And cuts the waiting on the site from its film, unless asked not to
        $this->assertTrue($demo['cut_waits']);
        UserServer::actingAs($user)
            ->tool(RunBrowserSuite::class, ['suite' => 'Shop', 'as' => 'demo', 'cut_waits' => false])
            ->assertOk();
        $this->assertFalse(BrowserRun::latest('id')->first()->settings['cut_waits']);
        UserServer::actingAs($user)
            ->tool(RunBrowserSuite::class, ['suite' => 'Shop', 'as' => 'demo', 'speed' => 'slow'])
            ->assertOk();
        $this->assertSame(['glide' => 600, 'typing' => 90, 'pause' => 1500], BrowserRun::latest('id')->first()->settings['pace']);
        UserServer::actingAs($user)
            ->tool(RunBrowserSuite::class, ['suite' => 'Shop', 'as' => 'demo', 'speed' => 'quick'])
            ->assertHasErrors(['"speed" is one of watch, steady or slow.']);
    }

    public function test_a_projects_suites_are_changed_and_run_only_by_its_owners_and_editors()
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $project = Project::factory()->withMember($owner)->create(['name' => 'Customer']);
        $project->members()->attach($viewer, ['role' => Project::VIEWER]);

        UserServer::actingAs($owner)
            ->tool(SaveBrowserSuite::class, ['project' => 'Customer', 'title' => 'Shop', 'files_tickets' => true])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('files_tickets', true)->etc());
        $this->assertSame($project->id, BrowserSuite::sole()->project_id);

        UserServer::actingAs($viewer)
            ->tool(GetBrowserSuite::class, ['project' => 'Customer', 'suite' => 'Shop'])
            ->assertOk();
        UserServer::actingAs($viewer)
            ->tool(SaveBrowserSuite::class, ['project' => 'Customer', 'suite' => 'Shop', 'title' => 'Mine'])
            ->assertHasErrors(['only its owners and editors']);
        UserServer::actingAs($viewer)
            ->tool(RunBrowserSuite::class, ['project' => 'Customer', 'suite' => 'Shop'])
            ->assertHasErrors(['only its owners and editors']);

        GlobalServer::actingAs(User::factory()->create())
            ->tool(ListBrowserSuites::class, ['user_id' => $owner->id, 'project' => 'Customer'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('suites.0.title', 'Shop')->etc());
    }

    public function test_one_whole_check_is_written_leaving_the_others_alone()
    {
        $user = User::factory()->create();
        $suite = (new BrowserSuite(['title' => 'Shop', 'checks' => [
            ['id' => 'a', 'title' => 'Logging in', 'steps' => self::STEPS],
            ['id' => 'b', 'title' => 'Home', 'steps' => [['do' => 'open', 'url' => 'https://shop.example.com']]],
        ]]))->ownedBy($user, $user);
        $suite->save();

        UserServer::actingAs($user)
            ->tool(SaveBrowserCheck::class, ['suite' => 'Shop', 'check' => 'Logging in', 'size' => 'phone', 'steps' => [...self::STEPS, ['do' => 'see', 'target' => 'Welcome']]])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('replaced.size', 'phone')
                ->where('replaced.steps.3.says', 'Expect to see "Welcome"')
                ->etc());
        UserServer::actingAs($user)
            ->tool(SaveBrowserCheck::class, ['suite' => 'Shop', 'check' => 'Paying', 'steps' => [['do' => 'open', 'url' => 'https://shop.example.com/pay']]])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->has('made')->etc());

        $suite->refresh();
        $this->assertSame(['a', 'b'], array_slice(array_column($suite->checks, 'id'), 0, 2));
        $this->assertCount(4, $suite->checks[0]['steps']);
        $this->assertSame([['do' => 'open', 'url' => 'https://shop.example.com']], $this->withoutStepIds($suite->checks[1]['steps']));
        $this->assertSame('Paying', $suite->checks[2]['title']);
    }

    public function test_a_check_is_changed_a_step_at_a_time()
    {
        $user = User::factory()->create();
        $suite = (new BrowserSuite(['title' => 'Shop', 'checks' => [['id' => 'a', 'title' => 'Logging in', 'steps' => self::STEPS]]]))->ownedBy($user, $user);
        $suite->save();

        UserServer::actingAs($user)
            ->tool(EditBrowserCheck::class, [
                'suite' => 'Shop',
                'check' => 'Logging in',
                'remove' => [2],
                'replace' => [['step' => 3, 'with' => ['do' => 'click', 'target' => 'Sign in']]],
                'add' => [['do' => 'type', 'target' => 'Email', 'text' => 'ada@example.com']],
                'at' => 3,
                'rename' => 'Signing in',
                'size' => 'tablet',
            ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('check.title', 'Signing in')
                ->where('check.size', 'tablet')
                ->where('check.steps.0.says', 'Open shop.example.com')
                ->where('check.steps.1.says', 'Type "ada@example.com" into "Email"')
                ->where('check.steps.2.says', 'Click "Sign in"')
                ->etc());
        $this->assertSame('a', $suite->fresh()->checks[0]['id']);

        UserServer::actingAs($user)
            ->tool(EditBrowserCheck::class, ['suite' => 'Shop', 'check' => 'Signing in', 'remove' => [9]])
            ->assertHasErrors(['there is no step 9']);

        // A note put on a step leaves the step as it was; "" takes it away
        UserServer::actingAs($user)
            ->tool(EditBrowserCheck::class, ['suite' => 'Shop', 'check' => 'Signing in', 'notes' => [
                ['step' => 2, 'note' => 'Your work email: the one you were invited with.'],
            ]])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('check.steps.1.note', 'Your work email: the one you were invited with.')
                ->where('check.steps.1.text', 'ada@example.com')
                ->etc());
        UserServer::actingAs($user)
            ->tool(EditBrowserCheck::class, ['suite' => 'Shop', 'check' => 'Signing in', 'notes' => [['step' => 2, 'note' => '']]])
            ->assertOk();
        $this->assertArrayNotHasKey('note', $suite->fresh()->checks[0]['steps'][1]);
        UserServer::actingAs($user)
            ->tool(EditBrowserCheck::class, ['suite' => 'Shop', 'check' => 'Signing in', 'notes' => [['step' => 7, 'note' => 'Hi']]])
            ->assertHasErrors(['there is no step 7']);

        // How long a demo stays on a step, and back to the default
        UserServer::actingAs($user)
            ->tool(EditBrowserCheck::class, ['suite' => 'Shop', 'check' => 'Signing in', 'waits' => [['step' => 1, 'wait' => 4.5], ['step' => 2, 'wait' => 0]]])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('check.steps.0.wait', '4.5')
                ->where('check.steps.1.wait', '0')
                ->etc());
        UserServer::actingAs($user)
            ->tool(EditBrowserCheck::class, ['suite' => 'Shop', 'check' => 'Signing in', 'waits' => [['step' => 1, 'wait' => null]]])
            ->assertOk();
        $this->assertArrayNotHasKey('wait', $suite->fresh()->checks[0]['steps'][0]);
    }

    public function test_a_check_is_built_on_the_page_a_step_at_a_time_keeping_what_works()
    {
        $id = '0f8fad5b-d9cb-469f-a165-70867728950e';
        $page = ['url' => 'https://shop.example.com/', 'title' => 'Shop', 'refused' => [], 'things' => [['kind' => 'button', 'name' => 'Log in', 'count' => 1, 'box' => ['x' => 0, 'y' => 0, 'w' => 9, 'h' => 9]]], 'below' => 0, 'shot' => null, 'size' => ['width' => 390, 'height' => 844]];
        Http::fake([
            'chrome:3000/live' => Http::response(['id' => $id, 'steps' => [['ok' => true, 'took' => 300]], ...$page]),
            "chrome:3000/live/{$id}/step" => Http::sequence()
                ->push(['step' => ['ok' => true, 'took' => 40], ...$page])
                ->push(['step' => ['ok' => false, 'took' => 10000, 'error' => 'Nothing showing "Nope" turned up in 10 seconds.'], ...$page]),
            "chrome:3000/live/{$id}/close" => Http::response(['ok' => true]),
        ]);
        $user = User::factory()->create();
        $suite = (new BrowserSuite(['title' => 'Shop', 'checks' => []]))->ownedBy($user, $user);
        $suite->save();

        UserServer::actingAs($user)
            ->tool(OpenBrowserPage::class, ['suite' => 'Shop', 'check' => 'Logging in', 'size' => 'phone', 'steps' => [['do' => 'open', 'url' => 'https://shop.example.com']]])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('page', $id)
                ->where('building.title', 'Logging in')
                ->where('things', [['kind' => 'button', 'name' => 'Log in']])
                ->etc());

        UserServer::actingAs(User::factory()->create())
            ->tool(DoBrowserStep::class, ['page' => $id, 'step' => ['do' => 'click', 'target' => 'Log in'], 'keep' => true])
            ->assertHasErrors(['That page has been closed']);

        UserServer::actingAs($user)
            ->tool(DoBrowserStep::class, ['page' => $id, 'step' => ['do' => 'click', 'target' => 'Log in'], 'keep' => true])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('worked', true)->where('kept', true)->etc());
        UserServer::actingAs($user)
            ->tool(DoBrowserStep::class, ['page' => $id, 'step' => ['do' => 'click', 'target' => 'Nope'], 'keep' => true])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('worked', false)->where('kept', false)->where('why', 'Nothing showing "Nope" turned up in 10 seconds.')->etc());

        $check = $suite->fresh()->checks[0];
        $this->assertSame('phone', $check['size']);
        $this->assertSame([
            ['do' => 'open', 'url' => 'https://shop.example.com'],
            ['do' => 'click', 'target' => 'Log in'],
        ], $this->withoutStepIds($check['steps']));

        UserServer::actingAs($user)->tool(CloseBrowserPage::class, ['page' => $id])->assertOk();
        UserServer::actingAs($user)
            ->tool(DoBrowserStep::class, ['page' => $id, 'step' => ['do' => 'shot']])
            ->assertHasErrors(['That page has been closed']);
    }
}
