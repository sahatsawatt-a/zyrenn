<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowNodeRun;
use App\Models\Workflow\WorkflowRun;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Workflow\Engine;
use App\Support\Workflow\NodeStatus;
use App\Support\Workflow\References;
use App\Support\Workflow\RunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GitHub in workflows: each of its calls a step that asks as GitHub reads
 * it, and a trigger that starts on what happens in a repository, signed
 * with the connection's secret. Through the real queue; GitHub is faked.
 */
class WorkflowGithubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database', 'services.workflows.public_url' => 'https://hooks.example.test']);
        Http::preventStrayRequests();
    }

    private function node(string $key, string $type, array $config = []): array
    {
        return ['id' => $key, 'key' => $key, 'type' => $type, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
    }

    private function edge(string $from, string $to): array
    {
        return ['id' => "{$from}.out.{$to}", 'source' => $from, 'sourceHandle' => 'out', 'target' => $to];
    }

    /**
     * Start → each step in turn, run by hand and worked off.
     */
    private function runChain(User $user, array $steps, array $payload = ['n' => 1]): WorkflowRun
    {
        $nodes = [$this->node('start', 'trigger.manual', ['payload' => json_encode($payload)]), ...$steps];
        $edges = [];

        foreach (array_slice($nodes, 1) as $at => $node) {
            $edges[] = $this->edge($nodes[$at]['id'], $node['id']);
        }

        $workflow = Workflow::factory()->for($user)->steps($nodes, $edges)->create();
        $this->assertSame([], References::allProblems($workflow));
        $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $user), $user);
        $this->work();

        return $run->fresh();
    }

    private function step(WorkflowRun $run, string $key): WorkflowNodeRun
    {
        return $run->steps()->where('node_key', $key)->sole();
    }

    /**
     * What GitHub answers, by method and path.
     */
    private function fakeGithub(): void
    {
        $issue = fn (int $number, array $more = []) => ['number' => $number, 'id' => 900 + $number, 'title' => "Issue {$number}", 'body' => 'Words', 'state' => 'open', 'labels' => [['name' => 'bug']], 'assignees' => [['login' => 'ada']], 'user' => ['login' => 'bo'], 'comments' => 1, 'html_url' => "https://github.com/zyrenn/app/issues/{$number}", 'updated_at' => '2026-10-17T01:00:00Z', ...$more];

        Http::fake(function (Request $request) use ($issue) {
            $path = str_replace('https://api.github.com', '', (string) strtok($request->url(), '?'));

            return Http::response(match ($request->method().' '.$path) {
                'POST /repos/zyrenn/app/issues' => $issue(12, ['title' => 'Broken export']),
                'PATCH /repos/zyrenn/app/issues/12' => $issue(12, ['state' => 'closed', 'state_reason' => 'completed']),
                'POST /repos/zyrenn/app/issues/12/comments' => ['id' => 77, 'body' => 'Fixed in 1.2', 'user' => ['login' => 'zyrenn-bot'], 'html_url' => 'https://github.com/zyrenn/app/issues/12#issuecomment-77'],
                'GET /repos/zyrenn/app/issues' => [$issue(12), $issue(13, ['pull_request' => ['url' => 'x']])],
                'GET /repos/zyrenn/app/contents/docs/CODE-MAP.md' => ['path' => 'docs/CODE-MAP.md', 'sha' => 'abc', 'size' => 11, 'encoding' => 'base64', 'content' => base64_encode('# Code map'), 'html_url' => 'https://github.com/zyrenn/app/blob/main/docs/CODE-MAP.md'],
                'PUT /repos/zyrenn/app/contents/docs/CODE-MAP.md' => ['content' => ['path' => 'docs/CODE-MAP.md', 'sha' => 'def'], 'commit' => ['sha' => 'c0ffee', 'html_url' => 'https://github.com/zyrenn/app/commit/c0ffee']],
                'GET /repos/other/site' => ['default_branch' => 'trunk'],
                'POST /repos/other/site/actions/workflows/deploy.yml/dispatches' => '',
                'GET /repos/zyrenn/app/actions/runs' => ['total_count' => 1, 'workflow_runs' => [['id' => 5, 'name' => 'CI', 'status' => 'completed', 'conclusion' => 'failure', 'head_branch' => 'main', 'html_url' => 'https://github.com/zyrenn/app/actions/runs/5']]],
                'POST /repos/zyrenn/app/topics' => ['names' => ['zyrenn']],
                'GET /user' => ['login' => 'zyrenn-bot', 'name' => 'Zyrenn', 'html_url' => 'https://github.com/zyrenn-bot'],
                default => ['message' => 'Not Found'],
            }, str_starts_with($request->method().' '.$path, 'POST /repos/other/site/actions') ? 204 : 200);
        });
    }

    public function test_each_github_call_is_a_step_that_asks_as_github_reads_it_and_gives_out_what_came_back()
    {
        $this->fakeGithub();
        $user = User::factory()->create();
        $on = fn (array $settings = []) => ['connection' => Connection::factory()->github()->for($user)->create()->ref_id, ...$settings];

        $run = $this->runChain($user, [
            $this->node('open', 'github.issue_create', $on(['title' => '{{ trigger.what }}', 'body' => 'From a workflow', 'labels' => "bug, from-zyrenn\nexport", 'assignees' => 'ada'])),
            $this->node('close', 'github.issue_update', $on(['number' => '#{{ node("open").number }}', 'state' => 'closed', 'reason' => 'completed'])),
            $this->node('say', 'github.comment', $on(['number' => '12', 'body' => 'Fixed in 1.2'])),
            $this->node('find', 'github.issues', $on(['state' => 'all', 'labels' => 'bug', 'since' => '2026-10-01', 'count' => 500])),
            $this->node('read', 'github.file', $on(['path' => '/docs/CODE-MAP.md'])),
            $this->node('write', 'github.file_save', $on(['path' => 'docs/CODE-MAP.md', 'content' => '# Code map, again', 'message' => 'Map'])),
            $this->node('deploy', 'github.dispatch', $on(['repo' => 'https://github.com/other/site.git', 'workflow' => 'deploy.yml', 'inputs' => [['name' => 'env', 'value' => 'prod']]])),
            $this->node('runs', 'github.runs', $on(['status' => 'failure'])),
            $this->node('any', 'github.request', $on(['method' => 'POST', 'path' => 'repos/zyrenn/app/topics', 'body' => '{"names": ["zyrenn"]}'])),
            $this->node('me', 'github.me', $on()),
        ], ['what' => 'Broken export']);

        $failed = $run->steps()->where('status', '!=', NodeStatus::Succeeded->value)->first();
        $this->assertNull($failed, $failed ? "{$failed->node_key}: ".json_encode($failed->error) : '');
        $this->assertSame(RunStatus::Succeeded, $run->status);

        $asked = Http::recorded()->map(fn (array $pair) => $pair[0]->method().' '.str_replace('https://api.github.com', '', $pair[0]->url()))->all();
        $this->assertSame([
            'POST /repos/zyrenn/app/issues',
            'PATCH /repos/zyrenn/app/issues/12',
            'POST /repos/zyrenn/app/issues/12/comments',
            'GET /repos/zyrenn/app/issues?state=all&labels=bug&since=2026-10-01T00%3A00%3A00Z&sort=updated&per_page=100',
            'GET /repos/zyrenn/app/contents/docs/CODE-MAP.md',
            // Changing a file asks what it is first, to name the one it changes
            'GET /repos/zyrenn/app/contents/docs/CODE-MAP.md',
            'PUT /repos/zyrenn/app/contents/docs/CODE-MAP.md',
            // A repository's address is taken as its name; with no branch named, its usual one
            'GET /repos/other/site',
            'POST /repos/other/site/actions/workflows/deploy.yml/dispatches',
            'GET /repos/zyrenn/app/actions/runs?status=failure&per_page=10',
            'POST /repos/zyrenn/app/topics',
            'GET /user',
        ], $asked);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer ghp-token') && $request->hasHeader('X-GitHub-Api-Version', '2022-11-28'));

        $sent = Http::recorded()->map(fn (array $pair) => $pair[0]->data())->all();
        $this->assertSame(['title' => 'Broken export', 'body' => 'From a workflow', 'labels' => ['bug', 'from-zyrenn', 'export'], 'assignees' => ['ada']], $sent[0]);
        $this->assertSame(['state' => 'closed', 'state_reason' => 'completed'], $sent[1]);
        $this->assertSame(['message' => 'Map', 'content' => base64_encode('# Code map, again'), 'sha' => 'abc'], $sent[6]);
        $this->assertSame(['ref' => 'trunk', 'inputs' => ['env' => 'prod']], $sent[8]);
        $this->assertSame(['names' => ['zyrenn']], $sent[10]);

        $this->assertSame([12, 'Broken export', ['bug'], ['ada'], 'https://github.com/zyrenn/app/issues/12'], array_values(array_intersect_key($this->step($run, 'open')->output, array_flip(['number', 'title', 'labels', 'assignees', 'url']))));
        $this->assertSame(['closed', 'completed'], [$this->step($run, 'close')->output['state'], $this->step($run, 'close')->output['reason']]);
        $this->assertSame(77, $this->step($run, 'say')->output['id']);
        // A pull request is an issue to GitHub, but not to "find issues"
        $this->assertSame([1, [12]], [$this->step($run, 'find')->output['count'], array_column($this->step($run, 'find')->output['issues'], 'number')]);
        $this->assertSame(['# Code map', false, false, 'abc'], [$this->step($run, 'read')->output['text'], $this->step($run, 'read')->output['cut'], $this->step($run, 'read')->output['binary'], $this->step($run, 'read')->output['sha']]);
        $this->assertSame(['c0ffee', false], [$this->step($run, 'write')->output['commit'], $this->step($run, 'write')->output['made']]);
        $this->assertSame(['started' => true, 'workflow' => 'deploy.yml', 'ref' => 'trunk'], $this->step($run, 'deploy')->output);
        $this->assertSame(['failure', 'main'], [$this->step($run, 'runs')->output['runs'][0]['conclusion'], $this->step($run, 'runs')->output['runs'][0]['branch']]);
        $this->assertSame(['names' => ['zyrenn']], $this->step($run, 'any')->output['answer']);
        $this->assertSame('zyrenn-bot', $this->step($run, 'me')->output['login']);
        $this->assertStringNotContainsString('ghp-token', (string) json_encode($run->steps()->get()->toArray()));
    }

    public function test_github_says_why_when_it_will_not_and_a_step_says_what_it_lacks()
    {
        Http::fake([
            'api.github.com/repos/zyrenn/app/issues/404' => Http::response(['message' => 'Not Found'], 404),
            'api.github.com/repos/zyrenn/app/issues/403' => Http::response(['message' => 'API rate limit exceeded'], 403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (time() + 120)]),
            'api.github.com/repos/zyrenn/app/issues' => Http::response(['message' => 'Validation Failed', 'errors' => [['resource' => 'Issue', 'field' => 'title', 'code' => 'missing_field']]], 422),
            'api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401),
        ]);
        $user = User::factory()->create();
        $github = Connection::factory()->github()->for($user)->create()->ref_id;
        $error = function (string $type, array $settings) use ($user, $github) {
            $step = $this->step($this->runChain($user, [$this->node('one', $type, ['connection' => $github, ...$settings])]), 'one');

            return [(string) ($step->error['message'] ?? ''), $step->attempts ?? null];
        };

        $this->assertStringContainsString('found nothing there, or the token cannot see it', $error('github.issue', ['number' => '404'])[0]);
        $this->assertStringContainsString('asked to slow down', $error('github.issue', ['number' => '403'])[0]);
        $this->assertStringContainsString('title missing_field', $error('github.issue_create', ['title' => 'x'])[0]);
        $this->assertStringContainsString('refused the token', $error('github.me', [])[0]);
        $this->assertStringContainsString('is not a repository', $error('github.issue', ['repo' => 'just-a-name', 'number' => '1'])[0]);
        $this->assertStringContainsString('There is no number', $error('github.issue', ['number' => 'twelve'])[0]);
        $this->assertStringContainsString('is not a path of GitHub', $error('github.request', ['path' => '/repos/../../admin'])[0]);
    }

    public function test_something_happening_on_github_starts_a_run_only_when_signed_and_of_the_kind_it_waits_for()
    {
        $user = User::factory()->create();
        $github = Connection::factory()->github()->for($user)->create();
        $nodes = [
            $this->node('heard', 'trigger.github', ['connection' => $github->ref_id, 'on' => 'issues', 'actions' => 'opened, reopened', 'repo' => '']),
            $this->node('said', 'data.set', ['fields' => [['name' => 'line', 'value' => '{{ trigger.text }}'], ['name' => 'number', 'value' => '{{ trigger.issue.number }}']]]),
        ];
        $workflow = Workflow::factory()->for($user)->steps($nodes, [$this->edge('heard', 'said')])->create();
        $this->actingAs($user)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertOk();
        $this->actingAs($user)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertOk();

        $send = function (string $event, array $payload, string $delivery, string $secret = 'gh-secret') use ($github) {
            $body = json_encode($payload);

            return $this->call('POST', route('hooks.github', $github->ref_id), [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GITHUB_EVENT' => $event,
                'HTTP_X_GITHUB_DELIVERY' => $delivery,
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
            ], $body);
        };
        $issue = fn (string $action) => [
            'action' => $action,
            'issue' => ['number' => 12, 'title' => 'Broken export', 'body' => 'It fails', 'state' => 'open', 'labels' => [['name' => 'bug']], 'user' => ['login' => 'bo'], 'html_url' => 'https://github.com/zyrenn/app/issues/12'],
            'repository' => ['full_name' => 'zyrenn/app', 'html_url' => 'https://github.com/zyrenn/app'],
            'sender' => ['login' => 'bo', 'html_url' => 'https://github.com/bo'],
        ];

        // Not signed with its secret: turned away
        $send('issues', $issue('opened'), 'd-0', 'wrong')->assertForbidden();
        // GitHub's hello when the webhook is made is only answered
        $send('ping', ['zen' => 'Keep it simple.', 'hook_id' => 1], 'd-ping')->assertOk();
        // An action it does not wait for, or another event, starts nothing
        $send('issues', $issue('closed'), 'd-1')->assertOk();
        $send('push', ['ref' => 'refs/heads/main', 'commits' => [], 'repository' => ['full_name' => 'zyrenn/app'], 'sender' => ['login' => 'bo']], 'd-2')->assertOk();
        $this->assertSame(0, WorkflowRun::count());

        $send('issues', $issue('opened'), 'd-3')->assertOk();
        // The same delivery sent again starts nothing more
        $send('issues', $issue('opened'), 'd-3')->assertOk();
        $this->work();

        $run = WorkflowRun::sole();
        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame(['line' => 'bo opened issue #12 Broken export in zyrenn/app', 'number' => 12], $this->step($run, 'said')->output);
    }
}
