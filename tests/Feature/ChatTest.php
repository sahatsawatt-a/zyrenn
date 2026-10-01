<?php

namespace Tests\Feature;

use App\Models\Chat\AiConnection;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\User;
use App\Support\Chat\ChatEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    /**
     * What a host streams back for an answer in these words, as chat completions do.
     *
     * @param  list<string>  $words
     */
    private function answer(array $words, bool $done = true): string
    {
        $lines = array_map(fn (string $word) => 'data: '.json_encode(['choices' => [['delta' => ['content' => $word]]]])."\n\n", $words);

        // Hosts also send events that carry no words, such as the one that opens the answer
        array_unshift($lines, 'data: '.json_encode(['choices' => [['delta' => ['role' => 'assistant', 'content' => '']]]])."\n\n");

        return implode('', $lines).($done ? "data: [DONE]\n\n" : '');
    }

    /**
     * A room answered through a connection, as it is set up when the user has made one.
     */
    private function room(User $user, array $room = []): ChatRoom
    {
        $connection = AiConnection::factory()->for($user)->create();

        return ChatRoom::factory()->for($user)->create([
            'ai_connection_id' => $connection->id,
            ...$room,
        ]);
    }

    /**
     * Say something in a room, and what came back as events: [name, data] each.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function say(User $user, ChatRoom $room, string $content): array
    {
        $body = $this->actingAs($user)
            ->post(route('chats.messages.store', $room), ['content' => $content])
            ->assertOk()
            ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
            ->streamedContent();

        preg_match_all('/event: (\w+)\ndata: (.*)\n\n/', $body, $found, PREG_SET_ORDER);

        return array_map(fn (array $event) => [$event[1], json_decode($event[2], true)], $found);
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('chats.index'))->assertRedirect(route('login'));
    }

    public function test_the_list_shows_only_the_users_own_rooms_and_who_answers()
    {
        $user = User::factory()->create();
        $this->room($user, ['title' => 'Mine', 'model' => 'llama3']);
        ChatRoom::factory()->for($user)->create(['title' => 'Alone']);
        ChatRoom::factory()->create(['title' => 'Someone else']);

        $this->actingAs($user)
            ->get(route('chats.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('chats/Index')
                ->has('rooms', 2)
                ->where('rooms.1.title', 'Mine')
                ->where('rooms.1.agent', 'Local Ollama · llama3')
                ->where('rooms.0.agent', null));
    }

    public function test_a_new_room_is_answered_by_the_connection_made_last()
    {
        $user = User::factory()->create();
        AiConnection::factory()->for($user)->create();
        $latest = AiConnection::factory()->openrouter()->for($user)->create();

        $this->actingAs($user)->post(route('chats.store'))->assertRedirect();

        $room = $user->chatRooms()->sole();
        $this->assertSame($latest->id, $room->ai_connection_id);
        $this->assertSame('', $room->title);
    }

    public function test_a_room_opens_with_what_was_said_and_never_a_key()
    {
        $user = User::factory()->create();
        $room = $this->room($user, ['title' => 'Hello']);
        $room->messages()->create(['role' => 'user', 'user_id' => $user->id, 'content' => 'Hi']);
        $room->messages()->create(['role' => 'assistant', 'content' => 'Hello!']);

        $this->actingAs($user)
            ->get(route('chats.show', $room))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('chats/Show')
                ->where('room.ref_id', $room->ref_id)
                ->where('room.ready', true)
                ->has('messages', 2)
                ->where('messages.1.content', 'Hello!')
                ->has('connections', 1)
                ->missing('connections.0.api_key')
                ->missing('connections.0.base_url'));
    }

    public function test_rooms_are_addressed_by_ref_id_not_numeric_id()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->for($user)->create();

        $this->actingAs($user)->get('/chats/'.$room->id)->assertNotFound();
        $this->actingAs($user)->get('/chats/'.$room->ref_id)->assertOk();
    }

    public function test_another_users_room_cannot_be_reached()
    {
        $user = User::factory()->create();
        $theirs = $this->room(User::factory()->create());
        Http::fake();

        $this->actingAs($user)->get(route('chats.show', $theirs))->assertForbidden();
        $this->actingAs($user)->patch(route('chats.update', $theirs), ['title' => 'Mine'])->assertForbidden();
        $this->actingAs($user)->delete(route('chats.destroy', $theirs))->assertForbidden();
        $this->actingAs($user)->post(route('chats.messages.store', $theirs), ['content' => 'hi'])->assertForbidden();

        $this->assertSame(0, $theirs->messages()->count());
        Http::assertNothingSent();
    }

    public function test_a_room_is_set_up_with_a_connection_model_and_prompt_of_the_users_own()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->for($user)->create();
        $connection = AiConnection::factory()->for($user)->create();

        $this->actingAs($user)
            ->patch(route('chats.update', $room), [
                'title' => 'Plans',
                'connection' => $connection->ref_id,
                'model' => 'qwen2.5:7b',
                'system_prompt' => 'Answer briefly.',
            ])
            ->assertSessionHasNoErrors();

        $room->refresh();
        $this->assertSame('Plans', $room->title);
        $this->assertSame($connection->id, $room->ai_connection_id);
        $this->assertSame('Answer briefly.', $room->system_prompt);
        $this->assertTrue($room->hasAgent());

        // Taking the agent away leaves the room as a plain log
        $this->actingAs($user)->patch(route('chats.update', $room), ['connection' => null]);
        $this->assertFalse($room->fresh()->hasAgent());
    }

    public function test_a_room_cannot_be_answered_through_another_users_connection()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->for($user)->create();
        $theirs = AiConnection::factory()->openrouter()->create();

        $this->actingAs($user)
            ->patch(route('chats.update', $room), ['connection' => $theirs->ref_id])
            ->assertSessionHasErrors('connection');

        $this->assertNull($room->fresh()->ai_connection_id);
    }

    public function test_a_room_answers_what_it_is_told_from_its_own_agent_and_keeps_both()
    {
        Http::fake(['ollama.test:11434/*' => Http::response($this->answer(['Hel', 'lo ', 'there.']), 200, ['Content-Type' => 'text/event-stream'])]);
        $user = User::factory()->create();
        $room = $this->room($user, ['model' => 'llama3', 'system_prompt' => 'Be brief.']);

        $events = $this->say($user, $room, 'Say hello');

        $this->assertSame(['user', 'delta', 'delta', 'delta', 'done'], array_column($events, 0));
        $this->assertSame('Say hello', $events[0][1]['message']['content']);
        $this->assertSame(['Hel', 'lo ', 'there.'], array_column(array_column(array_slice($events, 1, 3), 1), 'text'));
        $this->assertSame('Hello there.', $events[4][1]['message']['content']);

        $this->assertSame(
            [['user', 'Say hello'], ['assistant', 'Hello there.']],
            $room->messages()->get()->map(fn (ChatMessage $m) => [$m->role, $m->content])->all(),
        );
        $this->assertSame($user->id, $room->messages()->first()->user_id);
        $this->assertNull($room->messages()->reorder('id', 'desc')->first()->user_id);
        $this->assertSame('llama3', $room->messages()->reorder('id', 'desc')->first()->meta['model']);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'http://ollama.test:11434/v1/chat/completions'
                && $request['model'] === 'llama3'
                && $request['stream'] === true
                && $request['messages'] === [
                    ['role' => 'system', 'content' => 'Be brief.'],
                    ['role' => 'user', 'content' => 'Say hello'],
                ]
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_a_hosted_connection_is_called_with_the_users_key_and_its_default_model()
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->answer(['Yes.']))]);
        $user = User::factory()->create();
        $connection = AiConnection::factory()->openrouter()->for($user)->create(['default_model' => 'openai/gpt-4o-mini']);
        $room = ChatRoom::factory()->for($user)->create(['ai_connection_id' => $connection->id]);

        $this->say($user, $room, 'Is this on?');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $request['model'] === 'openai/gpt-4o-mini'
            && $request->hasHeader('Authorization', 'Bearer sk-or-secret'));
    }

    public function test_the_model_is_given_the_conversation_so_far_but_only_its_latest_part()
    {
        Http::fake(['*' => Http::response($this->answer(['ok']))]);
        $user = User::factory()->create();
        $room = $this->room($user);

        foreach (range(1, ChatEngine::HISTORY + 10) as $n) {
            $room->messages()->create(['role' => $n % 2 ? 'user' : 'assistant', 'user_id' => $n % 2 ? $user->id : null, 'content' => "m{$n}"]);
        }

        $this->say($user, $room, 'latest');

        Http::assertSent(function (Request $request) {
            $messages = $request['messages'];

            return count($messages) === ChatEngine::HISTORY
                && $messages[0]['content'] === 'm'.(50 + 1 - ChatEngine::HISTORY + 1)
                && end($messages)['content'] === 'latest';
        });
    }

    public function test_the_first_message_names_a_room_that_has_no_name()
    {
        Http::fake(['*' => Http::response($this->answer(['ok']))]);
        $user = User::factory()->create();
        $room = $this->room($user, ['title' => '']);

        $events = $this->say($user, $room, "  Plan   my\nweek  ");

        $this->assertSame('Plan my week', $room->fresh()->title);
        $this->assertSame('Plan my week', $events[0][1]['title']);

        $this->say($user, $room, 'Something else');
        $this->assertSame('Plan my week', $room->fresh()->title);
    }

    public function test_a_room_without_an_agent_only_keeps_what_is_said()
    {
        Http::fake();
        $user = User::factory()->create();
        $room = ChatRoom::factory()->for($user)->create();

        $events = $this->say($user, $room, 'Note to self');

        $this->assertSame(['user', 'done'], array_column($events, 0));
        $this->assertSame(1, $room->messages()->count());
        Http::assertNothingSent();
    }

    public function test_a_connection_with_no_model_to_ask_for_is_no_agent()
    {
        Http::fake();
        $user = User::factory()->create();
        $connection = AiConnection::factory()->for($user)->create(['default_model' => null]);
        $room = ChatRoom::factory()->for($user)->create(['ai_connection_id' => $connection->id]);

        $events = $this->say($user, $room, 'Anyone there?');

        $this->assertSame(['user', 'done'], array_column($events, 0));
        Http::assertNothingSent();
    }

    public function test_a_host_that_fails_before_answering_leaves_only_the_users_message()
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'model "nope" not found']], 404)]);
        $user = User::factory()->create();
        $room = $this->room($user, ['model' => 'nope']);

        $events = $this->say($user, $room, 'Hello?');

        $this->assertSame(['user', 'error'], array_column($events, 0));
        $this->assertSame('model "nope" not found', $events[1][1]['message']);
        $this->assertSame(['user'], $room->messages()->pluck('role')->all());
    }

    public function test_a_host_that_fails_partway_keeps_what_it_had_said_and_why_it_stopped()
    {
        $partial = $this->answer(['Half of ', 'an answer'], false).'data: '.json_encode(['error' => ['message' => 'Provider disconnected']])."\n\n";
        Http::fake(['*' => Http::response($partial)]);
        $user = User::factory()->create();
        $room = $this->room($user);

        $events = $this->say($user, $room, 'Go on');

        $this->assertSame('error', end($events)[0]);
        $this->assertSame('Provider disconnected', end($events)[1]['message']);

        $kept = $room->messages()->reorder('id', 'desc')->first();
        $this->assertSame('assistant', $kept->role);
        $this->assertSame('Half of an answer', $kept->content);
        $this->assertSame('Provider disconnected', $kept->toChat()['error']);
    }

    public function test_an_unreachable_host_is_an_error_event_not_a_crash()
    {
        Http::fake(fn () => throw new ConnectionException('refused'));
        $user = User::factory()->create();
        $room = $this->room($user);

        $events = $this->say($user, $room, 'Hello?');

        $this->assertSame('Could not reach ollama.test.', end($events)[1]['message']);
    }

    public function test_an_answer_comes_as_the_note_editors_document_without_pictures_from_elsewhere()
    {
        $markdown = "# Plan\n\n- one\n- two\n\n```php\necho 1;\n```\n\n![tracker](https://evil.test/p.png?q=secret)\n\n![mine](/drive/files/k3x9m2p7qa)";
        Http::fake(['*' => Http::response($this->answer([$markdown]))]);
        $user = User::factory()->create();
        $room = $this->room($user);

        $events = $this->say($user, $room, 'Plan it');
        $doc = end($events)[1]['message']['doc'];

        $types = array_column($doc['content'], 'type');
        $this->assertSame(['heading', 'bulletList', 'codeBlock', 'paragraph', 'image'], $types);
        $this->assertSame('[Picture not shown: https://evil.test/p.png?q=secret]', $doc['content'][3]['content'][0]['text']);
        $this->assertSame('/drive/files/k3x9m2p7qa', $doc['content'][4]['attrs']['src']);

        // What a person typed is theirs, as typed
        $this->assertNull($events[0][1]['message']['doc']);
    }

    public function test_something_must_be_said()
    {
        $user = User::factory()->create();
        $room = $this->room($user);

        $this->actingAs($user)->postJson(route('chats.messages.store', $room), ['content' => ''])->assertUnprocessable();
        $this->actingAs($user)->postJson(route('chats.messages.store', $room), ['content' => str_repeat('a', 20001)])->assertUnprocessable();

        $this->assertSame(0, $room->messages()->count());
    }

    public function test_deleting_a_room_deletes_what_was_said_in_it()
    {
        $user = User::factory()->create();
        $room = $this->room($user);
        $room->messages()->create(['role' => 'user', 'user_id' => $user->id, 'content' => 'hi']);

        $this->actingAs($user)->delete(route('chats.destroy', $room))->assertRedirect(route('chats.index'));

        $this->assertDatabaseCount('chat_rooms', 0);
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_rooms_connections_and_messages_are_deleted_with_their_user()
    {
        $user = User::factory()->create();
        $room = $this->room($user);
        $room->messages()->create(['role' => 'user', 'user_id' => $user->id, 'content' => 'hi']);

        $user->delete();

        $this->assertDatabaseCount('chat_rooms', 0);
        $this->assertDatabaseCount('chat_messages', 0);
        $this->assertDatabaseCount('ai_connections', 0);
    }
}
