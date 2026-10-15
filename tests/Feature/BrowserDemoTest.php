<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Browser\BrowserTests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The app's own test runs, on an admin page of their own.
 */
class BrowserDemoTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/browser-tests-'.uniqid();
        BrowserTests::$root = $this->root;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        BrowserTests::$root = null;

        parent::tearDown();
    }

    /** Someone who runs the app: its own test runs are theirs to ask for. */
    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    /** A runner waiting for runs, as run.mjs --listen says it is. */
    private function listening(int $beatMsAgo = 500): void
    {
        File::ensureDirectoryExists($this->root);
        File::put("{$this->root}/listener.json", json_encode([
            'pid' => 1,
            'since' => now()->getTimestampMs() - 60_000,
            'beat' => now()->getTimestampMs() - $beatMsAgo,
            'busy' => null,
            'suites' => ['board', 'notes', 'workflow-load'],
            'alone' => ['workflow-load'],
            'timings' => ['board' => 12.5],
        ]));
    }

    public function test_the_apps_own_test_runs_are_only_for_its_admins()
    {
        $user = User::factory()->create();
        $id = '20261014T101500ab12';

        $this->actingAs($user)->get(route('checks.index'))
            ->assertInertia(fn ($page) => $page->component('checks/Index')->missing('tests'));
        $this->actingAs($user)->get(route('admin.test-runs.page'))->assertForbidden();
        $this->actingAs($user)->getJson(route('admin.test-runs.index'))->assertForbidden();
        $this->actingAs($user)->postJson(route('admin.test-runs.store'), ['suites' => [], 'lanes' => 1])->assertForbidden();
        $this->actingAs($user)->getJson(route('admin.test-runs.show', $id))->assertForbidden();
        $this->actingAs($user)->postJson(route('admin.test-runs.stop', $id))->assertForbidden();
        $this->actingAs($user)->get(route('admin.test-runs.shot', [$id, 'board', 'a.png']))->assertForbidden();

        $this->actingAs($this->admin())->get(route('admin.test-runs.page'))
            ->assertInertia(fn ($page) => $page->component('admin/TestRuns')->where('initial.listener', null)->where('initial.runs', []));
    }

    public function test_someone_is_made_an_admin_only_from_the_command_line()
    {
        $user = User::factory()->create(['email' => 'runs@example.com']);

        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'X', 'email' => 'runs@example.com', 'is_admin' => true]);
        $this->assertFalse($user->fresh()->is_admin);

        $this->artisan('users:admin', ['email' => 'runs@example.com'])->assertSuccessful();
        $this->assertTrue($user->fresh()->is_admin);

        $this->artisan('users:admin', ['email' => 'runs@example.com', '--off' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->is_admin);
        $this->artisan('users:admin', ['email' => 'nobody@example.com'])->assertFailed();
    }

    public function test_a_run_is_asked_of_a_runner_that_is_waiting()
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->postJson(route('admin.test-runs.store'), ['suites' => [], 'lanes' => 3])
            ->assertStatus(409);

        $this->listening();

        $this->actingAs($user)
            ->postJson(route('admin.test-runs.store'), ['suites' => ['nope'], 'lanes' => 3])
            ->assertJsonValidationErrors('suites.0');

        $id = $this->actingAs($user)
            ->postJson(route('admin.test-runs.store'), ['suites' => ['board'], 'lanes' => 2, 'watch' => true])
            ->assertOk()
            ->json('id');

        $this->assertSame(
            ['suites' => ['board'], 'lanes' => 2, 'watch' => true],
            json_decode(File::get("{$this->root}/requests/{$id}.json"), true),
        );

        $this->actingAs($user)->postJson(route('admin.test-runs.stop', $id))->assertOk();
        $this->assertFileExists("{$this->root}/requests/{$id}.stop");
    }

    public function test_a_runner_that_has_gone_quiet_is_not_waiting()
    {
        $this->listening(beatMsAgo: 20_000);

        $this->actingAs($this->admin())
            ->getJson(route('admin.test-runs.index'))
            ->assertOk()
            ->assertJsonPath('listener', null);
    }

    public function test_runs_are_read_as_the_runner_wrote_them_with_their_pictures()
    {
        $user = $this->admin();
        $id = '20261014T101500ab12';
        File::ensureDirectoryExists("{$this->root}/runs/{$id}/board");
        File::put("{$this->root}/runs/{$id}/board/board-drawn.png", 'png');
        File::put("{$this->root}/runs/{$id}.json", json_encode([
            'id' => $id,
            'from' => 'app',
            'began' => 1,
            'ended' => 2,
            'stopped' => false,
            'suites' => [
                ['name' => 'board', 'state' => 'passed', 'lines' => ['PASS  drawn']],
                ['name' => 'notes', 'state' => 'failed', 'lines' => []],
            ],
        ]));

        $this->actingAs($user)->getJson(route('admin.test-runs.index'))
            ->assertJsonPath('runs.0.id', $id)
            ->assertJsonPath('runs.0.passed', 1)
            ->assertJsonPath('runs.0.failed', 1);

        $this->actingAs($user)->getJson(route('admin.test-runs.show', $id))
            ->assertJsonPath('suites.0.shots', ['board-drawn.png']);

        $this->actingAs($user)
            ->get(route('admin.test-runs.shot', [$id, 'board', 'board-drawn.png']))
            ->assertOk();
        $this->actingAs($user)
            ->get(route('admin.test-runs.shot', [$id, 'board', '..%2F..%2F.env']))
            ->assertNotFound();
        $this->actingAs($user)->getJson(route('admin.test-runs.show', '..'))->assertNotFound();
    }
}
