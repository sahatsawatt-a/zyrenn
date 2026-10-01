<?php

namespace Tests\Feature;

use App\Models\Chat\AiConnection;
use App\Models\Chat\ChatRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AiConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('ai-connections.index'))->assertRedirect(route('login'));
    }

    public function test_the_page_lists_only_the_users_own_and_never_their_key()
    {
        $user = User::factory()->create();
        AiConnection::factory()->openrouter()->for($user)->create(['name' => 'Mine']);
        AiConnection::factory()->create(['name' => 'Someone else']);

        $this->actingAs($user)
            ->get(route('ai-connections.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/AiConnections')
                ->has('connections', 1)
                ->where('connections.0.name', 'Mine')
                ->where('connections.0.has_key', true)
                ->missing('connections.0.api_key'));
    }

    public function test_a_machine_of_the_users_own_needs_no_key_and_a_blank_host_means_the_usual_one()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('ai-connections.store'), ['name' => 'Desk', 'kind' => 'ollama', 'base_url' => '', 'default_model' => 'qwen2.5:7b'])
            ->assertRedirect(route('ai-connections.index'));

        $connection = $user->aiConnections()->sole();
        $this->assertSame('http://localhost:11434', $connection->base_url);
        $this->assertNull($connection->api_key);
    }

    public function test_a_hosted_service_needs_a_key_and_the_key_is_kept_encrypted()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('ai-connections.store'), ['name' => 'OR', 'kind' => 'openrouter'])
            ->assertSessionHasErrors('api_key');

        $this->actingAs($user)
            ->post(route('ai-connections.store'), ['name' => 'OR', 'kind' => 'openrouter', 'api_key' => 'sk-or-secret'])
            ->assertSessionHasNoErrors();

        $connection = $user->aiConnections()->sole();
        $this->assertSame('https://openrouter.ai/api/v1', $connection->base_url);
        $this->assertSame('sk-or-secret', $connection->api_key);
        $this->assertStringNotContainsString('sk-or-secret', DB::table('ai_connections')->value('api_key'));
    }

    public function test_only_a_web_address_is_taken_as_a_host_and_only_a_known_kind()
    {
        $user = User::factory()->create();

        foreach (['ftp://x.test', 'file:///etc/passwd', 'javascript:alert(1)', 'not a url'] as $bad) {
            $this->actingAs($user)
                ->post(route('ai-connections.store'), ['name' => 'X', 'kind' => 'ollama', 'base_url' => $bad])
                ->assertSessionHasErrors('base_url');
        }

        $this->actingAs($user)
            ->post(route('ai-connections.store'), ['name' => 'X', 'kind' => 'skynet'])
            ->assertSessionHasErrors('kind');

        $this->assertSame(0, $user->aiConnections()->count());
    }

    public function test_a_blank_key_on_save_keeps_the_one_there()
    {
        $user = User::factory()->create();
        $connection = AiConnection::factory()->openrouter()->for($user)->create();

        $this->actingAs($user)
            ->patch(route('ai-connections.update', $connection), ['name' => 'Renamed', 'kind' => 'openrouter', 'api_key' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $connection->fresh()->name);
        $this->assertSame('sk-or-secret', $connection->fresh()->api_key);

        $this->actingAs($user)
            ->patch(route('ai-connections.update', $connection), ['name' => 'Renamed', 'kind' => 'openrouter', 'api_key' => 'sk-or-new']);

        $this->assertSame('sk-or-new', $connection->fresh()->api_key);
    }

    public function test_another_users_connection_cannot_be_touched()
    {
        $user = User::factory()->create();
        $theirs = AiConnection::factory()->openrouter()->create();

        $this->actingAs($user)->patch(route('ai-connections.update', $theirs), ['name' => 'Mine', 'kind' => 'ollama'])->assertForbidden();
        $this->actingAs($user)->delete(route('ai-connections.destroy', $theirs))->assertForbidden();
        $this->actingAs($user)->getJson(route('ai-connections.models', $theirs))->assertForbidden();

        $this->assertSame('sk-or-secret', $theirs->fresh()->api_key);
    }

    public function test_removing_a_connection_leaves_its_rooms_without_an_agent()
    {
        $user = User::factory()->create();
        $connection = AiConnection::factory()->for($user)->create();
        $room = ChatRoom::factory()->for($user)->create(['ai_connection_id' => $connection->id, 'model' => 'qwen2.5:7b']);

        $this->actingAs($user)->delete(route('ai-connections.destroy', $connection))->assertRedirect();

        $this->assertNull($room->fresh()->ai_connection_id);
        $this->assertFalse($room->fresh()->hasAgent());
    }

    public function test_the_models_a_host_offers_are_listed_asked_for_with_the_key()
    {
        Http::fake(['openrouter.ai/*' => Http::response(['data' => [['id' => 'z/model'], ['id' => 'a/model'], ['nope' => 1]]])]);
        $user = User::factory()->create();
        $connection = AiConnection::factory()->openrouter()->for($user)->create();

        $this->actingAs($user)
            ->getJson(route('ai-connections.models', $connection))
            ->assertOk()
            ->assertExactJson(['models' => ['a/model', 'z/model'], 'free' => null]);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://openrouter.ai/api/v1/models'
            && $request->hasHeader('Authorization', 'Bearer sk-or-secret'));
    }

    public function test_ollama_is_asked_under_v1_whether_or_not_the_host_says_so()
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 'qwen2.5:7b']]])]);
        $user = User::factory()->create();

        foreach (['http://ollama.test:11434', 'http://ollama.test:11434/', 'http://ollama.test:11434/v1'] as $host) {
            $connection = AiConnection::factory()->for($user)->create(['base_url' => $host]);

            $this->actingAs($user)->getJson(route('ai-connections.models', $connection))->assertOk();
        }

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://ollama.test:11434/v1/models');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/v1/v1'));
    }

    public function test_a_host_that_fails_is_reported_in_our_words_not_its_own_body()
    {
        $user = User::factory()->create();
        $connection = AiConnection::factory()->for($user)->create();

        Http::fake(['*' => Http::sequence()
            ->push('<html>internal-admin-panel secret</html>', 500)
            ->push(['error' => ['message' => 'No auth credentials found']], 401)
            ->push('', 302, ['Location' => 'http://169.254.169.254/'])]);

        $this->actingAs($user)->getJson(route('ai-connections.models', $connection))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'The host answered with 500.']);

        $this->actingAs($user)->getJson(route('ai-connections.models', $connection))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'The host refused the key.']);

        $this->actingAs($user)->getJson(route('ai-connections.models', $connection))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'The host sent us somewhere else.']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '169.254'));
    }

    public function test_a_host_that_cannot_be_reached_is_named()
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: refused'));
        $user = User::factory()->create();
        $connection = AiConnection::factory()->for($user)->create();

        $this->actingAs($user)->getJson(route('ai-connections.models', $connection))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Could not reach ollama.test.']);
    }

    public function test_any_host_that_speaks_the_dialect_can_be_connected_not_only_two()
    {
        $user = User::factory()->create();

        // A hosted kind falls back to its usual address and wants a key
        $this->actingAs($user)
            ->post(route('ai-connections.store'), ['name' => 'GPT', 'kind' => 'openai'])
            ->assertSessionHasErrors('api_key');
        $this->actingAs($user)
            ->post(route('ai-connections.store'), ['name' => 'GPT', 'kind' => 'openai', 'api_key' => 'sk-x']);
        $this->assertSame('https://api.openai.com/v1', $user->aiConnections()->where('name', 'GPT')->value('base_url'));

        // "Other" has no usual address, so one must be given, and needs no key
        $this->actingAs($user)
            ->post(route('ai-connections.store'), ['name' => 'Gateway', 'kind' => 'custom'])
            ->assertSessionHasErrors('base_url');
        $this->actingAs($user)
            ->post(route('ai-connections.store'), ['name' => 'Gateway', 'kind' => 'custom', 'base_url' => 'https://llm.example.test/v1'])
            ->assertSessionHasNoErrors();

        // A local server is given without its /v1, as Ollama is
        Http::fake(['*' => Http::response(['data' => []])]);
        $studio = AiConnection::factory()->for($user)->create(['kind' => 'lmstudio', 'base_url' => 'http://studio.test:1234']);
        $this->actingAs($user)->getJson(route('ai-connections.models', $studio))->assertOk();
        Http::assertSent(fn (Request $request) => $request->url() === 'http://studio.test:1234/v1/models');

        // ...while one that is "other" is asked exactly where it was said to be
        $gateway = $user->aiConnections()->where('name', 'Gateway')->sole();
        $this->actingAs($user)->getJson(route('ai-connections.models', $gateway))->assertOk();
        Http::assertSent(fn (Request $request) => $request->url() === 'https://llm.example.test/v1/models');
        $this->assertSame('Other', $gateway->label());
    }

    public function test_the_form_is_tried_before_it_is_saved_and_nothing_is_kept()
    {
        Http::fake(['openrouter.ai/*' => Http::response(['data' => [['id' => 'b/m'], ['id' => 'a/m']]])]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('ai-connections.check'), ['kind' => 'openrouter', 'api_key' => 'sk-or-typed'])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'models' => ['a/m', 'b/m'], 'free' => null]);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://openrouter.ai/api/v1/models'
            && $request->hasHeader('Authorization', 'Bearer sk-or-typed'));
        $this->assertSame(0, $user->aiConnections()->count());
    }

    public function test_a_failed_try_says_why()
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'bad key']], 401)]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('ai-connections.check'), ['kind' => 'openai', 'api_key' => 'nope'])
            ->assertOk()
            ->assertExactJson(['ok' => false, 'message' => 'The host refused the key.']);

        // "Other" with no address has nowhere to try
        $this->actingAs($user)
            ->postJson(route('ai-connections.check'), ['kind' => 'custom'])
            ->assertOk()
            ->assertExactJson(['ok' => false, 'message' => 'Give the address of the host.']);

        $this->actingAs($user)
            ->postJson(route('ai-connections.check'), ['kind' => 'custom', 'base_url' => 'ftp://x.test'])
            ->assertJsonValidationErrors('base_url');
    }

    public function test_changing_a_saved_connection_is_tried_with_its_own_key_when_none_is_typed()
    {
        Http::fake(['*' => Http::response(['data' => []])]);
        $user = User::factory()->create();
        $mine = AiConnection::factory()->openrouter()->for($user)->create();

        $this->actingAs($user)
            ->postJson(route('ai-connections.check'), ['kind' => 'openrouter', 'connection' => $mine->ref_id])
            ->assertOk();

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer sk-or-secret'));
    }

    public function test_another_users_key_is_never_borrowed_to_try_a_form()
    {
        Http::fake();
        $user = User::factory()->create();
        $theirs = AiConnection::factory()->openrouter()->create();

        $this->actingAs($user)
            ->postJson(route('ai-connections.check'), ['kind' => 'openrouter', 'connection' => $theirs->ref_id])
            ->assertJsonValidationErrors('connection');

        Http::assertNothingSent();
    }

    public function test_guests_cannot_have_the_app_try_a_host()
    {
        Http::fake();

        $this->postJson(route('ai-connections.check'), ['kind' => 'ollama'])->assertUnauthorized();

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
        $connection = AiConnection::factory()->openrouter()->for($user)->create();

        $this->actingAs($user)
            ->getJson(route('ai-connections.models', $connection))
            ->assertOk()
            ->assertJsonPath('free', ['free/model:free', 'untagged/free'])
            ->assertJsonCount(6, 'models');

        // Trying the form says the same
        $this->actingAs($user)
            ->postJson(route('ai-connections.check'), ['kind' => 'openrouter', 'api_key' => 'k'])
            ->assertJsonPath('free', ['free/model:free', 'untagged/free']);
    }

    public function test_a_host_that_gives_no_prices_has_no_free_list_rather_than_an_empty_one()
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 'qwen2.5:7b'], ['id' => 'llama3']]])]);
        $user = User::factory()->create();
        $connection = AiConnection::factory()->for($user)->create();

        $this->actingAs($user)
            ->getJson(route('ai-connections.models', $connection))
            ->assertJsonPath('free', null)
            ->assertJsonPath('models', ['llama3', 'qwen2.5:7b']);
    }

    public function test_localhost_in_a_connection_reaches_the_machine_not_the_container()
    {
        config(['services.chat.localhost_as' => '172.16.8.1']);
        Http::fake(['*' => Http::response(['data' => []])]);
        $user = User::factory()->create();
        $connection = AiConnection::factory()->for($user)->create(['base_url' => 'http://localhost:11434']);

        $this->actingAs($user)->getJson(route('ai-connections.models', $connection))->assertOk();
        $this->actingAs($user)
            ->postJson(route('ai-connections.check'), ['kind' => 'ollama', 'base_url' => 'http://127.0.0.1:11434'])
            ->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://172.16.8.1:11434/v1/models');
        // What the user typed is what is kept and shown
        $this->assertSame('http://localhost:11434', $connection->fresh()->base_url);
    }
}
