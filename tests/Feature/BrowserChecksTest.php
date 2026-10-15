<?php

namespace Tests\Feature;

use App\Jobs\RunBrowserChecks;
use App\Models\Browser\BrowserRun;
use App\Models\Browser\BrowserSuite;
use App\Models\Drive\DriveFile;
use App\Models\Project;
use App\Models\User;
use App\Support\Browser\BrowserCheck;
use App\Support\Browser\SuiteRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Suites of browser checks: kept by a person or a project, run in the
 * browser on the server at a chosen speed, their pictures and films kept in
 * the owner's Drive.
 */
class BrowserChecksTest extends TestCase
{
    use RefreshDatabase;

    private const PICTURE = 'data:image/jpeg;base64,/9j/4AAQ';

    // The start of a real film, enough to be known as one
    private const FILM = 'data:video/webm;base64,GkXfo59ChoEBQveBAULygQRC84EIQoKEd2VibUKHgQJChYECGFOAZwEAAAAAAfkC';

    /** @param  list<array<string, mixed>>  $steps */
    private function suiteOf(User|Project $owner, User $by, array $steps = [['do' => 'open', 'url' => 'https://example.com'], ['do' => 'shot']]): BrowserSuite
    {
        $suite = (new BrowserSuite([
            'title' => 'Shop',
            'checks' => [['id' => 'home', 'title' => 'Home', 'steps' => $steps]],
        ]))->ownedBy($owner, $by);
        $suite->save();

        return $suite;
    }

    private function browserAnswers(?string $video = null): void
    {
        Http::fake(['chrome:3000/check' => Http::response([
            'steps' => [['ok' => true, 'took' => 300], ['ok' => true, 'took' => 5, 'shot' => self::PICTURE]],
            'url' => 'https://example.com/',
            'title' => 'Example',
            'refused' => [],
            'video' => $video,
        ])]);
    }

    public function test_a_suite_is_made_and_kept_as_it_is_written()
    {
        $user = User::factory()->create();

        $made = $this->actingAs($user)->postJson(route('checks.suites.store'), ['title' => 'Customer shop'])
            ->assertCreated()
            ->json();

        $this->actingAs($user)->patchJson($made['urls']['update'], [
            'title' => 'Customer shop, live',
            'checks' => [['id' => 'buy', 'title' => 'Buying', 'steps' => [
                ['do' => 'open', 'url' => 'https://shop.example.com', 'target' => 'left over'],
                ['do' => 'type', 'target' => 'Email', 'text' => 'ada@example.com'],
            ]]],
        ])->assertOk();

        $suite = BrowserSuite::where('ref_id', $made['ref_id'])->firstOrFail();
        $this->assertSame($user->id, $suite->user_id);
        $this->assertSame('Customer shop, live', $suite->title);
        $this->assertSame([
            ['do' => 'open', 'url' => 'https://shop.example.com'],
            ['do' => 'type', 'target' => 'Email', 'text' => 'ada@example.com'],
        ], $suite->checks[0]['steps']);
    }

    public function test_a_new_suite_starts_empty_and_runs_only_once_it_has_a_check()
    {
        Queue::fake();
        $user = User::factory()->create();

        $made = $this->actingAs($user)->postJson(route('checks.suites.store'), ['title' => 'Shop'])
            ->assertCreated()
            ->assertJsonPath('checks', [])
            ->json();

        $this->actingAs($user)->postJson($made['urls']['runs'], ['speed' => 'quick', 'checks' => []])
            ->assertJsonValidationErrors(['checks' => 'Add a check first: there is nothing to run yet.']);
        Queue::assertNothingPushed();
    }

    public function test_a_suite_is_kept_half_written_but_runs_only_when_each_step_says_what_it_needs()
    {
        Queue::fake();
        $user = User::factory()->create();
        $suite = $this->suiteOf($user, $user);

        $this->actingAs($user)
            ->patchJson(route('checks.suites.update', $suite), ['checks' => [['id' => 'a', 'title' => 'Buying', 'steps' => [
                ['do' => 'open', 'url' => 'https://shop.example.com'],
                ['do' => 'click', 'target' => ''],
            ]]]])
            ->assertOk();

        $this->actingAs($user)->postJson(route('checks.runs.store', $suite), ['speed' => 'quick', 'checks' => []])
            ->assertJsonValidationErrors(['checks' => 'Before it can run -- Buying, step 2: say what to click.']);

        $this->actingAs($user)
            ->patchJson(route('checks.suites.update', $suite), ['checks' => [['id' => 'a', 'title' => 'A', 'steps' => [
                ['do' => 'press', 'key' => 'Enter; rm'],
                ['do' => 'fly'],
            ]]]])
            ->assertJsonValidationErrors(['checks.0.steps.0.key', 'checks.0.steps.1.do']);
        Queue::assertNothingPushed();
    }

    public function test_what_is_on_the_page_the_steps_before_lead_to_can_be_picked_from()
    {
        Http::fake(['chrome:3000/look' => Http::response([
            'steps' => [['ok' => true, 'took' => 300]],
            'url' => 'https://shop.example.com/',
            'title' => 'Shop',
            'refused' => [],
            'things' => [['kind' => 'field', 'name' => 'Username', 'count' => 1, 'box' => ['x' => 10, 'y' => 20, 'w' => 200, 'h' => 30]]],
            'below' => 0,
            'shot' => self::PICTURE,
            'size' => ['width' => 1280, 'height' => 800],
        ])]);
        $user = User::factory()->create();
        $suite = $this->suiteOf($user, $user);

        $this->actingAs($user)->postJson(route('checks.suites.look', $suite), ['title' => 'Buying', 'steps' => [
            ['do' => 'open', 'url' => 'https://shop.example.com'],
            ['do' => 'shot'],
        ]])->assertOk()->assertJsonPath('things.0.name', 'Username');

        // Pictures are no help in getting there
        Http::assertSent(fn (Request $request) => $request['steps'] === [
            ['do' => 'open', 'url' => 'https://shop.example.com', 'say' => 'Open shop.example.com'],
        ]);

        $this->actingAs($user)->postJson(route('checks.suites.look', $suite), ['steps' => [['do' => 'click', 'target' => 'Buy']]])
            ->assertJsonValidationErrors(['steps' => 'Start the check with "Open a page"']);
        $this->actingAs($user)->postJson(route('checks.suites.look', $suite), ['title' => 'Buying', 'steps' => [
            ['do' => 'open', 'url' => 'https://shop.example.com'],
            ['do' => 'type', 'target' => '', 'text' => 'ada'],
        ]])->assertJsonValidationErrors(['steps' => 'To get to the page -- Buying, step 2: say which field to type into.']);

        $viewer = User::factory()->create();
        $this->actingAs($viewer)->postJson(route('checks.suites.look', $suite), ['steps' => [['do' => 'open', 'url' => 'https://a.example']]])
            ->assertForbidden();
    }

    public function test_a_live_page_is_built_on_by_whoever_opened_it_alone()
    {
        $id = '0f8fad5b-d9cb-469f-a165-70867728950e';
        $page = ['url' => 'https://shop.example.com/', 'title' => 'Shop', 'refused' => [], 'things' => [], 'below' => 0, 'shot' => null, 'size' => ['width' => 390, 'height' => 844]];
        Http::fake([
            'chrome:3000/live' => Http::response(['id' => $id, 'steps' => [['ok' => true, 'took' => 300]], ...$page]),
            "chrome:3000/live/{$id}/step" => Http::response(['step' => ['ok' => true, 'took' => 40], ...$page]),
            "chrome:3000/live/{$id}/close" => Http::response(['ok' => true]),
        ]);
        $user = User::factory()->create();
        $suite = $this->suiteOf($user, $user);
        $suite->secret_values = [['name' => 'password', 'value' => 'secret_sauce']];
        $suite->save();

        $this->actingAs($user)->postJson(route('checks.suites.live', $suite), [
            'size' => 'phone',
            'steps' => [['do' => 'open', 'url' => 'https://shop.example.com']],
        ])->assertOk()->assertJsonPath('id', $id);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://chrome:3000/live' && $request['size'] === 'phone');

        // Someone else cannot go on with it
        $this->actingAs(User::factory()->create())
            ->postJson(route('checks.live.step', $id), ['step' => ['do' => 'click', 'target' => 'Log in']])
            ->assertNotFound();

        $this->actingAs($user)
            ->postJson(route('checks.live.step', $id), ['step' => ['do' => 'type', 'target' => 'Password', 'text' => '{{password}}']])
            ->assertOk()
            ->assertJsonPath('step.ok', true);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/step') && $request['step']['text'] === 'secret_sauce');

        $this->actingAs($user)->postJson(route('checks.live.step', $id), ['step' => ['do' => 'click']])
            ->assertJsonValidationErrors('steps.0.target');

        // A second live page of theirs closes the first
        $this->actingAs($user)->postJson(route('checks.suites.live', $suite), ['steps' => [['do' => 'open', 'url' => 'https://shop.example.com']]])->assertOk();
        Http::assertSent(fn (Request $request) => $request->url() === "http://chrome:3000/live/{$id}/close");

        $this->actingAs($user)->postJson(route('checks.live.close', $id))->assertOk();
        $this->actingAs($user)->postJson(route('checks.live.step', $id), ['step' => ['do' => 'shot']])->assertNotFound();
    }

    public function test_a_check_is_done_on_the_screen_it_names()
    {
        $this->browserAnswers();
        $user = User::factory()->create();
        $suite = $this->suiteOf($user, $user);

        $this->actingAs($user)->patchJson(route('checks.suites.update', $suite), ['checks' => [
            ['id' => 'home', 'title' => 'On a phone', 'size' => 'phone', 'steps' => [['do' => 'open', 'url' => 'https://example.com'], ['do' => 'shot']]],
        ]])->assertOk();
        $this->assertSame('phone', $suite->fresh()->checks[0]['size']);

        $this->actingAs($user)->patchJson(route('checks.suites.update', $suite), ['checks' => [
            ['id' => 'home', 'title' => 'On a watch', 'size' => 'watch', 'steps' => [['do' => 'shot']]],
        ]])->assertJsonValidationErrors('checks.0.size');

        $run = $suite->runs()->create(['state' => 'waiting', 'created_by' => $user->id, 'settings' => [
            'speed' => 'quick', 'pace' => ['glide' => 0, 'typing' => 0, 'pause' => 0],
            'captions' => false, 'record' => false, 'keep' => false, 'checks' => [],
        ]]);
        (new RunBrowserChecks($run->id))->handle();

        Http::assertSent(fn (Request $request) => $request['size'] === 'phone');
        $this->assertSame('phone', $run->fresh()->results[0]['size']);
    }

    public function test_a_thing_picked_from_the_page_is_found_where_it_was_and_said_as_which()
    {
        $user = User::factory()->create();
        $suite = $this->suiteOf($user, $user);

        $this->actingAs($user)->patchJson(route('checks.suites.update', $suite), ['checks' => [['id' => 'a', 'title' => 'Buying', 'steps' => [
            ['do' => 'open', 'url' => 'https://shop.example.com', 'selector' => 'body > main'],
            ['do' => 'click', 'target' => 'Add to cart', 'selector' => '#inventory > div:nth-of-type(3) > button', 'nth' => 3],
            ['do' => 'click', 'target' => 'Thumbnail for Cosmos', 'selector' => 'body > a:nth-of-type(2)'],
        ]]]])->assertOk();

        $steps = $suite->fresh()->checks[0]['steps'];
        // Only what a step's kind uses: an address is not found by place
        $this->assertSame(['do' => 'open', 'url' => 'https://shop.example.com'], $steps[0]);
        $this->assertSame(['do' => 'click', 'target' => 'Add to cart', 'selector' => '#inventory > div:nth-of-type(3) > button', 'nth' => '3'], $steps[1]);
        $this->assertSame('Click the 3rd "Add to cart"', BrowserCheck::say($steps[1]));
        $this->assertSame('Click "Thumbnail for Cosmos"', BrowserCheck::say($steps[2]));
    }

    public function test_an_option_is_chosen_from_a_list_the_browser_draws()
    {
        $user = User::factory()->create();
        $suite = $this->suiteOf($user, $user);

        $this->actingAs($user)->patchJson(route('checks.suites.update', $suite), ['checks' => [['id' => 'a', 'title' => 'Sorting', 'steps' => [
            ['do' => 'open', 'url' => 'https://shop.example.com'],
            ['do' => 'choose', 'target' => 'Sort by', 'text' => 'Price (low to high)', 'url' => 'https://left.over'],
        ]]]])->assertOk();

        $step = $suite->fresh()->checks[0]['steps'][1];
        $this->assertSame(['do' => 'choose', 'target' => 'Sort by', 'text' => 'Price (low to high)'], $step);
        $this->assertSame('Choose "Price (low to high)" in "Sort by"', BrowserCheck::say($step));
        $this->assertSame(
            'Sorting, step 2: say what to choose',
            BrowserCheck::unready(['title' => 'Sorting', 'steps' => [['do' => 'open', 'url' => 'https://a.example'], ['do' => 'choose', 'target' => 'Sort by', 'text' => '']]]),
        );
    }

    public function test_steps_point_at_add_files_go_back_and_expect_what_is_gone_and_where_the_page_is()
    {
        $user = User::factory()->create();
        $suite = $this->suiteOf($user, $user);

        $this->actingAs($user)->patchJson(route('checks.suites.update', $suite), ['checks' => [['id' => 'a', 'title' => 'Ordering', 'steps' => [
            ['do' => 'open', 'url' => 'https://shop.example.com'],
            ['do' => 'hover', 'target' => 'Products', 'text' => 'left over'],
            ['do' => 'click', 'target' => 'comments', 'nth' => 3],
            ['do' => 'type', 'target' => 'Card number', 'text' => '4242', 'frame' => 'body > iframe'],
            ['do' => 'upload', 'target' => 'Photo', 'file' => 'abc123', 'text' => 'cat.jpg', 'selector' => '#photo'],
            ['do' => 'back', 'target' => 'left over'],
            ['do' => 'gone', 'target' => 'Loading…'],
            ['do' => 'address', 'text' => '/thank-you'],
        ]]]])->assertOk();

        $steps = $suite->fresh()->checks[0]['steps'];
        $this->assertSame([
            ['do' => 'open', 'url' => 'https://shop.example.com'],
            ['do' => 'hover', 'target' => 'Products'],
            // The 3rd of those sharing the name, said by its words alone
            ['do' => 'click', 'target' => 'comments', 'nth' => '3'],
            ['do' => 'type', 'target' => 'Card number', 'text' => '4242', 'frame' => 'body > iframe'],
            ['do' => 'upload', 'target' => 'Photo', 'text' => 'cat.jpg', 'file' => 'abc123', 'selector' => '#photo'],
            ['do' => 'back'],
            ['do' => 'gone', 'target' => 'Loading…'],
            ['do' => 'address', 'text' => '/thank-you'],
        ], $steps);
        $this->assertSame(
            ['Open shop.example.com', 'Point at "Products"', 'Click the 3rd "comments"', 'Type "4242" into "Card number"', 'Add "cat.jpg" to "Photo"', 'Go back', 'Expect not to see "Loading…"', 'Expect the address to have "/thank-you"'],
            array_map(BrowserCheck::say(...), $steps),
        );

        $missing = fn (array $step) => BrowserCheck::unready(['title' => 'Ordering', 'steps' => [['do' => 'open', 'url' => 'https://a.example'], $step]]);
        $this->assertSame('Ordering, step 2: say what to point at', $missing(['do' => 'hover']));
        $this->assertSame('Ordering, step 2: say what should be gone from the page', $missing(['do' => 'gone']));
        $this->assertSame('Ordering, step 2: say what the address should have in it', $missing(['do' => 'address']));
        $this->assertSame('Ordering, step 2: choose the file to add from the Drive', $missing(['do' => 'upload', 'target' => 'Photo']));
        $this->assertNull($missing(['do' => 'back']));
    }

    public function test_a_step_carries_a_note_for_a_demo_video_and_a_scroll_goes_to_a_thing_or_a_screen_down()
    {
        $this->browserAnswers();
        $user = User::factory()->create();
        $suite = $this->suiteOf($user, $user);

        $this->actingAs($user)->patchJson(route('checks.suites.update', $suite), ['checks' => [['id' => 'home', 'title' => 'Home', 'steps' => [
            ['do' => 'open', 'url' => 'https://example.com', 'note' => '  Start at the home page.  '],
            ['do' => 'scroll', 'target' => 'Results'],
            ['do' => 'scroll', 'note' => ''],
            ['do' => 'shot'],
        ]]]])->assertOk();

        $steps = $suite->fresh()->checks[0]['steps'];
        // Any kind may have one; an empty one is none
        $this->assertSame([
            ['do' => 'open', 'url' => 'https://example.com', 'note' => 'Start at the home page.'],
            ['do' => 'scroll', 'target' => 'Results'],
            ['do' => 'scroll', 'target' => ''],
            ['do' => 'shot'],
        ], $steps);
        $this->assertSame(['Open example.com', 'Scroll to "Results"', 'Scroll down', 'Take a picture'], array_map(BrowserCheck::say(...), $steps));
        $this->assertNull(BrowserCheck::unready(['title' => 'Home', 'steps' => $steps]));

        $this->actingAs($user)->patchJson(route('checks.suites.update', $suite), ['checks' => [['id' => 'home', 'title' => 'Home', 'steps' => [
            ['do' => 'open', 'url' => 'https://example.com', 'note' => str_repeat('a', 601)],
        ]]]])->assertJsonValidationErrors(['checks.0.steps.0.note']);

        // Up long enough to pause the film on, the browser given that time too
        $this->assertSame(3000, BrowserCheck::noteHold('Short.'));
        $this->assertSame(1500 + 15 * 200, BrowserCheck::noteHold(str_repeat('a', 200)));
        $this->assertSame(8000, BrowserCheck::noteHold(str_repeat('a', 450)));

        // How long a demo stays on a step: to a tenth of a second, up to 30
        $this->actingAs($user)->patchJson(route('checks.suites.update', $suite), ['checks' => [['id' => 'home', 'title' => 'Home', 'steps' => [
            ['do' => 'open', 'url' => 'https://example.com', 'note' => 'Start at the home page.', 'wait' => 6],
            ['do' => 'see', 'target' => 'Welcome', 'wait' => 1.25],
            ['do' => 'shot', 'wait' => null],
        ]]]])->assertOk();
        $this->assertSame([
            ['do' => 'open', 'url' => 'https://example.com', 'note' => 'Start at the home page.', 'wait' => '6'],
            ['do' => 'see', 'target' => 'Welcome', 'wait' => '1.3'],
            ['do' => 'shot'],
        ], $suite->fresh()->checks[0]['steps']);
        $this->actingAs($user)->patchJson(route('checks.suites.update', $suite), ['checks' => [['id' => 'home', 'title' => 'Home', 'steps' => [
            ['do' => 'shot', 'wait' => 31],
        ]]]])->assertJsonValidationErrors(['checks.0.steps.0.wait']);

        $run = $suite->runs()->create(['created_by' => $user->id, 'settings' => SuiteRun::settings('steady', captions: true)]);
        (new RunBrowserChecks($run->id))->handle();

        Http::assertSent(fn (Request $request) => $request['pace'] === ['glide' => 480, 'typing' => 65, 'pause' => 1150]
            && $request['steps'][0] === ['do' => 'open', 'url' => 'https://example.com', 'note' => 'Start at the home page.', 'wait' => '6', 'say' => 'Open example.com']);
    }

    public function test_a_file_a_step_adds_goes_to_the_browser_from_the_owners_drive_and_only_there()
    {
        Storage::fake(DriveFile::DISK);
        $bytes = tempnam(sys_get_temp_dir(), 'cat');
        file_put_contents($bytes, 'a picture of a cat');
        $this->browserAnswers();
        $owner = User::factory()->create();
        $file = DriveFile::keep($bytes, 'cat.jpg', $owner);
        $stranger = User::factory()->create();
        $theirs = DriveFile::keep($bytes, 'theirs.jpg', $stranger);
        unlink($bytes);
        $suite = $this->suiteOf($owner, $owner, [
            ['do' => 'open', 'url' => 'https://shop.example.com'],
            ['do' => 'upload', 'target' => 'Photo', 'file' => $file->ref_id, 'text' => 'cat.jpg'],
            ['do' => 'upload', 'target' => 'Photo', 'file' => $theirs->ref_id, 'text' => 'theirs.jpg'],
        ]);
        $run = $suite->runs()->create(['created_by' => $owner->id, 'settings' => [
            'speed' => 'quick', 'pace' => BrowserCheck::SPEEDS['quick'],
            'captions' => false, 'record' => false, 'keep' => false, 'checks' => [],
        ]]);

        (new RunBrowserChecks($run->id))->handle();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/check')
            && $request['steps'][1]['file'] === ['name' => 'cat.jpg', 'type' => $file->mime, 'data' => base64_encode('a picture of a cat')]
            // Another's file is not theirs to add: none goes, and the step says so
            && $request['steps'][2]['file'] === null
            && $request['steps'][1]['say'] === 'Add "cat.jpg" to "Photo"');
    }

    public function test_a_projects_suites_are_its_members_and_only_its_editors_change_or_run_them()
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $stranger = User::factory()->create();
        $project = Project::start($owner, 'Customer');
        $project->members()->attach($viewer, ['role' => Project::VIEWER]);
        $suite = $this->suiteOf($project, $owner);

        $this->actingAs($viewer)->get(route('projects.checks.index', $project))
            ->assertInertia(fn ($page) => $page->where('canChange', false)->has('suites', 1));
        $this->actingAs($viewer)->getJson(route('checks.suites.show', $suite))->assertOk();
        $this->actingAs($viewer)->patchJson(route('checks.suites.update', $suite), ['title' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->postJson(route('checks.runs.store', $suite), ['speed' => 'quick', 'checks' => []])->assertForbidden();
        $this->actingAs($stranger)->getJson(route('checks.suites.show', $suite))->assertForbidden();

        $this->actingAs($owner)->get(route('checks.index'))
            ->assertInertia(fn ($page) => $page->has('suites', 0)->where('spaces.1.label', 'Customer'));
    }

    public function test_a_run_is_started_with_its_speed_and_a_film_is_always_kept()
    {
        Queue::fake();
        $user = User::factory()->create();
        $suite = $this->suiteOf($user, $user);

        $this->actingAs($user)->postJson(route('checks.runs.store', $suite), [
            'speed' => 'watch', 'captions' => true, 'record' => true, 'keep' => false, 'checks' => [],
        ])->assertCreated()->assertJsonPath('state', 'waiting');

        $run = BrowserRun::firstOrFail();
        $this->assertSame(['glide' => 350, 'typing' => 45, 'pause' => 800], $run->settings['pace']);
        $this->assertTrue($run->settings['keep']);
        Queue::assertPushed(RunBrowserChecks::class, fn ($job) => $job->runId === $run->id);

        $this->actingAs($user)->postJson(route('checks.runs.store', $suite), [
            'speed' => 'custom', 'pace' => ['glide' => 100, 'typing' => 20, 'pause' => 9000], 'checks' => ['nope'],
        ])->assertJsonValidationErrors(['pace.pause', 'checks.0']);
    }

    public function test_a_run_does_each_step_at_its_pace_and_says_what_each_did()
    {
        $this->browserAnswers();
        $user = User::factory()->create();
        $suite = $this->suiteOf($user, $user);
        $run = $suite->runs()->create(['created_by' => $user->id, 'settings' => [
            'speed' => 'slow', 'pace' => ['glide' => 600, 'typing' => 90, 'pause' => 1500],
            'captions' => true, 'record' => false, 'keep' => false, 'checks' => [],
        ]]);

        (new RunBrowserChecks($run->id))->handle();

        $run->refresh();
        $this->assertSame(BrowserRun::PASSED, $run->state);
        $this->assertSame('Open example.com', $run->results[0]['steps'][0]['say']);
        // Not kept: the picture stays in the run itself
        $this->assertSame(self::PICTURE, $run->results[0]['steps'][1]['shot']);
        $this->assertNull($run->drive_folder_id);

        Http::assertSent(fn (Request $request) => $request['pace'] === ['glide' => 600, 'typing' => 90, 'pause' => 1500]
            && $request['captions'] === true
            && $request['steps'][0] === ['do' => 'open', 'url' => 'https://example.com', 'say' => 'Open example.com']);
    }

    public function test_a_filmed_run_keeps_its_film_and_pictures_in_the_owners_drive()
    {
        Storage::fake(DriveFile::DISK);
        $this->browserAnswers(self::FILM);
        $owner = User::factory()->create();
        $project = Project::start($owner, 'Customer');
        $suite = $this->suiteOf($project, $owner);
        $run = $suite->runs()->create(['created_by' => $owner->id, 'settings' => [
            'speed' => 'watch', 'pace' => ['glide' => 350, 'typing' => 45, 'pause' => 800],
            'captions' => true, 'record' => true, 'keep' => true, 'checks' => [],
        ]]);

        (new RunBrowserChecks($run->id))->handle();

        $run->refresh();
        $files = $project->driveFiles()->get();
        $this->assertEqualsCanonicalizing(['Home - step 2.jpg', 'Home.webm'], $files->pluck('name')->all());
        $this->assertSame('video', $files->firstWhere('name', 'Home.webm')->kind);
        $this->assertSame('Home.webm', $run->results[0]['video']['name']);
        $this->assertSame($run->drive_folder_id, $files->first()->folder_id);
        $this->assertSame('Shop', $run->driveFolder->parent?->name);

        $this->actingAs($owner)->getJson(route('checks.runs.show', $run))
            ->assertJsonPath('results.0.video.name', 'Home.webm')
            ->assertJsonPath('folder', route('projects.drive.index', ['project' => $project, 'folder' => $run->driveFolder->ref_id]));
    }

    public function test_every_check_is_filmed_one_after_another_as_one_film()
    {
        Storage::fake(DriveFile::DISK);
        Http::fake(['chrome:3000/check' => Http::response([
            // The second check stopped at its second step; the third never began
            'steps' => [['ok' => true, 'took' => 300], ['ok' => true, 'took' => 40], ['ok' => false, 'took' => 10000, 'error' => 'Nothing showing "Thanks" turned up.']],
            'chapters' => [[['ok' => true, 'took' => 300]], [['ok' => true, 'took' => 40], ['ok' => false, 'took' => 10000, 'error' => 'Nothing showing "Thanks" turned up.']], []],
            'url' => 'https://example.com/',
            'title' => 'Example',
            'refused' => [],
            'video' => self::FILM,
        ])]);
        $user = User::factory()->create();
        $suite = (new BrowserSuite(['title' => 'Shop', 'checks' => [
            ['id' => 'a', 'title' => 'Signing in', 'steps' => [['do' => 'open', 'url' => 'https://example.com', 'note' => 'Start here.']]],
            ['id' => 'b', 'title' => 'Buying', 'steps' => [['do' => 'click', 'target' => 'Buy'], ['do' => 'see', 'target' => 'Thanks']]],
            ['id' => 'c', 'title' => 'Signing out', 'steps' => [['do' => 'click', 'target' => 'Sign out']]],
        ]]))->ownedBy($user, $user);
        $suite->save();

        $this->actingAs($user)->postJson(route('checks.runs.store', $suite), [
            'speed' => 'steady', 'captions' => true, 'record' => true, 'keep' => true, 'one_film' => true, 'checks' => [],
        ])->assertSuccessful();
        $this->work();

        // One browser, asked once, with each check's name and steps
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['record'] === true
            && array_column($request['chapters'], 'title') === ['Signing in', 'Buying', 'Signing out']
            && $request['chapters'][0]['steps'][0]['note'] === 'Start here.');

        $run = BrowserRun::sole();
        $this->assertTrue($run->settings['one_film']);
        $this->assertSame([true, false, false], array_column($run->results, 'ok'));
        $this->assertSame([1, 2, 0], array_map(fn (array $check) => count($check['steps']), $run->results));
        $this->assertSame('Buying', $run->results[1]['title']);
        $this->assertSame(10040, $run->results[1]['took']);

        // The film kept once, and every check points at it
        $films = $user->driveFiles()->where('name', 'like', '%.webm')->get();
        $this->assertSame(['Shop.webm'], $films->pluck('name')->all());
        $this->assertSame(['Shop.webm', 'Shop.webm', 'Shop.webm'], array_column(array_column($run->results, 'video'), 'name'));
    }

    public function test_a_browser_that_cannot_be_reached_fails_the_check_plainly()
    {
        Http::fake(['chrome:3000/check' => Http::response(['error' => 'Two checks are running already'], 429)]);
        $user = User::factory()->create();
        $suite = $this->suiteOf($user, $user);
        $run = $suite->runs()->create(['created_by' => $user->id, 'settings' => [
            'speed' => 'quick', 'pace' => ['glide' => 0, 'typing' => 0, 'pause' => 0],
            'captions' => false, 'record' => false, 'keep' => false, 'checks' => [],
        ]]);

        (new RunBrowserChecks($run->id))->handle();

        $run->refresh();
        $this->assertSame(BrowserRun::FAILED, $run->state);
        $this->assertSame('Two checks are running already', $run->results[0]['problem']);
    }
}
