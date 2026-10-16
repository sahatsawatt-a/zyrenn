<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workflow\CodeLibrary;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowRun;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Workflow\Choices;
use App\Support\Workflow\Code\CodeSettings;
use App\Support\Workflow\Engine;
use App\Support\Workflow\References;
use App\Support\Workflow\RunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What "Run code" may use, as the app's admins say: libraries they add
 * (installed in the background, handed to a run only when its step chose
 * them, and only while switched on), whether Python may be written, and the
 * most seconds a step may run. The runners are faked.
 */
class WorkflowCodeLibrariesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        Http::preventStrayRequests();
        $this->withoutVite();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function lodash(bool $enabled = true): CodeLibrary
    {
        return CodeLibrary::create(['language' => 'javascript', 'name' => 'lodash', 'version' => '4.17.21', 'status' => CodeLibrary::READY, 'size' => 20, 'source' => 'module.exports = { sum: function () {} };', 'enabled' => $enabled]);
    }

    private function workflow(User $user, array $config): Workflow
    {
        return Workflow::factory()->for($user)->steps([
            ['id' => 'start', 'key' => 'start', 'type' => 'trigger.manual', 'position' => ['x' => 0, 'y' => 0], 'config' => ['payload' => '{}']],
            ['id' => 'work', 'key' => 'work', 'type' => 'data.code', 'position' => ['x' => 0, 'y' => 0], 'config' => ['code' => 'return 1', ...$config]],
        ], [['id' => 'e', 'source' => 'start', 'sourceHandle' => 'out', 'target' => 'work']])->create();
    }

    private function started(User $user, Workflow $workflow): WorkflowRun
    {
        $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $user), $user);
        $this->work();

        return $run->fresh();
    }

    public function test_only_an_admin_sees_and_sets_what_code_may_use()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.code.page'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.code.libraries.store'), ['language' => 'javascript', 'name' => 'lodash'])->assertForbidden();
        $this->actingAs($user)->patch(route('admin.code.settings'), ['python' => true, 'seconds' => ['javascript' => 60, 'python' => 60], 'python_memory' => 512])->assertForbidden();
        $this->assertFalse(CodeSettings::pythonOn());

        $this->actingAs($this->admin())->get(route('admin.code.page'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/Code')->where('settings.python', false)->where('limits.ceiling', CodeSettings::CEILING_SECONDS));
    }

    public function test_an_admin_adds_a_library_which_is_installed_in_the_background_and_says_why_when_it_is_not()
    {
        Http::fake(['code:3000/install' => fn (Request $request) => Http::response($request['name'] === 'lodash'
            ? ['ok' => true, 'version' => '4.17.21', 'size' => 72555, 'source' => 'module.exports = {};']
            : ['ok' => false, 'error' => 'It needs Node’s own fs, which code here can’t have: only libraries that just compute can be used.'])]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.code.libraries.store'), ['language' => 'javascript', 'name' => 'lodash', 'version' => '^4'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.code.libraries.store'), ['language' => 'javascript', 'name' => 'fs-extra'])->assertSessionHasNoErrors();
        // A name npm would never have is turned away at once
        $this->actingAs($admin)->post(route('admin.code.libraries.store'), ['language' => 'javascript', 'name' => '../../etc'])->assertSessionHasErrors('name');
        $this->assertSame([CodeLibrary::INSTALLING, CodeLibrary::INSTALLING], CodeLibrary::orderBy('name')->pluck('status')->all());

        $this->work();

        $lodash = CodeLibrary::where('name', 'lodash')->sole();
        $this->assertSame([CodeLibrary::READY, '^4', '4.17.21', 72555, 'module.exports = {};', $admin->id], [$lodash->status, $lodash->asked, $lodash->version, $lodash->size, $lodash->source, $lodash->added_by]);
        $this->assertStringContainsString('needs Node’s own fs', (string) CodeLibrary::where('name', 'fs-extra')->sole()->error);
        Http::assertSent(fn (Request $request) => $request['name'] === 'lodash' && $request['version'] === '^4');

        // Only what is ready and on is offered to a step; the bundle never reaches a page
        $this->assertSame(['javascript' => [['name' => 'lodash', 'version' => '4.17.21']], 'python' => []], Choices::for($admin)['libraries']);
        $this->actingAs($admin)->get(route('admin.code.page'))->assertDontSee('module.exports');
    }

    public function test_a_step_is_handed_only_the_libraries_it_chose_and_only_while_they_are_on()
    {
        Http::fake(['code:3000/run' => Http::response(['ok' => true, 'value' => ['n' => 6], 'logs' => []])]);
        $user = User::factory()->create();
        $library = $this->lodash();

        $with = $this->workflow($user, ['libraries' => ['lodash', 'lodash', '']]);
        $this->assertSame([], References::allProblems($with));
        $this->assertSame(RunStatus::Succeeded, $this->started($user, $with)->status);
        Http::assertSent(fn (Request $request) => $request['libraries'] === [['name' => 'lodash', 'source' => 'module.exports = { sum: function () {} };']]);

        $without = $this->workflow($user, []);
        $this->started($user, $without);
        Http::assertSent(fn (Request $request) => $request['libraries'] === []);

        // Switched off by an admin, a step that chose it stands in the way and stops
        $library->update(['enabled' => false]);
        $this->assertSame(['Libraries: lodash is not there for JavaScript: the app\'s admins have not added it, or switched it off.'], array_column(References::allProblems($with), 'message'));
        $stopped = $this->started($user, $with);
        $this->assertSame(RunStatus::Failed, $stopped->status);
        $this->assertStringContainsString('lodash, which the app\'s admins have taken away or switched off', (string) $stopped->error);
    }

    public function test_code_runs_no_longer_than_the_admins_allow_and_python_only_where_they_allow_it()
    {
        Http::fake(['code:3000/run' => Http::response(['ok' => true, 'value' => 1, 'logs' => []])]);
        $user = User::factory()->create();

        $this->actingAs($this->admin())->patch(route('admin.code.settings'), ['python' => false, 'seconds' => ['javascript' => 10, 'python' => 20], 'python_memory' => 256])->assertSessionHasNoErrors();
        $this->started($user, $this->workflow($user, ['seconds' => 90]));
        Http::assertSent(fn (Request $request) => $request['seconds'] === 10);

        // Not past the ceiling, even for an admin
        $this->actingAs($this->admin())->patch(route('admin.code.settings'), ['python' => false, 'seconds' => ['javascript' => 600, 'python' => 20], 'python_memory' => 256])->assertSessionHasErrors('seconds.javascript');

        $python = $this->workflow($user, ['language' => 'python', 'code' => 'return 1']);
        $this->assertSame(['Language: Python is switched off by the app\'s admins.'], array_column(References::allProblems($python), 'message'));
        $this->assertSame('Run code: Python is switched off by the app\'s admins.', $this->started($user, $python)->error);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'python'));
    }

    public function test_python_where_allowed_is_run_by_the_python_runner_with_its_libraries_and_memory()
    {
        $down = false;
        Http::fake(['python:3000/run' => function () use (&$down) {
            return $down ? Http::response('', 503) : Http::response(['ok' => true, 'value' => ['mean' => 2.5], 'logs' => ['numbers read'], 'error' => null, 'line' => null]);
        }]);
        $user = User::factory()->create();
        CodeLibrary::create(['language' => 'python', 'name' => 'numpy', 'version' => '2.2.5', 'status' => CodeLibrary::READY, 'enabled' => true]);
        CodeSettings::set(['python' => true, 'seconds' => ['javascript' => 30, 'python' => 20], 'python_memory' => 384]);

        $python = $this->workflow($user, ['language' => 'python', 'code' => "import numpy as np\nresult = {'mean': float(np.mean([1, 2, 3, 4]))}", 'libraries' => ['numpy'], 'seconds' => 45]);
        $this->assertSame([], References::allProblems($python));
        $run = $this->started($user, $python);

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertSame(['mean' => 2.5], $run->steps()->where('node_key', 'work')->sole()->output);
        $this->assertSame(['numbers read'], $run->logs()->pluck('message')->all());
        // Its own libraries, no longer than the admins allow Python, with the memory they give it
        Http::assertSent(fn (Request $request) => $request['libraries'] === ['numpy'] && $request['seconds'] === 20 && $request['memory'] === 384);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'code:3000'));

        // A runner down is tried again later, not the code's failing
        $down = true;
        $later = $this->started($user, $python);
        $this->assertSame('retry_wait', $later->steps()->where('node_key', 'work')->sole()->status->value);
    }
}
