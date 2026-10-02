<?php

namespace Tests\Feature;

use App\Events\ChatMessagePosted;
use App\Models\Chat\AiConnection;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Project;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PeopleChatTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A project with these people in it, the first as its owner.
     */
    private function project(User $owner, User ...$others): Project
    {
        $project = Project::factory()->withMember($owner)->create();

        foreach ($others as $other) {
            $project->members()->attach($other, ['role' => Project::VIEWER]);
        }

        return $project;
    }

    /**
     * A group in the project with everyone in it, started by its first member.
     */
    private function group(Project $project, string $title = 'Team'): ChatRoom
    {
        $people = $project->members()->orderBy('project_user.id')->get();

        return ChatRoom::startGroup($project, $people->first(), $title, $people->pluck('id'));
    }

    /**
     * Say something in a room, as the page does, and answer with what came back.
     */
    private function say(User $user, ChatRoom $room, string $content): string
    {
        return $this->actingAs($user)
            ->post(route('chats.messages.store', $room), ['content' => $content])
            ->assertOk()
            ->streamedContent();
    }

    public function test_two_people_in_a_project_share_one_room_whoever_opens_it()
    {
        [$ada, $grace] = User::factory()->count(2)->create();
        $this->project($ada, $grace);

        $this->actingAs($ada)->post(route('chats.direct'), ['user' => $grace->id])->assertRedirect();
        $this->actingAs($grace)->post(route('chats.direct'), ['user' => $ada->id])->assertRedirect();

        $room = ChatRoom::query()->sole();
        $this->assertSame(ChatRoom::DIRECT, $room->kind);
        $this->assertNull($room->user_id);
        $this->assertEqualsCanonicalizing([$ada->id, $grace->id], $room->members()->pluck('users.id')->all());

        $this->actingAs($ada)->get(route('chats.show', $room))
            ->assertInertia(fn (Assert $page) => $page
                ->where('room.kind', 'direct')
                ->where('room.title', $grace->name)
                ->where('room.mine', false)
                ->has('people', 2)
                ->where('connections', []));
    }

    public function test_only_someone_shared_a_project_with_can_be_reached()
    {
        [$ada, $stranger] = User::factory()->count(2)->create();
        $this->project($ada);

        $this->actingAs($ada)->post(route('chats.direct'), ['user' => $stranger->id])->assertSessionHasErrors('user');
        $this->actingAs($ada)->post(route('chats.direct'), ['user' => $ada->id])->assertSessionHasErrors('user');
        $this->actingAs($ada)->post(route('chats.direct'), ['user' => 99999])->assertSessionHasErrors('user');

        $this->assertDatabaseCount('chat_rooms', 0);
    }

    public function test_the_list_offers_only_people_shared_a_project_with()
    {
        [$ada, $grace, $stranger] = User::factory()->count(3)->create();
        $this->project($ada, $grace);
        $this->project($stranger);

        $this->actingAs($ada)->get(route('messages.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('messages/Index')
                ->has('people', 1)
                ->where('people.0.id', $grace->id));
    }

    public function test_a_direct_room_is_closed_to_everyone_else()
    {
        [$ada, $grace, $eve] = User::factory()->count(3)->create();
        $this->project($ada, $grace, $eve);
        $room = ChatRoom::between($ada, $grace);
        $room->messages()->create(['role' => 'user', 'user_id' => $ada->id, 'content' => 'secret']);

        $this->actingAs($eve)->get(route('chats.show', $room))->assertForbidden();
        $this->actingAs($eve)->post(route('chats.messages.store', $room), ['content' => 'hi'])->assertForbidden();
        $this->actingAs($eve)->getJson(route('chats.messages.index', $room))->assertForbidden();
        $this->actingAs($eve)->postJson(route('chats.read', $room), ['message' => 1])->assertForbidden();

        $this->assertSame(1, $room->messages()->count());
    }

    public function test_anyone_in_a_project_starts_a_group_with_people_chosen_from_it()
    {
        [$owner, $viewer, $other, $outsider] = User::factory()->count(4)->create();
        $project = $this->project($owner, $viewer, $other);

        // A viewer may talk: the role is about the project's content, not its conversation
        $this->actingAs($viewer)
            ->post(route('projects.chat.store', $project), ['title' => '  Launch  ', 'people' => [$owner->id]])
            ->assertRedirect();

        $room = ChatRoom::query()->sole();
        $this->assertSame(ChatRoom::GROUP, $room->kind);
        $this->assertSame('Launch', $room->title);
        $this->assertSame($viewer->id, $room->created_by);
        $this->assertEqualsCanonicalizing([$viewer->id, $owner->id], $room->members()->pluck('users.id')->all());

        $this->say($viewer, $room, 'Hello all');
        $this->say($owner, $room, 'Hi!');

        $this->actingAs($owner)->get(route('chats.show', $room))
            ->assertInertia(fn (Assert $page) => $page
                ->where('room.title', 'Launch')
                ->where('room.project.name', $project->name)
                ->where('room.manage', false)
                ->where('room.leave', true)
                ->has('people', 2)
                ->where('messages.0.author.name', $viewer->name)
                ->where('messages.1.author.id', $owner->id));

        // Not chosen, so not in it -- until they join
        $this->actingAs($other)->get(route('chats.show', $room))->assertForbidden();
        $this->actingAs($other)->post(route('chats.messages.store', $room), ['content' => 'hey'])->assertForbidden();
        $this->actingAs($outsider)->get(route('projects.chat', $project))->assertForbidden();
    }

    public function test_a_project_can_have_many_groups()
    {
        $owner = User::factory()->create();
        $project = $this->project($owner);

        foreach (['Design', 'Launch', 'Random'] as $title) {
            $this->actingAs($owner)->post(route('projects.chat.store', $project), ['title' => $title]);
        }

        $this->assertSame(3, $project->chatRooms()->count());
        $this->actingAs($owner)->get(route('projects.chat', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->component('chats/Groups')
                ->has('groups', 3)
                ->where('groups.0.joined', true));
    }

    public function test_only_people_in_the_project_can_be_chosen_or_join()
    {
        [$owner, $outsider] = User::factory()->count(2)->create();
        $project = $this->project($owner);
        $this->project($outsider);

        $this->actingAs($owner)
            ->post(route('projects.chat.store', $project), ['title' => 'X', 'people' => [$outsider->id]])
            ->assertSessionHasErrors('people.0');
        $this->actingAs($owner)
            ->post(route('projects.chat.store', $project), ['title' => ''])
            ->assertSessionHasErrors('title');
        $this->assertSame(0, $project->chatRooms()->count());

        $room = ChatRoom::startGroup($project, $owner, 'Mine');
        $this->actingAs($outsider)->post(route('chats.join', $room))->assertForbidden();
        $this->assertFalse($room->members()->whereKey($outsider->id)->exists());
    }

    public function test_someone_in_the_project_sees_the_groups_they_are_not_in_and_joins_one()
    {
        [$owner, $member] = User::factory()->count(2)->create();
        $project = $this->project($owner, $member);
        $room = ChatRoom::startGroup($project, $owner, 'Design');
        $room->messages()->create(['role' => 'user', 'user_id' => $owner->id, 'content' => 'before you came']);

        $this->actingAs($member)->get(route('projects.chat', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('groups.0.title', 'Design')
                ->where('groups.0.joined', false)
                // What one is not in is not one's to have read
                ->where('groups.0.unread', 0)
                ->where('people.0.id', $owner->id));
        $this->assertSame(0, ChatRoom::unreadTotal($member));

        $this->actingAs($member)->post(route('chats.join', $room))->assertRedirect(route('chats.show', $room));
        $this->actingAs($member)->get(route('chats.show', $room))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('messages.0.content', 'before you came'));

        // Joining twice is no more joined
        $this->actingAs($member)->post(route('chats.join', $room))->assertForbidden();
        $this->assertSame(2, $room->members()->count());
    }

    public function test_leaving_a_group_closes_it_until_joined_again()
    {
        [$owner, $member] = User::factory()->count(2)->create();
        $project = $this->project($owner, $member);
        $room = $this->group($project);

        $this->actingAs($member)->post(route('chats.leave', $room))->assertRedirect(route('projects.chat', $project));
        $this->actingAs($member)->get(route('chats.show', $room))->assertForbidden();
        $this->actingAs($member)->post(route('chats.leave', $room))->assertForbidden();

        $this->actingAs($member)->post(route('chats.join', $room));
        $this->actingAs($member)->get(route('chats.show', $room))->assertOk();
    }

    public function test_only_who_started_a_group_renames_or_deletes_it_and_never_sets_an_agent()
    {
        [$owner, $member] = User::factory()->count(2)->create();
        $project = $this->project($owner, $member);
        $room = $this->group($project, 'Old');
        $connection = AiConnection::factory()->for($owner)->create();

        $this->actingAs($member)->patch(route('chats.update', $room), ['title' => 'Mine'])->assertForbidden();
        $this->actingAs($member)->delete(route('chats.destroy', $room))->assertForbidden();

        $this->actingAs($owner)->patch(route('chats.update', $room), ['title' => 'New', 'connection' => $connection->ref_id]);
        $this->assertSame('New', $room->fresh()->title);
        $this->assertNull($room->fresh()->ai_connection_id);

        $this->actingAs($owner)->delete(route('chats.destroy', $room))->assertRedirect(route('projects.chat', $project));
        $this->assertDatabaseCount('chat_rooms', 0);
    }

    public function test_a_projects_groups_go_with_the_project()
    {
        $owner = User::factory()->create();
        $project = $this->project($owner);
        $room = ChatRoom::startGroup($project, $owner, 'A');
        ChatRoom::startGroup($project, $owner, 'B');
        $room->messages()->create(['role' => 'user', 'user_id' => $owner->id, 'content' => 'hi']);

        $project->delete();

        $this->assertDatabaseCount('chat_rooms', 0);
        $this->assertDatabaseCount('chat_messages', 0);
        $this->assertDatabaseCount('chat_room_members', 0);
    }

    public function test_someone_who_leaves_a_project_leaves_its_groups()
    {
        [$owner, $member] = User::factory()->count(2)->create();
        $project = $this->project($owner, $member);
        $room = $this->group($project);

        $this->actingAs($member)->get(route('chats.show', $room))->assertOk();

        $project->members()->detach($member);

        $this->actingAs($member)->get(route('chats.show', $room))->assertForbidden();
        $this->actingAs($member)->post(route('chats.messages.store', $room), ['content' => 'still here?'])->assertForbidden();
        $this->assertSame(0, ChatRoom::unreadTotal($member));
        $this->actingAs($member)->get(route('messages.index'))
            ->assertInertia(fn (Assert $page) => $page->has('groups', 0));
    }

    public function test_a_direct_room_is_not_renamed_set_up_or_deleted_by_either()
    {
        [$ada, $grace] = User::factory()->count(2)->create();
        $project = $this->project($ada, $grace);
        $connection = AiConnection::factory()->for($ada)->create();

        foreach ([ChatRoom::between($ada, $grace)] as $room) {
            $this->actingAs($ada)->patch(route('chats.update', $room), ['title' => 'Mine'])->assertForbidden();
            $this->actingAs($ada)->patch(route('chats.update', $room), ['connection' => $connection->ref_id])->assertForbidden();
            $this->actingAs($ada)->delete(route('chats.destroy', $room))->assertForbidden();
        }

        $this->assertDatabaseCount('chat_rooms', 1);
    }

    public function test_no_agent_answers_in_a_shared_room_even_if_one_were_set()
    {
        Http::fake();
        [$ada, $grace] = User::factory()->count(2)->create();
        $this->project($ada, $grace);
        $room = ChatRoom::between($ada, $grace);
        $room->forceFill(['ai_connection_id' => AiConnection::factory()->for($ada)->create()->id])->save();

        $this->assertFalse($room->fresh()->hasAgent());
        $body = $this->say($ada, $room, 'Hello?');

        $this->assertStringContainsString("event: done\n", $body);
        $this->assertStringNotContainsString('event: delta', $body);
        Http::assertNothingSent();
    }

    public function test_what_is_said_in_a_shared_room_is_told_to_the_room_and_to_everyone_else_in_it()
    {
        Bus::fake([BroadcastEvent::class]);
        [$owner, $a, $b] = User::factory()->count(3)->create();
        $room = $this->group($this->project($owner, $a, $b));

        $this->say($a, $room, 'Lunch?');

        Bus::assertDispatched(BroadcastEvent::class, function (BroadcastEvent $job) use ($room, $a, $owner, $b) {
            if (! $job->event instanceof ChatMessagePosted) {
                return false;
            }

            $channels = collect($job->event->broadcastOn())->map(fn ($channel) => $channel->name)->sort()->values()->all();
            $payload = $job->event->broadcastWith();

            return $channels === collect([
                "presence-chats.{$room->ref_id}",
                "private-App.Models.User.{$owner->id}",
                "private-App.Models.User.{$b->id}",
            ])->sort()->values()->all()
                && $payload['room'] === $room->ref_id
                && $payload['message']['content'] === 'Lunch?'
                && $payload['message']['author'] === ['id' => $a->id, 'name' => $a->name];
        });
    }

    public function test_nothing_is_told_from_ones_own_room()
    {
        Bus::fake([BroadcastEvent::class]);
        $user = User::factory()->create();
        $room = ChatRoom::factory()->for($user)->create();

        $this->say($user, $room, 'Note to self');

        Bus::assertNotDispatched(BroadcastEvent::class);
    }

    public function test_the_room_channel_is_a_presence_channel_and_others_get_a_private_one()
    {
        [$ada, $grace] = User::factory()->count(2)->create();
        $this->project($ada, $grace);
        $room = ChatRoom::between($ada, $grace);
        $message = $room->messages()->create(['role' => 'user', 'user_id' => $ada->id, 'content' => 'hi']);

        $channels = (new ChatMessagePosted($room, $message))->broadcastOn();

        $this->assertInstanceOf(PresenceChannel::class, $channels[0]);
        $this->assertCount(2, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[1]);
        $this->assertSame("private-App.Models.User.{$grace->id}", $channels[1]->name);
    }

    public function test_unread_counts_others_messages_until_the_room_is_opened()
    {
        [$ada, $grace] = User::factory()->count(2)->create();
        $project = $this->project($ada, $grace);
        $direct = ChatRoom::between($ada, $grace);
        $group = $this->group($project, 'Launch');

        $this->say($grace, $direct, 'one');
        $this->say($grace, $direct, 'two');
        $this->say($grace, $group, 'three');
        $this->say($ada, $group, 'mine never counts');

        $this->assertSame(3, ChatRoom::unreadTotal($ada));
        // Ada's one is the only thing Grace has yet to read
        $this->assertSame(1, ChatRoom::unreadTotal($grace));

        $this->actingAs($ada)->get(route('messages.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('unreadChats', 3)
                ->where('direct.0.unread', 2)
                ->where('direct.0.title', $grace->name)
                ->where('groups.0.unread', 1)
                ->where('groups.0.title', 'Launch')
                ->where('groups.0.project', $project->name));

        $this->actingAs($ada)->get(route('chats.show', $direct))->assertOk();
        $this->assertSame(1, ChatRoom::unreadTotal($ada));
    }

    public function test_reading_as_messages_arrive_never_goes_back_or_past_the_room()
    {
        [$ada, $grace] = User::factory()->count(2)->create();
        $this->project($ada, $grace);
        $room = ChatRoom::between($ada, $grace);
        $first = $room->messages()->create(['role' => 'user', 'user_id' => $grace->id, 'content' => 'one']);
        $second = $room->messages()->create(['role' => 'user', 'user_id' => $grace->id, 'content' => 'two']);

        $this->actingAs($ada)->postJson(route('chats.read', $room), ['message' => $second->id])
            ->assertOk()->assertExactJson(['unread' => 0]);
        $this->actingAs($ada)->postJson(route('chats.read', $room), ['message' => $first->id])
            ->assertExactJson(['unread' => 0]);

        // A number past the room's end is the room's end, so what comes later is still unread
        $this->actingAs($ada)->postJson(route('chats.read', $room), ['message' => $second->id + 1000]);
        $room->messages()->create(['role' => 'user', 'user_id' => $grace->id, 'content' => 'three']);

        $this->assertSame(1, ChatRoom::unreadTotal($ada));
    }

    public function test_ones_own_rooms_never_count_as_unread()
    {
        $user = User::factory()->create();
        $room = ChatRoom::factory()->for($user)->create();
        $room->messages()->create(['role' => 'assistant', 'content' => 'an answer']);

        $this->assertSame(0, ChatRoom::unreadTotal($user));
    }

    public function test_messages_and_ones_own_ai_rooms_are_listed_apart()
    {
        [$ada, $grace] = User::factory()->count(2)->create();
        $this->group($this->project($ada, $grace), 'One');
        $this->group($this->project($ada), 'Two');
        ChatRoom::between($ada, $grace);
        ChatRoom::factory()->for($ada)->create(['title' => 'Ideas']);
        ChatRoom::factory()->create(['title' => 'Someone else']);

        $this->actingAs($ada)->get(route('messages.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('messages/Index')
                ->has('direct', 1)
                ->has('groups', 2)
                ->has('projects', 2)
                ->missing('rooms'));

        $this->actingAs($ada)->get(route('chats.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('chats/Index')
                ->has('rooms', 1)
                ->where('rooms.0.title', 'Ideas')
                ->missing('direct')
                ->missing('groups'));
    }

    public function test_a_long_room_opens_on_its_latest_and_scrolls_back_a_page_at_a_time()
    {
        [$ada, $grace] = User::factory()->count(2)->create();
        $this->project($ada, $grace);
        $room = ChatRoom::between($ada, $grace);

        foreach (range(1, 250) as $n) {
            $room->messages()->create(['role' => 'user', 'user_id' => $grace->id, 'content' => "m{$n}"]);
        }

        $this->actingAs($ada)->get(route('chats.show', $room))
            ->assertInertia(fn (Assert $page) => $page
                ->has('messages', 100)
                ->where('messages.0.content', 'm151')
                ->where('messages.99.content', 'm250')
                ->where('hasEarlier', true));

        $first = $room->messages()->where('content', 'm151')->value('id');
        $page = $this->actingAs($ada)->getJson(route('chats.messages.index', ['room' => $room, 'before' => $first]))->json();
        $this->assertCount(100, $page['messages']);
        $this->assertSame('m51', $page['messages'][0]['content']);
        $this->assertTrue($page['hasEarlier']);

        $last = $this->actingAs($ada)->getJson(route('chats.messages.index', ['room' => $room, 'before' => $page['messages'][0]['id']]))->json();
        $this->assertCount(50, $last['messages']);
        $this->assertFalse($last['hasEarlier']);

        // Opening it read everything shown
        $this->assertSame(0, ChatRoom::unreadTotal($ada));
    }

    public function test_someone_who_deletes_their_account_leaves_their_words_behind_unnamed()
    {
        [$ada, $grace] = User::factory()->count(2)->create();
        $this->project($ada, $grace);
        $room = ChatRoom::between($ada, $grace);
        $room->messages()->create(['role' => 'user', 'user_id' => $grace->id, 'content' => 'bye']);

        $grace->delete();

        $this->actingAs($ada)->get(route('chats.show', $room))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('room.title', 'Only you')
                ->where('messages.0.author', ['id' => null, 'name' => 'Deleted user'])
                ->where('messages.0.content', 'bye'));
        $this->assertSame(1, ChatMessage::query()->count());
    }
}
