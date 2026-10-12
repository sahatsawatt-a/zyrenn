<?php

namespace Tests\Feature;

use App\Models\Chat\ChatRoom;
use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
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
}
