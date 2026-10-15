<?php

namespace Tests\Feature;

use App\Models\Chat\ChatRoom;
use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use App\Support\Connections\Meta;
use App\Support\Workflow\Steps\WhatsAppApi;
use Database\Factories\ConnectionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('connections.index'))->assertRedirect(route('login'));
    }

    public function test_the_old_address_leads_to_the_new_page()
    {
        $this->actingAs(User::factory()->create())
            ->get('/settings/ai-connections')
            ->assertRedirect('/settings/connections');
    }

    public function test_the_page_lists_only_the_users_own_and_never_their_secrets()
    {
        $user = User::factory()->create();
        Connection::factory()->openrouter()->for($user)->create(['name' => 'Mine']);
        Connection::factory()->create(['name' => 'Someone else']);
        Connection::factory()->forProject(Project::factory()->withMember($user)->create())->create(['name' => 'A project\'s']);

        $this->actingAs($user)
            ->get(route('connections.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Connections')
                ->has('connections', 1)
                ->where('connections.0.name', 'Mine')
                ->where('connections.0.saved_secrets', ['api_key'])
                ->where('connections.0.added_by', $user->name)
                ->missing('connections.0.secrets')
                ->where('can.manage', true)
                ->has('services.telegram')
                ->has('services.openrouter'));
    }

    public function test_a_machine_of_the_users_own_needs_no_key_and_a_blank_host_means_the_usual_one()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('connections.store'), ['name' => 'Desk', 'service' => 'ollama', 'settings' => ['base_url' => '', 'default_model' => 'qwen2.5:7b']])
            ->assertRedirect(route('connections.index'));

        $connection = $user->connections()->sole();
        $this->assertSame('http://localhost:11434', $connection->setting('base_url'));
        $this->assertSame('qwen2.5:7b', $connection->setting('default_model'));
        $this->assertNull($connection->secret('api_key'));
        $this->assertSame($user->id, $connection->created_by);
    }

    public function test_a_hosted_service_needs_a_key_and_the_key_is_kept_encrypted()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('connections.store'), ['name' => 'OR', 'service' => 'openrouter'])
            ->assertSessionHasErrors('secrets.api_key');

        $this->actingAs($user)
            ->post(route('connections.store'), ['name' => 'OR', 'service' => 'openrouter', 'secrets' => ['api_key' => 'sk-or-secret']])
            ->assertSessionHasNoErrors();

        $connection = $user->connections()->sole();
        $this->assertSame('https://openrouter.ai/api/v1', $connection->setting('base_url'));
        $this->assertSame('sk-or-secret', $connection->secret('api_key'));
        $this->assertStringNotContainsString('sk-or-secret', DB::table('connections')->value('secrets'));
    }

    public function test_only_a_web_address_is_taken_as_a_host_and_only_a_known_service()
    {
        $user = User::factory()->create();

        foreach (['ftp://x.test', 'file:///etc/passwd', 'javascript:alert(1)', 'not a url'] as $bad) {
            $this->actingAs($user)
                ->post(route('connections.store'), ['name' => 'X', 'service' => 'ollama', 'settings' => ['base_url' => $bad]])
                ->assertSessionHasErrors('settings.base_url');
        }

        $this->actingAs($user)
            ->post(route('connections.store'), ['name' => 'X', 'service' => 'skynet'])
            ->assertSessionHasErrors('service');

        $this->assertSame(0, $user->connections()->count());
    }

    public function test_a_blank_secret_on_save_keeps_the_one_there()
    {
        $user = User::factory()->create();
        $connection = Connection::factory()->openrouter()->for($user)->create();

        $this->actingAs($user)
            ->patch(route('connections.update', $connection), ['name' => 'Renamed', 'service' => 'openrouter', 'secrets' => ['api_key' => '']])
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $connection->fresh()->name);
        $this->assertSame('sk-or-secret', $connection->fresh()->secret('api_key'));

        $this->actingAs($user)
            ->patch(route('connections.update', $connection), ['name' => 'Renamed', 'service' => 'openrouter', 'secrets' => ['api_key' => 'sk-or-new']]);

        $this->assertSame('sk-or-new', $connection->fresh()->secret('api_key'));
    }

    public function test_an_ai_connection_can_become_another_ai_service_but_nothing_else()
    {
        $user = User::factory()->create();
        $connection = Connection::factory()->openrouter()->for($user)->create();

        $this->actingAs($user)
            ->patch(route('connections.update', $connection), ['name' => 'Bot', 'service' => 'telegram', 'secrets' => ['bot_token' => '1:abc']])
            ->assertSessionHasErrors('service');

        $this->actingAs($user)
            ->patch(route('connections.update', $connection), ['name' => 'GPT', 'service' => 'openai'])
            ->assertSessionHasNoErrors();

        $this->assertSame('openai', $connection->fresh()->service);
        $this->assertSame('sk-or-secret', $connection->fresh()->secret('api_key'));
    }

    public function test_another_users_connection_cannot_be_touched()
    {
        $user = User::factory()->create();
        $theirs = Connection::factory()->openrouter()->create();

        $this->actingAs($user)->patch(route('connections.update', $theirs), ['name' => 'Mine', 'service' => 'ollama'])->assertForbidden();
        $this->actingAs($user)->delete(route('connections.destroy', $theirs))->assertForbidden();
        $this->actingAs($user)->getJson(route('connections.models', $theirs))->assertForbidden();
        $this->actingAs($user)->getJson(route('connections.test', $theirs))->assertForbidden();

        $this->assertSame('sk-or-secret', $theirs->fresh()->secret('api_key'));
    }

    public function test_removing_a_connection_leaves_its_rooms_without_an_agent()
    {
        $user = User::factory()->create();
        $connection = Connection::factory()->for($user)->create();
        $room = ChatRoom::factory()->for($user)->create(['ai_connection_id' => $connection->id, 'model' => 'qwen2.5:7b']);

        $this->actingAs($user)->delete(route('connections.destroy', $connection))->assertRedirect(route('connections.index'));

        $this->assertNull($room->fresh()->ai_connection_id);
        $this->assertFalse($room->fresh()->hasAgent());
    }

    public function test_the_models_a_host_offers_are_listed_asked_for_with_the_key()
    {
        Http::fake(['openrouter.ai/*' => Http::response(['data' => [['id' => 'z/model'], ['id' => 'a/model'], ['nope' => 1]]])]);
        $user = User::factory()->create();
        $connection = Connection::factory()->openrouter()->for($user)->create();

        $this->actingAs($user)
            ->getJson(route('connections.models', $connection))
            ->assertOk()
            ->assertExactJson(['models' => ['a/model', 'z/model'], 'free' => null]);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://openrouter.ai/api/v1/models'
            && $request->hasHeader('Authorization', 'Bearer sk-or-secret'));
    }

    public function test_only_an_ai_connection_lists_models()
    {
        Http::fake();
        $user = User::factory()->create();
        $bot = Connection::factory()->telegram()->for($user)->create();

        $this->actingAs($user)->getJson(route('connections.models', $bot))->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_ollama_is_asked_under_v1_whether_or_not_the_host_says_so()
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 'qwen2.5:7b']]])]);
        $user = User::factory()->create();

        foreach (['http://ollama.test:11434', 'http://ollama.test:11434/', 'http://ollama.test:11434/v1'] as $host) {
            $connection = Connection::factory()->for($user)->create(['settings' => ['base_url' => $host]]);

            $this->actingAs($user)->getJson(route('connections.models', $connection))->assertOk();
        }

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://ollama.test:11434/v1/models');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/v1/v1'));
    }

    public function test_a_host_that_fails_is_reported_in_our_words_not_its_own_body()
    {
        $user = User::factory()->create();
        $connection = Connection::factory()->for($user)->create();

        Http::fake(['*' => Http::sequence()
            ->push('<html>internal-admin-panel secret</html>', 500)
            ->push(['error' => ['message' => 'No auth credentials found']], 401)
            ->push('', 302, ['Location' => 'http://169.254.169.254/'])]);

        $this->actingAs($user)->getJson(route('connections.models', $connection))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'The host answered with 500.']);

        $this->actingAs($user)->getJson(route('connections.models', $connection))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'The host refused the key.']);

        $this->actingAs($user)->getJson(route('connections.models', $connection))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'The host sent us somewhere else.']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '169.254'));
    }

    public function test_a_host_that_cannot_be_reached_is_named()
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: refused'));
        $user = User::factory()->create();
        $connection = Connection::factory()->for($user)->create();

        $this->actingAs($user)->getJson(route('connections.models', $connection))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Could not reach ollama.test.']);
    }

    public function test_any_host_that_speaks_the_dialect_can_be_connected_not_only_two()
    {
        $user = User::factory()->create();

        // A hosted service falls back to its usual address and wants a key
        $this->actingAs($user)
            ->post(route('connections.store'), ['name' => 'GPT', 'service' => 'openai'])
            ->assertSessionHasErrors('secrets.api_key');
        $this->actingAs($user)
            ->post(route('connections.store'), ['name' => 'GPT', 'service' => 'openai', 'secrets' => ['api_key' => 'sk-x']]);
        $this->assertSame('https://api.openai.com/v1', $user->connections()->where('name', 'GPT')->sole()->setting('base_url'));

        // "Other" has no usual address, so one must be given, and needs no key
        $this->actingAs($user)
            ->post(route('connections.store'), ['name' => 'Gateway', 'service' => 'custom'])
            ->assertSessionHasErrors('settings.base_url');
        $this->actingAs($user)
            ->post(route('connections.store'), ['name' => 'Gateway', 'service' => 'custom', 'settings' => ['base_url' => 'https://llm.example.test/v1']])
            ->assertSessionHasNoErrors();

        // A local server is given without its /v1, as Ollama is
        Http::fake(['*' => Http::response(['data' => []])]);
        $studio = Connection::factory()->for($user)->create(['service' => 'lmstudio', 'settings' => ['base_url' => 'http://studio.test:1234']]);
        $this->actingAs($user)->getJson(route('connections.models', $studio))->assertOk();
        Http::assertSent(fn (Request $request) => $request->url() === 'http://studio.test:1234/v1/models');

        // ...while one that is "other" is asked exactly where it was said to be
        $gateway = $user->connections()->where('name', 'Gateway')->sole();
        $this->actingAs($user)->getJson(route('connections.models', $gateway))->assertOk();
        Http::assertSent(fn (Request $request) => $request->url() === 'https://llm.example.test/v1/models');
        $this->assertSame('Other', $gateway->label());
    }

    public function test_the_form_is_tried_before_it_is_saved_and_nothing_is_kept()
    {
        Http::fake(['openrouter.ai/*' => Http::response(['data' => [['id' => 'b/m'], ['id' => 'a/m']]])]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('connections.check'), ['service' => 'openrouter', 'secrets' => ['api_key' => 'sk-or-typed']])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'message' => 'Works: 2 models available.', 'models' => ['a/m', 'b/m'], 'free' => null]);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://openrouter.ai/api/v1/models'
            && $request->hasHeader('Authorization', 'Bearer sk-or-typed'));
        $this->assertSame(0, $user->connections()->count());
    }

    public function test_a_failed_try_says_why()
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'bad key']], 401)]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('connections.check'), ['service' => 'openai', 'secrets' => ['api_key' => 'nope']])
            ->assertOk()
            ->assertExactJson(['ok' => false, 'message' => 'The host refused the key.']);

        // "Other" with no address has nowhere to try
        $this->actingAs($user)
            ->postJson(route('connections.check'), ['service' => 'custom'])
            ->assertOk()
            ->assertExactJson(['ok' => false, 'message' => 'Give the address of the host.']);

        // A value of the wrong shape is an answer too, in the field's own name
        $this->actingAs($user)
            ->postJson(route('connections.check'), ['service' => 'custom', 'settings' => ['base_url' => 'ftp://x.test']])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'host'));

        $this->actingAs($user)
            ->postJson(route('connections.check'), ['service' => 'telegram', 'secrets' => ['bot_token' => 'not a token']])
            ->assertExactJson(['ok' => false, 'message' => 'That is not a bot token: it looks like 123456789:AA…, as @BotFather gives it.']);
    }

    public function test_changing_a_saved_connection_is_tried_with_its_own_key_when_none_is_typed()
    {
        Http::fake(['*' => Http::response(['data' => []])]);
        $user = User::factory()->create();
        $mine = Connection::factory()->openrouter()->for($user)->create();

        $this->actingAs($user)
            ->postJson(route('connections.check'), ['service' => 'openrouter', 'connection' => $mine->ref_id])
            ->assertOk();

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer sk-or-secret'));
    }

    public function test_another_owners_secret_is_never_borrowed_to_try_a_form()
    {
        Http::fake();
        $user = User::factory()->create();
        $theirs = Connection::factory()->openrouter()->create();
        $projects = Connection::factory()->openrouter()->forProject(Project::factory()->withMember($user)->create())->create();

        $this->actingAs($user)
            ->postJson(route('connections.check'), ['service' => 'openrouter', 'connection' => $theirs->ref_id])
            ->assertJsonValidationErrors('connection');

        // Not even a project's, from one's own settings
        $this->actingAs($user)
            ->postJson(route('connections.check'), ['service' => 'openrouter', 'connection' => $projects->ref_id])
            ->assertJsonValidationErrors('connection');

        Http::assertNothingSent();
    }

    public function test_guests_cannot_have_the_app_try_a_host()
    {
        Http::fake();

        $this->postJson(route('connections.check'), ['service' => 'ollama'])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_the_free_models_are_told_apart_when_the_host_gives_prices()
    {
        Http::fake(['openrouter.ai/*' => Http::response(['data' => [
            ['id' => 'paid/model', 'pricing' => ['prompt' => '0.000001', 'completion' => '0.000002']],
            ['id' => 'free/model:free', 'pricing' => ['prompt' => '0', 'completion' => '0']],
            ['id' => 'untagged/free', 'pricing' => ['prompt' => '0.0', 'completion' => 0]],
            ['id' => 'half/free', 'pricing' => ['prompt' => '0', 'completion' => '0.000003']],
            ['id' => 'openrouter/auto', 'pricing' => ['prompt' => '-1', 'completion' => '-1']],
            ['id' => 'no/price-given', 'pricing' => []],
        ]])]);
        $user = User::factory()->create();
        $connection = Connection::factory()->openrouter()->for($user)->create();

        $this->actingAs($user)
            ->getJson(route('connections.models', $connection))
            ->assertOk()
            ->assertJsonPath('free', ['free/model:free', 'untagged/free'])
            ->assertJsonCount(6, 'models');

        // Trying the form says the same
        $this->actingAs($user)
            ->postJson(route('connections.check'), ['service' => 'openrouter', 'secrets' => ['api_key' => 'k']])
            ->assertJsonPath('free', ['free/model:free', 'untagged/free']);
    }

    public function test_a_host_that_gives_no_prices_has_no_free_list_rather_than_an_empty_one()
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 'qwen2.5:7b'], ['id' => 'llama3']]])]);
        $user = User::factory()->create();
        $connection = Connection::factory()->for($user)->create();

        $this->actingAs($user)
            ->getJson(route('connections.models', $connection))
            ->assertJsonPath('free', null)
            ->assertJsonPath('models', ['llama3', 'qwen2.5:7b']);
    }

    public function test_localhost_in_a_connection_reaches_the_machine_not_the_container()
    {
        config(['services.chat.localhost_as' => '172.16.8.1']);
        Http::fake(['*' => Http::response(['data' => []])]);
        $user = User::factory()->create();
        $connection = Connection::factory()->for($user)->create(['settings' => ['base_url' => 'http://localhost:11434']]);

        $this->actingAs($user)->getJson(route('connections.models', $connection))->assertOk();
        $this->actingAs($user)
            ->postJson(route('connections.check'), ['service' => 'ollama', 'settings' => ['base_url' => 'http://127.0.0.1:11434']])
            ->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://172.16.8.1:11434/v1/models');
        // What the user typed is what is kept and shown
        $this->assertSame('http://localhost:11434', $connection->fresh()->setting('base_url'));
    }

    // --- a project's ---

    public function test_a_projects_editors_keep_its_connections_and_its_viewers_only_see_their_names()
    {
        $editor = User::factory()->create();
        $viewer = User::factory()->create();
        $project = Project::factory()->withMember($editor, Project::EDITOR)->withMember($viewer, Project::VIEWER)->create();

        $this->actingAs($editor)
            ->post(route('projects.connections.store', $project), ['name' => 'Team OR', 'service' => 'openrouter', 'secrets' => ['api_key' => 'sk-team']])
            ->assertRedirect(route('projects.connections.index', $project));

        $connection = $project->connections()->sole();
        $this->assertNull($connection->user_id);
        $this->assertSame($editor->id, $connection->created_by);
        $this->assertSame(0, $editor->connections()->count());

        $this->actingAs($viewer)
            ->get(route('projects.connections.index', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/Connections')
                ->where('project.ref_id', $project->ref_id)
                ->where('can.manage', false)
                ->where('connections.0.name', 'Team OR')
                ->where('connections.0.added_by', $editor->name)
                ->missing('connections.0.secrets'));

        $this->actingAs($viewer)->post(route('projects.connections.store', $project), ['name' => 'X', 'service' => 'ollama'])->assertForbidden();
        $this->actingAs($viewer)->patch(route('connections.update', $connection), ['name' => 'X', 'service' => 'openrouter'])->assertForbidden();
        $this->actingAs($viewer)->delete(route('connections.destroy', $connection))->assertForbidden();
        $this->actingAs($viewer)->getJson(route('connections.test', $connection))->assertForbidden();
        $this->actingAs($viewer)->postJson(route('projects.connections.check', $project), ['service' => 'ollama'])->assertForbidden();

        $this->actingAs($editor)
            ->patch(route('connections.update', $connection), ['name' => 'Team', 'service' => 'openrouter'])
            ->assertRedirect(route('projects.connections.index', $project));
        $this->assertSame('sk-team', $connection->fresh()->secret('api_key'));
    }

    public function test_outsiders_never_see_a_projects_connections()
    {
        $project = Project::factory()->withMember(User::factory()->create())->create();
        $connection = Connection::factory()->openrouter()->forProject($project)->create();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get(route('projects.connections.index', $project))->assertForbidden();
        $this->actingAs($outsider)->getJson(route('connections.models', $connection))->assertForbidden();
    }

    public function test_a_projects_connections_go_with_the_project()
    {
        $project = Project::factory()->withMember(User::factory()->create())->create();
        Connection::factory()->forProject($project)->create();

        $project->delete();

        $this->assertDatabaseCount('connections', 0);
    }

    public function test_a_projects_connection_never_answers_a_members_own_chat()
    {
        // Of a room's own agent, set up only while rooms may have one
        config(['services.chat.agents' => true]);
        $user = User::factory()->create();
        $project = Project::factory()->withMember($user)->create();
        $shared = Connection::factory()->forProject($project)->create();
        $room = ChatRoom::factory()->for($user)->create();

        $this->actingAs($user)
            ->patch(route('chats.update', $room), ['connection' => $shared->ref_id])
            ->assertSessionHasErrors('connection');

        $this->actingAs($user)
            ->get(route('chats.show', $room))
            ->assertInertia(fn (Assert $page) => $page->has('connections', 0));
    }

    // --- other services ---

    public function test_a_telegram_bot_is_kept_with_its_token_hidden_and_tried_by_asking_telegram()
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'zyrenn_bot']])]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('connections.store'), ['name' => 'Bot', 'service' => 'telegram', 'secrets' => ['bot_token' => 'not a token']])
            ->assertSessionHasErrors('secrets.bot_token');

        $this->actingAs($user)
            ->post(route('connections.store'), ['name' => 'Bot', 'service' => 'telegram', 'secrets' => ['bot_token' => '123456:AAbc-_d']])
            ->assertSessionHasNoErrors();

        $bot = $user->connections()->sole();
        // Asking for messages is the usual way, since it needs no public address
        $this->assertSame('poll', $bot->setting('receive'));
        $this->assertFalse($bot->isAi());

        $this->actingAs($user)
            ->getJson(route('connections.test', $bot))
            ->assertExactJson(['ok' => true, 'message' => 'Works: this is @zyrenn_bot.']);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.telegram.org/bot123456:AAbc-_d/getMe');
    }

    public function test_a_token_telegram_does_not_know_is_said_so_without_repeating_it()
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('connections.check'), ['service' => 'telegram', 'secrets' => ['bot_token' => '99:wrong']])
            ->assertExactJson(['ok' => false, 'message' => 'Telegram does not know this token.']);
    }

    public function test_a_line_channel_needs_both_values_and_is_tried_with_its_token()
    {
        Http::fake(['api.line.me/*' => Http::response(['displayName' => 'Zyrenn Shop'])]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('connections.store'), ['name' => 'Shop', 'service' => 'line', 'secrets' => ['channel_access_token' => 'tok']])
            ->assertSessionHasErrors('secrets.channel_secret');

        $this->actingAs($user)
            ->postJson(route('connections.check'), ['service' => 'line', 'secrets' => ['channel_access_token' => 'tok']])
            ->assertExactJson(['ok' => true, 'message' => 'Works: this is “Zyrenn Shop”.']);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.line.me/v2/bot/info'
            && $request->hasHeader('Authorization', 'Bearer tok'));
    }

    public function test_an_api_key_is_lent_only_to_the_address_it_was_given_for()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('connections.store'), ['name' => 'Key', 'service' => 'http_header', 'settings' => ['header_name' => 'X Api Key', 'base_url' => 'https://api.example.test/v2'], 'secrets' => ['header_value' => 'k']])
            ->assertSessionHasErrors('settings.header_name');

        $this->actingAs($user)
            ->post(route('connections.store'), ['name' => 'Key', 'service' => 'http_header', 'settings' => ['base_url' => 'https://api.example.test/v2/'], 'secrets' => ['header_value' => 'Bearer k']])
            ->assertSessionHasNoErrors();

        $key = $user->connections()->sole();
        $this->assertSame('Authorization', $key->setting('header_name'));

        $this->assertTrue($key->sendsTo('https://api.example.test/v2/users?page=2'));
        $this->assertTrue($key->sendsTo('https://API.example.test:443/v2'));
        $this->assertFalse($key->sendsTo('https://api.example.test/v1/users'));
        $this->assertFalse($key->sendsTo('https://api.example.test/v2x'));
        $this->assertFalse($key->sendsTo('https://api.example.test.evil.test/v2'));
        $this->assertFalse($key->sendsTo('http://api.example.test/v2'));
        $this->assertFalse($key->sendsTo('https://api.example.test:8443/v2'));
        $this->assertFalse($key->sendsTo('not a url'));
    }

    public function test_a_bot_or_channel_shows_where_its_messages_are_sent_and_how_that_is_set()
    {
        config(['services.workflows.public_url' => 'https://hooks.example.test/']);
        $user = User::factory()->create();
        $line = Connection::factory()->for($user)->create(['name' => 'A shop', 'service' => 'line', 'settings' => [], 'secrets' => ['channel_access_token' => 't', 'channel_secret' => 's']]);
        $bot = Connection::factory()->telegram()->for($user)->create(['name' => 'B bot', 'settings' => ['receive' => 'webhook']]);
        Connection::factory()->telegram()->for($user)->create(['name' => 'C poller', 'settings' => ['receive' => 'poll']]);
        Connection::factory()->openrouter()->for($user)->create(['name' => 'D model']);

        $this->actingAs($user)->get(route('connections.index'))->assertInertia(fn (Assert $page) => $page
            ->where('connections.0.hook', ['url' => "https://hooks.example.test/hooks/line/{$line->ref_id}", 'set' => 'by_hand'])
            ->where('connections.1.hook', ['url' => "https://hooks.example.test/hooks/telegram/{$bot->ref_id}", 'set' => 'by_app'])
            ->where('connections.2.hook', null)
            ->where('connections.3.hook', null));

        // Nothing outside can reach the app yet: no address to give
        config(['services.workflows.public_url' => null]);
        $this->actingAs($user)->get(route('connections.index'))->assertInertia(fn (Assert $page) => $page
            ->where('connections.0.hook', ['url' => null, 'set' => 'by_hand']));
    }

    public function test_a_linkedin_connection_is_signed_in_on_linkedin_and_keeps_what_that_gave_through_a_change()
    {
        config(['services.workflows.public_url' => null]);
        Http::preventStrayRequests();
        Http::fake([
            'www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'granted-token', 'expires_in' => 5184000, 'scope' => 'openid,profile,w_member_social']),
            'api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc123', 'name' => 'Ada Lovelace']),
        ]);
        $user = User::factory()->create();

        // Nothing to try before it is signed in, so it is saved at once
        $this->actingAs($user)
            ->postJson(route('connections.check'), ['service' => 'linkedin', 'settings' => ['client_id' => 'client-1'], 'secrets' => ['client_secret' => 'shh']])
            ->assertJson(['ok' => true]);
        $this->actingAs($user)
            ->post(route('connections.store'), ['name' => 'Me', 'service' => 'linkedin', 'settings' => ['client_id' => 'client-1', 'member' => 'someone-else'], 'secrets' => ['client_secret' => 'shh', 'access_token' => 'typed']])
            ->assertRedirect();

        $connection = $user->connections()->sole();
        // What signing in gives is never taken from a form
        $this->assertSame([['client_id' => 'client-1'], ['client_secret' => 'shh']], [$connection->settings, $connection->secrets]);

        $callback = route('connections.signed-in');
        $this->actingAs($user)->get(route('connections.index'))->assertInertia(fn (Assert $page) => $page
            ->where('connections.0.sign_in.url', route('connections.sign-in', $connection))
            ->where('connections.0.sign_in.callback', $callback)
            ->where('connections.0.sign_in.service', 'LinkedIn')
            ->where('connections.0.sign_in.state', 'no')
            ->where('connections.0.sign_in.detail', 'Not signed in yet'));

        // There: to LinkedIn, to post as its member, and back to the one address
        $to = $this->actingAs($user)->get(route('connections.sign-in', $connection))->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url((string) $to, PHP_URL_QUERY), $asked);
        $this->assertStringStartsWith('https://www.linkedin.com/oauth/v2/authorization?', (string) $to);
        $this->assertSame(['code', 'client-1', $callback, 'openid profile w_member_social'], [$asked['response_type'], $asked['client_id'], $asked['redirect_uri'], $asked['scope']]);

        // And back: the code is traded with the secret, and LinkedIn says who it is
        $this->travelTo('2026-10-12 09:00:00');
        $this->actingAs($user)->get(route('connections.signed-in', ['code' => 'the-code', 'state' => $asked['state']]))
            ->assertRedirect(route('connections.index'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'accessToken')
            && $request->data() === ['grant_type' => 'authorization_code', 'code' => 'the-code', 'redirect_uri' => $callback, 'client_id' => 'client-1', 'client_secret' => 'shh']);

        $connection->refresh();
        $this->assertSame(['client_id' => 'client-1', 'member' => 'abc123', 'member_name' => 'Ada Lovelace', 'expires_at' => '2026-12-11T09:00:00+00:00'], $connection->settings);
        $this->assertSame('granted-token', $connection->secret('access_token'));

        $this->actingAs($user)->get(route('connections.index'))->assertInertia(fn (Assert $page) => $page
            ->where('connections.0.sign_in.state', 'yes')
            ->where('connections.0.sign_in.detail', 'Signed in as Ada Lovelace until 11 Dec 2026'));

        // The same answer is not taken twice
        $this->actingAs($user)->get(route('connections.signed-in', ['code' => 'the-code', 'state' => $asked['state']]))->assertRedirect(route('connections.index'));
        Http::assertSentCount(2);

        // Renamed, with its secret left blank: still signed in, and LinkedIn says so
        $this->actingAs($user)
            ->patch(route('connections.update', $connection), ['name' => 'Ada', 'service' => 'linkedin', 'settings' => ['client_id' => 'client-1'], 'secrets' => ['client_secret' => '']])
            ->assertRedirect();
        $connection->refresh();
        $this->assertSame(['Ada', 'abc123', 'granted-token', 'shh'], [$connection->name, $connection->setting('member'), $connection->secret('access_token'), $connection->secret('client_secret')]);
        $this->actingAs($user)->getJson(route('connections.test', $connection))
            ->assertJson(['ok' => true, 'message' => 'Works: signed in as Ada Lovelace until Fri, Dec 11, 2026.']);

        $this->travelTo('2026-12-12 09:00:00');
        $this->actingAs($user)->getJson(route('connections.test', $connection))
            ->assertJson(['ok' => false, 'message' => 'Its sign-in ran out on Fri, Dec 11, 2026: sign in with LinkedIn again.']);
    }

    public function test_a_linkedin_sign_in_is_only_for_who_keeps_the_connection_and_a_refusal_keeps_nothing()
    {
        config(['services.workflows.public_url' => null]);
        Http::preventStrayRequests();
        Http::fake(['www.linkedin.com/oauth/v2/accessToken' => Http::response(['error' => 'invalid_client', 'error_description' => 'Client authentication failed'], 401)]);
        $user = User::factory()->create();
        $connection = Connection::factory()->for($user)->create(['name' => 'Me', 'service' => 'linkedin', 'settings' => ['client_id' => 'client-1'], 'secrets' => ['client_secret' => 'wrong']]);
        $state = function () use ($user, $connection) {
            parse_str((string) parse_url((string) $this->actingAs($user)->get(route('connections.sign-in', $connection))->headers->get('Location'), PHP_URL_QUERY), $asked);

            return $asked['state'];
        };

        $this->actingAs(User::factory()->create())->get(route('connections.sign-in', $connection))->assertForbidden();
        $this->actingAs($user)->get(route('connections.sign-in', Connection::factory()->telegram()->for($user)->create()))->assertNotFound();

        // Said no on LinkedIn's page; an answer nobody asked for; a secret LinkedIn does not know
        $this->actingAs($user)->get(route('connections.signed-in', ['error' => 'user_cancelled_authorize', 'state' => $state()]))
            ->assertRedirect(route('connections.index'))->assertInertiaFlash('toast.message', 'Signing in to LinkedIn was cancelled.');
        $state();
        $this->actingAs($user)->get(route('connections.signed-in', ['code' => 'c', 'state' => 'made-up']))
            ->assertRedirect(route('connections.index'))->assertInertiaFlash('toast.type', 'error');
        Http::assertNothingSent();
        $this->actingAs($user)->get(route('connections.signed-in', ['code' => 'c', 'state' => $state()]))
            ->assertInertiaFlash('toast.message', 'LinkedIn did not sign it in: Client authentication failed.');

        $this->assertSame([['client_id' => 'client-1'], ['client_secret' => 'wrong']], [$connection->fresh()->settings, $connection->fresh()->secrets]);
    }

    public function test_where_the_app_has_a_public_address_linkedin_sends_its_member_back_by_it()
    {
        config(['services.workflows.public_url' => 'https://hooks.example.test']);
        Http::preventStrayRequests();
        Http::fake([
            'www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'granted-token', 'expires_in' => 5184000]),
            'api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc123', 'name' => 'Ada Lovelace']),
        ]);
        $public = 'https://hooks.example.test/hooks/linkedin/signed-in';
        $user = User::factory()->create();
        $connection = Connection::factory()->for($user)->create(['name' => 'Me', 'service' => 'linkedin', 'settings' => ['client_id' => 'client-1'], 'secrets' => ['client_secret' => 'shh']]);

        // The one address to give LinkedIn, wherever the app is opened from
        $this->actingAs($user)->get('http://localhost:8001/settings/connections')->assertInertia(fn (Assert $page) => $page
            ->where('connections.0.sign_in.callback', $public));
        parse_str((string) parse_url((string) $this->actingAs($user)->get("http://localhost:8001/settings/connections/{$connection->ref_id}/sign-in")->headers->get('Location'), PHP_URL_QUERY), $asked);
        $this->assertSame($public, $asked['redirect_uri']);

        // Nobody is signed in there: it takes nothing, and passes them on to where they set out -- once
        auth()->logout();
        $this->get("/hooks/linkedin/signed-in?code=the-code&state={$asked['state']}")
            ->assertRedirect("http://localhost:8001/settings/connections/signed-in?code=the-code&state={$asked['state']}");
        $this->get("/hooks/linkedin/signed-in?code=the-code&state={$asked['state']}&to=https://evil.test")
            ->assertRedirect(config('app.url')."/settings/connections/signed-in?code=the-code&state={$asked['state']}");
        Http::assertNothingSent();
        $this->assertNull($connection->fresh()->secret('access_token'));

        // Set out from a host the app is not known at: passed on to the app's own, never there
        parse_str((string) parse_url((string) $this->actingAs($user)->get("http://evil.test/settings/connections/{$connection->ref_id}/sign-in")->headers->get('Location'), PHP_URL_QUERY), $forged);
        $this->get("/hooks/linkedin/signed-in?code=c&state={$forged['state']}")
            ->assertRedirect(config('app.url')."/settings/connections/signed-in?code=c&state={$forged['state']}");
        parse_str((string) parse_url((string) $this->actingAs($user)->get("http://localhost:8001/settings/connections/{$connection->ref_id}/sign-in")->headers->get('Location'), PHP_URL_QUERY), $asked);

        // And there the code is traded, naming the address LinkedIn was given
        $this->actingAs($user)->get("http://localhost:8001/settings/connections/signed-in?code=the-code&state={$asked['state']}")->assertRedirect();
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'accessToken') && $request['redirect_uri'] === $public);
        $this->assertSame('granted-token', $connection->fresh()->secret('access_token'));
    }

    public function test_an_error_asked_for_at_the_public_address_never_shows_its_workings()
    {
        config(['app.debug' => true, 'services.workflows.public_url' => 'https://hooks.example.test']);

        $this->get('http://localhost/login');
        $this->assertTrue(config('app.debug'));

        $this->get('https://hooks.example.test/login');
        $this->assertFalse(config('app.debug'));
    }

    public function test_a_facebook_connection_is_signed_in_as_the_page_it_posts_as()
    {
        config(['services.workflows.public_url' => 'https://hooks.example.test']);
        Http::preventStrayRequests();
        $pages = [['id' => '1001', 'name' => 'Shop', 'access_token' => 'shop-token'], ['id' => '1002', 'name' => 'Side Project', 'access_token' => 'side-token']];
        Http::fake(fn (Request $request) => match (true) {
            str_contains($request->url(), 'fb_exchange_token') => Http::response(['access_token' => 'long-user-token', 'expires_in' => 5184000]),
            str_contains($request->url(), '/oauth/access_token') => Http::response(['access_token' => 'short-user-token', 'token_type' => 'bearer', 'expires_in' => 5000]),
            str_contains($request->url(), '/me/accounts') => Http::response(['data' => $pages]),
            str_contains($request->url(), '/me?fields=id') => Http::response(['id' => '9001']),
            default => Http::response(['error' => ['message' => 'Not faked']], 400),
        });
        $user = User::factory()->create();
        $connection = Connection::factory()->for($user)->create(['name' => 'Page', 'service' => 'facebook', 'settings' => ['client_id' => 'app-1'], 'secrets' => ['client_secret' => 'app-secret']]);
        $signIn = function () use ($user, $connection) {
            parse_str((string) parse_url((string) $this->actingAs($user)->get("http://localhost/settings/connections/{$connection->ref_id}/sign-in")->headers->get('Location'), PHP_URL_QUERY), $asked);

            return [$asked, $this->actingAs($user)->get("http://localhost/settings/connections/signed-in?code=the-code&state={$asked['state']}")];
        };

        // To Facebook, for its Pages, and back by the public address
        [$asked, $back] = $signIn();
        $this->assertSame(['app-1', 'https://hooks.example.test/hooks/facebook/signed-in', 'code'], [$asked['client_id'], $asked['redirect_uri'], $asked['response_type']]);
        $this->assertStringContainsString('pages_manage_posts', $asked['scope']);

        // It looks after two: which one has to be said, and nothing is kept until it is
        $back->assertInertiaFlash('toast.message', 'This account looks after 2 Pages: "Shop", "Side Project". Write the one to post as in "Page" on the connection, then sign in again.');
        $this->assertNull($connection->fresh()->secret('page_token'));

        $this->actingAs($user)->patch(route('connections.update', $connection), ['name' => 'Page', 'service' => 'facebook', 'settings' => ['client_id' => 'app-1', 'page' => 'side project'], 'secrets' => []])->assertRedirect();
        $signIn()[1]->assertInertiaFlash('toast.message', 'Signed in to Facebook as Side Project.');

        // The Page's own token is what is kept, made from a sign-in that lasts
        $connection->refresh();
        $this->assertSame(['1002', 'Side Project', 'side-token'], [$connection->setting('page_id'), $connection->setting('page_name'), $connection->secret('page_token')]);
        // And who signed it in, by the id Facebook gives the app for them: how word that they took the app off is matched
        $this->assertSame('9001', $connection->setting('signer'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/me/accounts') && $request->hasHeader('Authorization', 'Bearer long-user-token'));
        $this->actingAs($user)->get(route('connections.index'))->assertInertia(fn (Assert $page) => $page
            ->where('connections.0.sign_in.detail', 'Signed in as Side Project')
            ->where('connections.0.saved_secrets', ['client_secret', 'page_token']));

        // A Page not among them is said so, with those there are
        $this->actingAs($user)->patch(route('connections.update', $connection), ['name' => 'Page', 'service' => 'facebook', 'settings' => ['client_id' => 'app-1', 'page' => 'Elsewhere'], 'secrets' => []]);
        $signIn()[1]->assertInertiaFlash('toast.message', 'There is no Page "Elsewhere" among those shared with the app: "Shop", "Side Project".');
    }

    public function test_instagram_and_threads_sign_in_for_sixty_days_and_are_made_new_before_they_run_out()
    {
        config(['services.workflows.public_url' => null]);
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => match (true) {
            // Instagram wraps what it gives in a list of one; Threads does not
            $request->url() === 'https://api.instagram.com/oauth/access_token' => Http::response(['data' => [['access_token' => 'ig-short', 'user_id' => '1789', 'permissions' => 'instagram_business_basic']]]),
            $request->url() === 'https://graph.threads.net/oauth/access_token' => Http::response(['access_token' => 'th-short', 'token_type' => 'bearer', 'user_id' => 2456]),
            str_contains($request->url(), 'ig_exchange_token') => Http::response(['access_token' => 'ig-long', 'token_type' => 'bearer', 'expires_in' => 5184000]),
            str_contains($request->url(), 'th_exchange_token') => Http::response(['access_token' => 'th-long', 'token_type' => 'bearer', 'expires_in' => 5184000]),
            str_contains($request->url(), 'ig_refresh_token') => Http::response(['access_token' => 'ig-renewed', 'token_type' => 'bearer', 'expires_in' => 5184000]),
            str_starts_with($request->url(), 'https://graph.instagram.com/v25.0/me') => Http::response(['id' => '3941', 'user_id' => '1789', 'username' => 'shop']),
            str_starts_with($request->url(), 'https://graph.threads.net/v1.0/me') => Http::response(['id' => '2456', 'username' => 'shop.th']),
            default => Http::response(['error' => ['message' => 'Not faked']], 400),
        });
        $this->travelTo('2026-10-12 09:00:00');
        $user = User::factory()->create();
        $make = fn (string $service) => Connection::factory()->for($user)->create(['name' => ucfirst($service), 'service' => $service, 'settings' => ['client_id' => 'the-app'], 'secrets' => ['client_secret' => 'the-secret']]);
        $signIn = function (Connection $connection) use ($user) {
            $to = (string) $this->actingAs($user)->get(route('connections.sign-in', $connection))->headers->get('Location');
            parse_str((string) parse_url($to, PHP_URL_QUERY), $asked);
            $this->actingAs($user)->get(route('connections.signed-in', ['code' => 'the-code', 'state' => $asked['state']]))->assertInertiaFlash('toast.type', 'success');

            return [$to, $asked];
        };

        [$to, $asked] = $signIn($instagram = $make('instagram'));
        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $to);
        $this->assertSame(['the-app', 'instagram_business_basic,instagram_business_content_publish,instagram_business_manage_comments,instagram_business_manage_messages'], [$asked['client_id'], $asked['scope']]);
        $instagram->refresh();
        // Instagram names the account by two ids: both are kept
        $this->assertSame(['1789', '3941', 'shop', '2026-12-11T09:00:00+00:00', 'ig-long'], [$instagram->setting('member'), $instagram->setting('scoped_id'), $instagram->setting('member_name'), $instagram->setting('expires_at'), $instagram->secret('access_token')]);

        [$to, $asked] = $signIn($threads = $make('threads'));
        $this->assertStringStartsWith('https://threads.com/oauth/authorize?', $to);
        $this->assertStringContainsString('threads_content_publish', $asked['scope']);
        $threads->refresh();
        $this->assertSame(['2456', 'shop.th', 'th-long'], [$threads->setting('member'), $threads->setting('member_name'), $threads->secret('access_token')]);
        $this->actingAs($user)->getJson(route('connections.test', $threads))->assertJson(['ok' => true, 'message' => 'Works: signed in as shop.th until Fri, Dec 11, 2026.']);

        // With weeks left it is used as it is; near its end it is made new, unasked
        $this->actingAs($user)->getJson(route('connections.test', $instagram))->assertJson(['ok' => true]);
        $this->assertSame('ig-long', $instagram->fresh()->secret('access_token'));

        $this->travelTo('2026-12-05 09:00:00');
        $this->actingAs($user)->getJson(route('connections.test', $instagram))->assertJson(['ok' => true, 'message' => 'Works: signed in as shop until Wed, Feb 3, 2027.']);
        $this->assertSame(['ig-renewed', '2027-02-03T09:00:00+00:00'], [$instagram->fresh()->secret('access_token'), $instagram->fresh()->setting('expires_at')]);
    }

    public function test_an_instagram_connection_signs_in_by_facebook_as_the_account_its_page_is_linked_to()
    {
        config(['services.workflows.public_url' => 'https://hooks.example.test']);
        Http::preventStrayRequests();
        $pages = [['id' => '1001', 'name' => 'Shop', 'access_token' => 'shop-token', 'instagram_business_account' => ['id' => '1789', 'username' => 'shop']], ['id' => '1002', 'name' => 'Side Project', 'access_token' => 'side-token']];
        Http::fake(function (Request $request) use (&$pages) {
            return match (true) {
                str_contains($request->url(), 'fb_exchange_token') => Http::response(['access_token' => 'long-user-token', 'expires_in' => 5184000]),
                str_contains($request->url(), '/oauth/access_token') => Http::response(['access_token' => 'short-user-token', 'token_type' => 'bearer', 'expires_in' => 5000]),
                str_contains($request->url(), '/me/accounts') => Http::response(['data' => $pages]),
                str_starts_with($request->url(), 'https://graph.facebook.com/v25.0/1789') => Http::response(['username' => 'shop', 'id' => '1789']),
                default => Http::response(['error' => ['message' => 'Not faked']], 400),
            };
        });
        $user = User::factory()->create();
        $connection = Connection::factory()->for($user)->create(['name' => 'Shop', 'service' => 'instagram', 'settings' => ['by' => 'facebook', 'client_id' => 'app-1'], 'secrets' => ['client_secret' => 'app-secret']]);
        $signIn = function () use ($user, $connection) {
            $to = (string) $this->actingAs($user)->get("http://localhost/settings/connections/{$connection->ref_id}/sign-in")->headers->get('Location');
            parse_str((string) parse_url($to, PHP_URL_QUERY), $asked);

            return [$to, $asked, $this->actingAs($user)->get("http://localhost/settings/connections/signed-in?code=the-code&state={$asked['state']}")];
        };

        // To Facebook, for what Instagram lets an app do there -- taking a post down among it -- and back by Instagram's own address
        [$to, $asked, $back] = $signIn();
        $this->assertStringStartsWith('https://www.facebook.com/v25.0/dialog/oauth?', $to);
        $this->assertSame(['app-1', 'https://hooks.example.test/hooks/instagram/signed-in'], [$asked['client_id'], $asked['redirect_uri']]);
        $this->assertStringContainsString('instagram_manage_contents', $asked['scope']);
        $back->assertInertiaFlash('toast.message', 'Signed in to Instagram as @shop.');

        // The account is the one linked to a Page, kept with that Page's token, which does not run out
        $connection->refresh();
        $this->assertSame(['1789', 'shop', 'facebook', null, 'shop-token'], [$connection->setting('member'), $connection->setting('member_name'), $connection->setting('via'), $connection->setting('expires_at'), $connection->secret('access_token')]);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/me/accounts') && str_contains(urldecode($request->url()), 'instagram_business_account{id,username}'));

        // It is asked after at Facebook's address, as the account and not as "me", who there is the Page
        $this->actingAs($user)->getJson(route('connections.test', $connection))->assertJson(['ok' => true, 'message' => 'Works: signed in as shop.']);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://graph.facebook.com/v25.0/1789?'));

        // Saved again it keeps what signing in gave; set to sign in with Instagram, that sign-in is not this one's
        $this->actingAs($user)->patch(route('connections.update', $connection), ['name' => 'Shop', 'service' => 'instagram', 'settings' => ['by' => 'facebook', 'client_id' => 'app-1'], 'secrets' => []])->assertRedirect();
        $this->actingAs($user)->get(route('connections.index'))->assertInertia(fn (Assert $page) => $page
            ->where('connections.0.sign_in.detail', 'Signed in as @shop')
            // Where its address is listed is said for the way it signs in
            ->where('connections.0.sign_in.hint', fn (string $hint) => str_contains($hint, 'Facebook Login for Business') && str_contains($hint, 'instagram_manage_contents')));
        $this->actingAs($user)->patch(route('connections.update', $connection), ['name' => 'Shop', 'service' => 'instagram', 'settings' => ['by' => 'instagram', 'client_id' => 'app-1'], 'secrets' => []])->assertRedirect();
        $this->actingAs($user)->get(route('connections.index'))->assertInertia(fn (Assert $page) => $page
            ->where('connections.0.sign_in.detail', 'Not signed in yet')
            ->where('connections.0.sign_in.hint', fn (string $hint) => str_contains($hint, 'API setup with Instagram login')));

        // What Facebook shared is said, so what is missing shows: no Page, a Page with no account, or more than one account
        $this->actingAs($user)->patch(route('connections.update', $connection), ['name' => 'Shop', 'service' => 'instagram', 'settings' => ['by' => 'facebook', 'client_id' => 'app-1'], 'secrets' => []]);
        $all = $pages;
        $pages = [];
        $signIn()[2]->assertInertiaFlash('toast.message', 'Facebook shared no Page with the app. Sign in again and, where Facebook asks what to share, choose the Page the Instagram account is linked to, and the account.');
        $pages = [$all[1]];
        $signIn()[2]->assertInertiaFlash('toast.message', 'Facebook shared the Page "Side Project", and showed no Instagram account linked to it. Link the account to its Page on Facebook, then sign in again and share both the Page and the account.');
        // A Page may name its account the other way
        $pages = [['id' => '1001', 'access_token' => 'a', 'instagram_business_account' => ['id' => '1789', 'username' => 'shop']], ['id' => '1003', 'access_token' => 'b', 'connected_instagram_account' => ['id' => '1790', 'username' => 'cafe']]];
        $signIn()[2]->assertInertiaFlash('toast.message', 'Facebook shared 2 Instagram accounts: @shop, @cafe. Sign in again and share only the one to post as.');
        $this->assertSame('shop-token', $connection->fresh()->secret('access_token'));
    }

    public function test_a_signed_in_instagram_or_threads_connection_shows_where_meta_sends_what_happens_there()
    {
        config(['services.workflows.public_url' => 'https://hooks.example.test']);
        $user = User::factory()->create();
        $instagram = Connection::factory()->instagram()->for($user)->create(['name' => 'A']);
        $threads = Connection::factory()->threads()->for($user)->create(['name' => 'B']);
        // Signed in by Facebook, or not signed in at all, there is nothing to send to
        Connection::factory()->instagram()->for($user)->create(['name' => 'C', 'settings' => ['by' => 'facebook', 'via' => 'facebook', 'client_id' => 'app-1', 'member' => '1789', 'member_name' => 'shop']]);
        Connection::factory()->for($user)->create(['name' => 'D', 'service' => 'threads', 'settings' => ['client_id' => 'th-app'], 'secrets' => ['client_secret' => 'th-secret']]);

        $shown = collect($this->actingAs($user)->get(route('connections.index'))->viewData('page')['props']['connections'])->keyBy('name');

        $this->assertSame(["https://hooks.example.test/hooks/instagram/{$instagram->ref_id}", "https://hooks.example.test/hooks/threads/{$threads->ref_id}", null, null], [$shown['A']['hook']['url'], $shown['B']['hook']['url'], $shown['C']['hook'], $shown['D']['hook']]);
        // Each with the verify token Meta is to be given, its own
        $this->assertStringContainsString('verify token '.Meta::verifyToken($instagram).',', $shown['A']['hook']['hint']);
        $this->assertStringContainsString('verify token '.Meta::verifyToken($threads).',', $shown['B']['hook']['hint']);
    }

    public function test_meta_taking_a_sign_in_back_signs_the_connection_out_and_a_deletion_is_confirmed_where_anyone_may_read_it()
    {
        config(['services.workflows.public_url' => 'https://hooks.example.test', 'app.contact' => 'privacy@example.test']);
        $user = User::factory()->create();
        $instagram = Connection::factory()->instagram()->for($user)->create();
        $instagram->update(['settings' => [...$instagram->settings, 'scoped_id' => '3941']]);
        $threads = Connection::factory()->threads()->for($user)->create();
        $page = Connection::factory()->for($user)->create(['name' => 'Page', 'service' => 'facebook', 'settings' => ['client_id' => 'app-1', 'page_id' => '55', 'page_name' => 'Shop', 'signer' => '9001'], 'secrets' => ['client_secret' => 'app-secret', 'page_token' => 'page-token']]);
        // As Meta signs what it sends: what is said, and its signature before it, both in the base64 of addresses
        $part = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $signed = function (string $who, string $secret) use ($part) {
            $said = $part((string) json_encode(['algorithm' => 'HMAC-SHA256', 'issued_at' => 1791860000, 'user_id' => $who]));

            return $part(hash_hmac('sha256', $said, $secret, true)).'.'.$said;
        };
        $threadsSecret = (string) $threads->secret('client_secret');
        $threadsMember = (string) $threads->setting('member');

        // Signed by no app a connection here is of, or saying nothing: refused, and nothing is touched
        $this->post('/hooks/meta/deauthorize', ['signed_request' => $signed('1789', 'someone-elses')])->assertForbidden();
        $this->post('/hooks/meta/data-deletion', ['signed_request' => 'nonsense'])->assertForbidden();
        $this->assertSame('ig-token', $instagram->fresh()->secret('access_token'));

        // The app taken off the account on Instagram, which names it by its other id: signed out, what its owner typed kept
        $this->post('/hooks/meta/deauthorize', ['signed_request' => $signed('3941', 'ig-secret')])->assertOk();
        $instagram->refresh();
        $this->assertSame([null, null, null, 'ig-app', 'ig-secret'], [$instagram->setting('member'), $instagram->setting('scoped_id'), $instagram->secret('access_token'), $instagram->setting('client_id'), $instagram->secret('client_secret')]);
        $this->assertSame($threadsMember, $threads->fresh()->setting('member'));
        $this->assertDatabaseHas('audit_events', ['action' => 'connection.signed_out', 'subject_ref' => $instagram->ref_id, 'actor_id' => null]);

        // Whoever signed a Page in is known by the id Facebook gives the app for them
        $this->post('/hooks/meta/deauthorize', ['signed_request' => $signed('9001', 'app-secret')])->assertOk();
        $this->assertSame([null, null, 'app-secret'], [$page->fresh()->secret('page_token'), $page->fresh()->setting('page_id'), $page->fresh()->secret('client_secret')]);

        // A deletion asked for: signed out, what came in through it let go, and where to see that it was done given back
        DB::table('inbound_events')->insert([
            ['source' => "threads:{$threads->ref_id}", 'event_id' => 'reply:1', 'envelope' => '{}', 'received_at' => now()],
            ['source' => "instagram:{$instagram->ref_id}", 'event_id' => 'comment:1', 'envelope' => '{}', 'received_at' => now()],
        ]);
        $answer = $this->post('/hooks/meta/data-deletion', ['signed_request' => $signed($threadsMember, $threadsSecret)])->assertOk()->json();
        $this->assertSame("https://hooks.example.test/data-deletion/{$answer['confirmation_code']}", $answer['url']);
        $this->assertSame([null, null], [$threads->fresh()->setting('member'), $threads->fresh()->secret('access_token')]);
        $this->assertSame(["instagram:{$instagram->ref_id}"], DB::table('inbound_events')->pluck('source')->all());

        // That page is read without an account, by the receipt alone; one the app never gave is no page
        $this->get("/data-deletion/{$answer['confirmation_code']}")->assertOk()->assertInertia(fn (Assert $shown) => $shown
            ->component('legal/DataDeletion')
            ->where('done.receipt', $answer['confirmation_code'])
            ->where('done.removed', 1)
            ->where('contact', 'privacy@example.test'));
        $this->get('/data-deletion/1791860000-1-0123456789abcdef')->assertNotFound();

        // Asked again with nothing of theirs left, it is still Meta asking: answered, with nothing to remove
        $again = $this->post('/hooks/meta/data-deletion', ['signed_request' => $signed($threadsMember, $threadsSecret)])->assertOk()->json('confirmation_code');
        $this->get("/data-deletion/{$again}")->assertInertia(fn (Assert $shown) => $shown->where('done.removed', 0));

        // How the app treats what it is given, its terms, and how to have it deleted: for anyone to read
        $this->get('/privacy')->assertOk()->assertInertia(fn (Assert $shown) => $shown->component('legal/Privacy')->where('contact', 'privacy@example.test')->has('updated'));
        $this->get('/terms')->assertOk()->assertInertia(fn (Assert $shown) => $shown->component('legal/Terms'));
        $this->get('/data-deletion')->assertOk()->assertInertia(fn (Assert $shown) => $shown->component('legal/DataDeletion')->where('done', null));
    }

    public function test_a_whatsapp_number_is_tried_with_its_token_and_shows_where_meta_sends_its_messages()
    {
        config(['services.workflows.public_url' => 'https://hooks.example.test']);
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/v25.0/106540352242922*' => Http::response(['display_phone_number' => '+1 555 078 3881', 'verified_name' => 'Shop', 'id' => '106540352242922'])]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('connections.check'), ['service' => 'whatsapp', 'settings' => ['phone_number_id' => '106540352242922'], 'secrets' => ['access_token' => 'wa-token', 'app_secret' => 's']])
            ->assertJson(['ok' => true, 'message' => 'Works: this is +1 555 078 3881 (Shop).']);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer wa-token'));

        $number = Connection::factory()->whatsapp()->for($user)->create();
        $token = WhatsAppApi::verifyToken($number);

        $this->actingAs($user)->get(route('connections.index'))->assertInertia(fn (Assert $page) => $page
            ->where('connections.0.hook.url', "https://hooks.example.test/hooks/whatsapp/{$number->ref_id}")
            ->where('connections.0.hook.set', 'by_hand')
            ->where('connections.0.hook.hint', fn (string $hint) => str_contains($hint, $token) && strlen($token) === 32)
            ->where('connections.0.sign_in', null));
    }

    public function test_a_search_key_is_tried_for_nothing_and_says_what_is_left_of_it()
    {
        Http::preventStrayRequests();
        Http::fake([
            'serpapi.com/account.json*' => Http::sequence()
                ->push(['api_key' => 'serp-key', 'plan_name' => 'Free Plan', 'total_searches_left' => 240])
                ->push(['error' => 'Invalid API key. Your API key should be here: https://serpapi.com/manage-api-key'], 401),
            'api.tavily.com/usage' => Http::sequence()
                ->push(['key' => ['usage' => 150, 'limit' => null], 'account' => ['current_plan' => 'Researcher', 'plan_usage' => 150, 'plan_limit' => 1000]])
                ->push(['detail' => ['error' => 'Unauthorized: missing or invalid API key.']], 401),
        ]);
        $user = User::factory()->create();
        $try = fn (string $service, string $key) => $this->actingAs($user)->postJson(route('connections.check'), ['service' => $service, 'secrets' => ['api_key' => $key]]);

        $try('serpapi', 'serp-key')->assertExactJson(['ok' => true, 'message' => 'Works: 240 searches left (Free Plan).']);
        $try('serpapi', 'not-a-key')->assertExactJson(['ok' => false, 'message' => 'SerpApi does not know this key.']);
        $try('tavily', 'tvly-key')->assertExactJson(['ok' => true, 'message' => 'Works: 150 of 1,000 credits used (Researcher).']);
        $try('tavily', 'not-a-key')->assertExactJson(['ok' => false, 'message' => 'Tavily does not know this key.']);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer tvly-key'));

        // Kept under Search, with a key that is needed and never shown again
        $this->actingAs($user)->post(route('connections.store'), ['name' => 'Search', 'service' => 'serpapi', 'secrets' => ['api_key' => '']])->assertSessionHasErrors('secrets.api_key');
        Connection::factory()->tavily()->for($user)->create();

        $this->actingAs($user)->get(route('connections.index'))->assertInertia(fn (Assert $page) => $page
            ->where('services.serpapi.group', 'search')
            ->where('services.tavily.group', 'search')
            ->where('connections.0.saved_secrets', ['api_key'])
            ->where('connections.0.hook', null));
    }

    public function test_a_promptx_password_is_spent_on_signing_in_and_only_the_tokens_it_signs_in_to_are_kept()
    {
        $logins = [];
        Http::fake(function (Request $request) use (&$logins) {
            if (str_ends_with($request->url(), '/pmtx/login')) {
                $logins[] = $request->data();

                return $request['password'] === 'right'
                    ? Http::response(['access_token' => ConnectionFactory::jwt(['firstname' => 'Ada', 'lastname' => 'Lovelace', 'exp' => now()->addDays(30)->getTimestamp()]), 'refresh_token' => 'refresh-'.count($logins)])
                    // A wrong password is a 500 that says so
                    : Http::response(['error' => 'Invalid Username or Password'], 500);
            }

            return Http::response(['wfAuth' => ['accessToken' => 'flows', 'projectId' => 'usual']]);
        });
        $user = User::factory()->create();
        $typed = ['name' => 'PromptX', 'service' => 'promptx', 'settings' => ['login' => 'ada@example.com']];

        $this->actingAs($user)->post(route('connections.store'), $typed)->assertSessionHasErrors('secrets.password');
        $this->actingAs($user)->post(route('connections.store'), [...$typed, 'secrets' => ['password' => 'wrong']])
            ->assertSessionHasErrors(['secrets.password' => 'PromptX did not take that email and password.']);
        $this->assertSame(0, $user->connections()->count());

        // Tried before it is kept: signed in, and nothing kept
        $this->actingAs($user)->postJson(route('connections.check'), [...$typed, 'secrets' => ['password' => 'right']])
            ->assertExactJson(['ok' => true, 'message' => 'Works: signed in as Ada Lovelace.']);
        $this->assertSame(0, $user->connections()->count());

        $this->actingAs($user)->post(route('connections.store'), [...$typed, 'secrets' => ['password' => 'right']])->assertSessionHasNoErrors();
        $connection = $user->connections()->sole();
        $this->assertSame(['access_token', 'refresh_token'], array_keys($connection->secrets));
        $this->assertSame('refresh-3', $connection->secret('refresh_token'));
        $this->assertSame(['login' => 'ada@example.com'], $connection->settings);
        $this->assertSame(['grant_type' => 'password', 'username' => 'ada@example.com', 'password' => 'right'], array_intersect_key($logins[2], array_flip(['grant_type', 'username', 'password'])));

        // Signed in, it is changed without the password, and asked of with what it holds
        $this->actingAs($user)->patch(route('connections.update', $connection), [...$typed, 'name' => 'Work'])->assertSessionHasNoErrors();
        $this->assertSame(['Work', 'refresh-3'], [$connection->fresh()->name, $connection->fresh()->secret('refresh_token')]);
        $this->actingAs($user)->getJson(route('connections.test', $connection))->assertJson(['ok' => true]);
        $this->assertCount(3, $logins);

        // Typed again, it signs in again
        $this->actingAs($user)->patch(route('connections.update', $connection), [...$typed, 'secrets' => ['password' => 'right']])->assertSessionHasNoErrors();
        $this->assertSame('refresh-4', $connection->fresh()->secret('refresh_token'));
        $this->assertStringNotContainsString('right', (string) json_encode($connection->fresh()->secrets));

        $this->actingAs($user)->get(route('connections.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('services.promptx.secrets.0.spent', true)
                ->where('connections.0.saved_secrets', ['access_token', 'refresh_token']));
    }
}
