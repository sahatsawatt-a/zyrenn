<?php

namespace Tests\Feature;

use App\Events\ChatRoomChanged;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\Contact;
use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowRun;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Workflow\Engine;
use App\Support\Workflow\References;
use App\Support\Workflow\RunStatus;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A project's inbox: its chats with customers, each a room linked to a chat
 * on a messaging service -- who may read and answer them, how one moves
 * between open, waiting and closed, notes that never go out, and the
 * workflow steps and templates that bring chats in and send answers back.
 */
class InboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database', 'services.workflows.public_url' => 'https://hooks.example.test']);
        Http::preventStrayRequests();
    }

    /**
     * A project with its owner, an editor and a viewer.
     *
     * @return array{0: Project, 1: User, 2: User, 3: User}
     */
    private function team(): array
    {
        [$owner, $editor, $viewer] = User::factory()->count(3)->create()->all();
        $project = Project::factory()->withMember($owner)->create();
        $project->members()->attach($editor, ['role' => Project::EDITOR]);
        $project->members()->attach($viewer, ['role' => Project::VIEWER]);

        return [$project, $owner, $editor, $viewer];
    }

    /**
     * A chat with a customer in the project, as a workflow would have made it.
     */
    private function customer(Project $project, string $title = 'Ada on LINE', string $service = 'line'): ChatRoom
    {
        $room = ChatRoom::startGroup($project, $project->members()->orderBy('project_user.id')->firstOrFail(), $title);
        $room->forceFill(['link' => ['service' => $service, 'connection' => 'conn1', 'chat' => 'U1']])->save();
        $room->becomeCustomers();

        return $room;
    }

    /**
     * What the customer wrote, as a bridge carries it in.
     */
    private function heard(ChatRoom $room, string $content = 'Is it open?'): ChatMessage
    {
        return $room->messages()->create(['role' => ChatMessage::WORKFLOW, 'content' => $content, 'meta' => ['name' => 'Ada (LINE)', 'outside' => true]]);
    }

    private function say(User $user, ChatRoom $room, array $message): string
    {
        return $this->actingAs($user)->post(route('chats.messages.store', $room), $message)->assertOk()->streamedContent();
    }

    private function node(string $key, string $type, array $config = []): array
    {
        return ['id' => $key, 'key' => $key, 'type' => $type, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
    }

    /**
     * Start → each step in turn, in the project, run by hand and worked off.
     */
    private function runChain(Project $project, User $user, array $steps): WorkflowRun
    {
        $nodes = [$this->node('start', 'trigger.manual', ['payload' => '{"n": 1}']), ...$steps];
        $edges = [];

        foreach (array_slice($nodes, 1) as $at => $node) {
            $edges[] = ['id' => "{$nodes[$at]['id']}.out.{$node['id']}", 'source' => $nodes[$at]['id'], 'sourceHandle' => 'out', 'target' => $node['id']];
        }

        $workflow = Workflow::factory()->steps($nodes, $edges)->forProject($project, $user)->create();
        $run = Engine::start($workflow, $workflow->freeze(WorkflowVersion::TEST, $user), $user);
        $this->work();

        return $run->fresh();
    }

    public function test_a_projects_room_linked_to_an_outside_chat_is_everyones_in_the_project()
    {
        [$project, $owner, $editor, $viewer] = $this->team();
        $bot = Connection::factory()->telegram()->forProject($project, $owner)->create();

        $run = $this->runChain($project, $editor, [
            $this->node('room', 'chat.room', ['key' => 'telegram:77', 'title' => 'Ada on Telegram', 'link' => $bot->ref_id, 'link_chat' => '77']),
            // A room the project keeps for itself stays a group
            $this->node('own', 'chat.room', ['key' => 'alerts', 'title' => 'Alerts']),
        ]);

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $room = ChatRoom::where('external_key', 'telegram:77')->sole();
        $this->assertSame([ChatRoom::CUSTOMER, ChatRoom::OPEN, $project->id], [$room->kind, $room->status, $room->project_id]);
        $this->assertSame(ChatRoom::GROUP, ChatRoom::where('external_key', 'alerts')->sole()->kind);

        // Nobody joins it: the owner and the viewer open it, though the editor's run made it
        $this->actingAs($owner)->get(route('chats.show', $room))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('room.kind', 'customer')
                ->where('room.desk.status', 'open')
                ->where('room.post', true)
                ->where('room.remove', true)
                ->count('room.desk.people', 2));
        $this->actingAs($viewer)->get(route('chats.show', $room))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('room.post', false)->where('room.manage', false)->where('room.remove', false));
        $this->actingAs(User::factory()->create())->get(route('chats.show', $room))->assertForbidden();

        // It is not among one's messages, nor counted there: the inbox holds it
        $this->heard($room);
        $this->actingAs($editor)->get(route('chats.index'))
            ->assertInertia(fn (Assert $page) => $page->count('groups', 1)->where('groups.0.title', 'Alerts')->where('unreadChats', 0));
    }

    public function test_a_customers_chat_is_opened_by_what_they_send_and_left_waiting_by_an_answer()
    {
        [$project, $owner, $editor] = $this->team();
        $room = $this->customer($project);
        Event::fake([ChatRoomChanged::class]);

        $this->say($editor, $room, ['content' => 'Yes, until six.']);
        $this->assertSame(ChatRoom::WAITING, $room->fresh()->status);
        // The others in the project hear of it, for their inbox
        Event::assertDispatched(ChatRoomChanged::class, fn (ChatRoomChanged $event) => in_array(new PrivateChannel("App.Models.User.{$owner->id}"), $event->broadcastOn()));

        $this->heard($room, 'Thanks!');
        $this->assertSame(ChatRoom::OPEN, $room->fresh()->status);

        // Closed stays closed whatever is said from here, until they write again
        $room->forceFill(['status' => ChatRoom::CLOSED])->save();
        $this->say($owner, $room, ['content' => 'Closing this.']);
        $this->assertSame(ChatRoom::CLOSED, $room->fresh()->status);
        $this->heard($room, 'One more thing');
        $this->assertSame(ChatRoom::OPEN, $room->fresh()->status);
    }

    public function test_a_note_is_for_the_project_alone_and_no_workflow_hears_it()
    {
        [$project, $owner, $editor] = $this->team();
        $room = $this->customer($project);
        $this->heard($room);

        // A workflow that hears the room, as a bridge's way out does
        $workflow = Workflow::factory()->forProject($project, $owner)->steps([
            $this->node('written', 'trigger.chat', ['room' => $room->ref_id]),
            $this->node('log', 'output.log', ['message' => '{{ trigger.text }}']),
        ], [['id' => 'written.out.log', 'source' => 'written', 'sourceHandle' => 'out', 'target' => 'log']])->create();
        $this->actingAs($owner)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertOk();
        $this->actingAs($owner)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertOk();

        $said = $this->say($editor, $room, ['content' => 'She asked last week too', 'internal' => true]);
        $this->assertStringContainsString('"internal":true', $said);
        $this->work();

        $this->assertSame(0, $workflow->runs()->count());
        // Still theirs to answer
        $this->assertSame(ChatRoom::OPEN, $room->fresh()->status);
        $this->assertTrue($room->messages()->reorder('id', 'desc')->firstOrFail()->isInternal());

        // An answer is heard, and says where the chat stands
        $this->say($editor, $room, ['content' => 'Yes, until six.']);
        $this->work();
        $run = $workflow->runs()->sole();
        $this->assertSame(['Yes, until six.', 'customer', 'waiting'], [$run->trigger_data['text'], $run->trigger_data['room']['kind'], $run->trigger_data['room']['status']]);

        // A note is only ever one in a customer's chat
        $group = ChatRoom::startGroup($project, $owner, 'Team');
        $this->say($owner, $group, ['content' => 'Hello', 'internal' => true]);
        $this->assertFalse($group->messages()->sole()->isInternal());
    }

    public function test_owners_and_editors_answer_and_look_after_a_chat_and_viewers_only_read()
    {
        [$project, $owner, $editor, $viewer] = $this->team();
        $room = $this->customer($project);

        $this->actingAs($viewer)->post(route('chats.messages.store', $room), ['content' => 'Hi'])->assertForbidden();
        $this->actingAs($viewer)->patch(route('chats.update', $room), ['status' => 'closed'])->assertForbidden();

        $this->actingAs($editor)->patch(route('chats.update', $room), [
            'status' => 'closed',
            'assigned_to' => $owner->id,
            'tags' => ['Refund', ' vip ', 'refund'],
        ])->assertRedirect();
        $room->refresh();
        $this->assertSame([ChatRoom::CLOSED, $owner->id, ['refund', 'vip']], [$room->status, $room->assigned_to, $room->tags]);

        // Only someone who answers for the project can be given it
        $this->actingAs($editor)->patch(route('chats.update', $room), ['assigned_to' => $viewer->id])->assertSessionHasErrors('assigned_to');
        $this->actingAs($editor)->patch(route('chats.update', $room), ['status' => 'done'])->assertSessionHasErrors('status');
        $this->actingAs($editor)->patch(route('chats.update', $room), ['assigned_to' => null, 'tags' => []])->assertRedirect();
        $this->assertSame([null, null], [$room->fresh()->assigned_to, $room->fresh()->tags]);

        // Everything said to a customer goes with it: an owner's to delete
        $this->actingAs($editor)->delete(route('chats.destroy', $room))->assertForbidden();
        $this->actingAs($owner)->delete(route('chats.destroy', $room))->assertRedirect(route('projects.inbox', $project));
        $this->assertModelMissing($room);
    }

    public function test_a_chat_says_until_when_its_service_takes_an_answer()
    {
        [$project, $owner] = $this->team();
        $onWhatsApp = $this->customer($project, 'Bo on WhatsApp', 'whatsapp');
        $onLine = $this->customer($project);
        $until = fn (ChatRoom $room) => $this->actingAs($owner)->get(route('chats.show', $room))->assertOk()->viewData('page')['props']['room']['desk']['answer_until'];

        // Nothing heard yet, and a service that sets no time: nothing to say
        $this->assertNull($until($onWhatsApp));
        $this->heard($onLine);
        $this->assertNull($until($onLine));

        // A day from the last thing they wrote, whatever is answered meanwhile
        $this->travelTo(now()->startOfSecond());
        $this->heard($onWhatsApp);
        $day = now()->addDay();
        $this->travel(2)->hours();
        $this->say($owner, $onWhatsApp, ['content' => 'Yes, until six.']);
        $this->assertTrue($day->equalTo($until($onWhatsApp)));
    }

    public function test_the_inbox_lists_a_projects_chats_by_where_they_stand()
    {
        [$project, $owner, $editor, $viewer] = $this->team();
        $ada = $this->customer($project, 'Ada on LINE');
        $bo = $this->customer($project, 'Bo on WhatsApp', 'whatsapp');
        $cy = $this->customer($project, 'Cy on LINE');
        $this->heard($ada);
        $bo->forceFill(['status' => ChatRoom::WAITING, 'assigned_to' => $editor->id, 'tags' => ['vip']])->save();
        $cy->forceFill(['status' => ChatRoom::CLOSED])->save();
        // Another project's are never seen
        $this->customer(Project::factory()->withMember(User::factory()->create())->create(), 'Elsewhere');

        $inbox = fn (User $user, array $filters = []) => $this->actingAs($user)->get(route('projects.inbox', [$project, ...$filters]));

        // Those to be answered first, and how many stand where
        $inbox($viewer)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('chats/Inbox')
            ->count('rooms', 1)
            ->where('rooms.0.title', 'Ada on LINE')
            ->where('rooms.0.unread', 1)
            ->where('rooms.0.last', 'Ada (LINE): Is it open?')
            ->where('counts', ['open' => 1, 'waiting' => 1, 'closed' => 1])
            ->where('channels', ['line', 'whatsapp'])
            ->where('project.inbox', 1)
            ->count('people', 2));

        $inbox($owner, ['status' => 'all'])->assertInertia(fn (Assert $page) => $page->count('rooms', 3));
        $inbox($owner, ['status' => 'waiting'])->assertInertia(fn (Assert $page) => $page
            ->count('rooms', 1)
            ->where('rooms.0.assignee.name', $editor->name)
            ->where('rooms.0.tags', ['vip']));
        $inbox($editor, ['status' => 'all', 'assigned' => 'me'])->assertInertia(fn (Assert $page) => $page->count('rooms', 1)->where('counts', ['waiting' => 1]));
        $inbox($owner, ['status' => 'all', 'assigned' => 'nobody'])->assertInertia(fn (Assert $page) => $page->count('rooms', 2));
        $inbox($owner, ['status' => 'all', 'channel' => 'whatsapp'])->assertInertia(fn (Assert $page) => $page->count('rooms', 1)->where('rooms.0.title', 'Bo on WhatsApp'));
        $inbox($owner, ['status' => 'all', 'q' => 'cy'])->assertInertia(fn (Assert $page) => $page->count('rooms', 1)->where('rooms.0.title', 'Cy on LINE'));

        $this->actingAs(User::factory()->create())->get(route('projects.inbox', $project))->assertForbidden();
    }

    public function test_a_workflow_hands_a_chat_to_someone_and_closes_it()
    {
        [$project, $owner, $editor, $viewer] = $this->team();
        $room = $this->customer($project);
        $room->forceFill(['tags' => ['new']])->save();

        $run = $this->runChain($project, $owner, [
            $this->node('hand', 'chat.update', ['room' => $room->ref_id, 'status' => 'keep', 'assign' => 'person', 'person' => $editor->email, 'tag' => 'Refund, vip', 'untag' => 'new']),
        ]);

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $room->refresh();
        $this->assertSame([ChatRoom::OPEN, $editor->id, ['refund', 'vip']], [$room->status, $room->assigned_to, $room->tags]);
        $this->assertSame($editor->name, $run->steps()->where('node_key', 'hand')->sole()->output['assignee']['name']);

        $run = $this->runChain($project, $owner, [
            $this->node('close', 'chat.update', ['room' => $room->ref_id, 'status' => 'closed', 'assign' => 'nobody']),
        ]);
        $this->assertSame([ChatRoom::CLOSED, null], [$room->fresh()->status, $room->fresh()->assigned_to]);

        // A viewer answers for nothing; a group has no status to set
        $run = $this->runChain($project, $owner, [
            $this->node('hand', 'chat.update', ['room' => $room->ref_id, 'status' => 'keep', 'assign' => 'person', 'person' => $viewer->email]),
        ]);
        $this->assertSame(RunStatus::Failed, $run->status);

        $group = ChatRoom::startGroup($project, $owner, 'Team');
        $run = $this->runChain($project, $owner, [
            $this->node('close', 'chat.update', ['room' => $group->ref_id, 'status' => 'closed', 'assign' => 'keep']),
        ]);
        $this->assertSame(RunStatus::Failed, $run->status);
    }

    public function test_a_whatsapp_chat_and_its_room_in_the_inbox_talk_both_ways()
    {
        $sent = [];
        Http::fake(function (Request $request) use (&$sent) {
            $sent[] = $request->data();

            return Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '16505551234']], 'messages' => [['id' => 'wamid.out']]]);
        });
        [$project, $owner, $editor] = $this->team();
        $number = Connection::factory()->whatsapp()->forProject($project, $owner)->create();

        $this->actingAs($owner)
            ->post(route('projects.workflows.store', $project), ['template' => 'whatsapp-room', 'connections' => ['whatsapp' => $number->ref_id]])
            ->assertRedirect();
        $workflow = $project->workflows()->sole();
        $this->assertSame([], References::allProblems($workflow));
        $this->actingAs($owner)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertOk();
        $this->actingAs($owner)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertOk();
        auth()->logout();

        // In from WhatsApp: a chat of their own in the project's inbox, to be answered
        $body = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '1', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => '106540352242922'],
            'contacts' => [['profile' => ['name' => 'Sheena'], 'wa_id' => '16505551234']],
            'messages' => [['from' => '16505551234', 'id' => 'wamid.in', 'timestamp' => '1749416383', 'type' => 'text', 'text' => ['body' => 'Is it open?']]],
        ]]]]]];
        $this->call('POST', "/hooks/whatsapp/{$number->ref_id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', (string) json_encode($body), 'wa-app-secret'),
        ], (string) json_encode($body))->assertOk();
        $this->work();

        $room = ChatRoom::query()->customers($project)->sole();
        $this->assertSame(['Sheena on WhatsApp', ChatRoom::OPEN, 'whatsapp', '16505551234'], [$room->title, $room->status, $room->link['service'], $room->link['chat']]);
        $this->assertSame(['name' => 'Sheena', 'phone' => '16505551234'], $room->details);
        $heard = $room->messages()->sole();
        $this->assertSame(['Is it open?', 'Sheena (WhatsApp)', true], [$heard->content, $heard->meta['name'], $heard->isFromOutside()]);

        // Out: the editor's answer goes back through the room's own number, and the chat waits on them
        $this->say($editor, $room, ['content' => 'Yes, until six.']);
        // A note stays here
        $this->say($editor, $room, ['content' => 'Regular customer', 'internal' => true]);
        $this->work();

        $out = collect($sent)->firstWhere('type', 'text');
        $this->assertSame(['16505551234', 'Yes, until six.'], [$out['to'], $out['text']['body']]);
        $this->assertCount(1, collect($sent)->where('type', 'text'));
        $this->assertSame(ChatRoom::WAITING, $room->fresh()->status);
    }

    public function test_the_instagram_room_template_is_whole()
    {
        [$project, $owner] = $this->team();
        $account = Connection::factory()->instagram()->forProject($project, $owner)->create();

        $this->actingAs($owner)
            ->post(route('projects.workflows.store', $project), ['template' => 'instagram-room', 'connections' => ['instagram' => $account->ref_id]])
            ->assertRedirect();

        $workflow = $project->workflows()->sole();
        $this->assertSame([], References::allProblems($workflow));
        $this->assertSame('Instagram messages in a room, both ways', $workflow->title);
    }

    // --- who a customer is, on every service ---

    public function test_a_customer_is_one_person_on_every_service_a_workflow_knows_them_by()
    {
        [$project, $owner] = $this->team();
        $bot = Connection::factory()->telegram()->forProject($project, $owner)->create();
        $number = Connection::factory()->whatsapp()->forProject($project, $owner)->create();
        $room = fn (Connection $through, string $chat, string $key) => $this->node("room{$chat}", 'chat.room', [
            'key' => "{$through->service}:{$chat}",
            'title' => "Ada on {$through->service}",
            'link' => $through->ref_id,
            'link_chat' => $chat,
            'contact' => $key,
            'details' => [['name' => 'name', 'value' => 'Ada Lovelace']],
        ]);

        $this->runChain($project, $owner, [$room($bot, '77', 'Ada@Example.com ')]);
        $onTelegram = ChatRoom::where('external_key', 'telegram:77')->sole();
        $ada = $onTelegram->contact;
        $this->assertSame(['Ada Lovelace', 'ada@example.com'], [$ada->name, $ada->key]);

        // The same key on another service: the same person, and a chat that shows the other
        $run = $this->runChain($project, $owner, [$room($number, '6650', 'ada@example.com'), $room($bot, '88', '')]);
        $onWhatsApp = ChatRoom::where('external_key', 'whatsapp:6650')->sole();
        $this->assertSame($ada->id, $onWhatsApp->contact_id);
        $this->assertSame($ada->ref_id, $run->steps()->where('node_key', 'room6650')->sole()->output['contact']);
        $this->assertSame(1, Contact::query()->where('key', 'ada@example.com')->count());
        // Without a key, someone of their own
        $this->assertNotSame($ada->id, ChatRoom::where('external_key', 'telegram:88')->sole()->contact_id);

        $this->actingAs($owner)->get(route('chats.show', $onWhatsApp))
            ->assertInertia(fn (Assert $page) => $page
                ->where('room.contact.name', 'Ada Lovelace')
                ->where('room.contact.rooms.0.service', 'telegram')
                ->where('room.contact.rooms.1.service', 'whatsapp'));

        // A workflow reads who they are, with their chats
        $this->heard($onWhatsApp);
        $this->assertSame(['telegram', 'whatsapp'], array_column($onWhatsApp->fresh()->heardAs()['contact']['rooms'], 'service'));

        // And the inbox finds them by their name -- the other Ada too -- saying where else each writes
        $this->actingAs($owner)->get(route('projects.inbox', [$project, 'status' => 'all', 'q' => 'lovelace']))
            ->assertInertia(fn (Assert $page) => $page
                ->count('rooms', 3)
                ->where('rooms', fn ($rooms) => collect($rooms)->firstWhere('title', 'Ada on whatsapp')['also'] === ['telegram']));
    }

    public function test_what_is_noted_about_a_customer_is_kept_once_for_all_their_chats()
    {
        [$project, $owner] = $this->team();
        $onLine = $this->customer($project, 'Ada on LINE');
        $onWhatsApp = $this->customer($project, 'Ada on WhatsApp', 'whatsapp');
        $onLine->contact->absorb($onWhatsApp->contact);
        $onLine->note(['line_id' => 'U1']);

        $this->actingAs($owner)->patch(route('chats.update', $onLine), ['details' => ['line_id' => 'U1', 'phone' => '0812345678']])->assertRedirect();

        // On them, not on the chat; what the workflow keeps of LINE stays with the LINE chat
        $this->assertSame(['phone' => '0812345678'], $onLine->contact->fresh()->details);
        $this->assertSame(['line_id' => 'U1'], $onLine->fresh()->details);
        $this->actingAs($owner)->get(route('chats.show', $onWhatsApp))
            ->assertInertia(fn (Assert $page) => $page->where('room.details', ['phone' => '0812345678'])->where('room.kept', []));
        $this->actingAs($owner)->get(route('chats.show', $onLine))
            ->assertInertia(fn (Assert $page) => $page->where('room.details', ['phone' => '0812345678', 'line_id' => 'U1'])->where('room.kept', ['line_id']));
    }

    public function test_chats_are_found_to_be_one_person_and_taken_apart_by_hand()
    {
        [$project, $owner, $editor, $viewer] = $this->team();
        $onLine = $this->customer($project, 'Ada on LINE');
        $onWhatsApp = $this->customer($project, 'A. Lovelace', 'whatsapp');
        $onWhatsApp->contact->noteByHand(['phone' => '0812345678']);
        $elsewhere = $this->customer(Project::factory()->withMember($owner)->create(), 'Ada elsewhere');

        // Offered: the project's other chats with customers, by their name or by who they are with
        $this->actingAs($editor)->getJson(route('chats.contact.candidates', [$onLine, 'q' => 'lovelace']))
            ->assertOk()
            ->assertJsonPath('rooms.0.ref_id', $onWhatsApp->ref_id)
            ->assertJsonCount(1, 'rooms');
        $this->actingAs($viewer)->getJson(route('chats.contact.candidates', $onLine))->assertForbidden();
        $this->actingAs($viewer)->post(route('chats.contact.join', $onLine), ['room' => $onWhatsApp->ref_id])->assertForbidden();
        $this->actingAs($editor)->post(route('chats.contact.join', $onLine), ['room' => $elsewhere->ref_id])->assertSessionHasErrors('room');

        $this->actingAs($editor)->post(route('chats.contact.join', $onLine), ['room' => $onWhatsApp->ref_id])->assertRedirect();
        $ada = $onLine->fresh()->contact;
        $this->assertSame($ada->id, $onWhatsApp->fresh()->contact_id);
        $this->assertSame(['phone' => '0812345678'], $ada->details);
        // The one taken in is gone: two chats, one person
        $this->assertSame(1, Contact::query()->where('project_id', $project->id)->count());
        // Joined, a chat is not offered again
        $this->actingAs($editor)->getJson(route('chats.contact.candidates', [$onLine, 'q' => 'lovelace']))->assertJsonCount(0, 'rooms');

        $this->actingAs($editor)->patch(route('chats.contact.update', $onWhatsApp), ['name' => 'Ada Lovelace'])->assertRedirect();
        $this->assertSame('Ada Lovelace', $ada->fresh()->name);

        // Not the same after all: someone of its own again, keeping what was noted
        $this->actingAs($editor)->delete(route('chats.contact.leave', $onWhatsApp))->assertRedirect();
        $apart = $onWhatsApp->fresh()->contact;
        $this->assertNotSame($ada->id, $apart->id);
        $this->assertSame(['phone' => '0812345678'], $apart->details);
        $this->assertSame([$onLine->id], $ada->rooms()->pluck('id')->all());
    }

    // --- saved replies ---

    public function test_a_projects_saved_replies_are_kept_by_its_owners_and_editors_and_read_by_all()
    {
        [$project, $owner, $editor, $viewer] = $this->team();

        $this->actingAs($viewer)->postJson(route('projects.replies.store', $project), ['title' => 'Hours', 'body' => 'We open at 9.'])->assertForbidden();
        $this->actingAs($editor)->postJson(route('projects.replies.store', $project), ['title' => '', 'body' => 'x'])->assertJsonValidationErrors('title');

        $made = $this->actingAs($editor)->postJson(route('projects.replies.store', $project), ['title' => ' Opening hours ', 'body' => 'Hi {name}, we open at 9. -- {me}'])
            ->assertCreated()
            ->assertJsonPath('reply.title', 'Opening hours')
            ->json('reply.ref_id');
        $this->actingAs($owner)->postJson(route('projects.replies.store', $project), ['title' => 'Address', 'body' => '1 Main St.'])->assertCreated();

        // Read by anyone in the project, by name
        $this->actingAs($viewer)->getJson(route('projects.replies.index', $project))
            ->assertOk()
            ->assertJsonPath('replies.*.title', ['Address', 'Opening hours']);
        $this->actingAs(User::factory()->create())->getJson(route('projects.replies.index', $project))->assertForbidden();

        $this->actingAs($owner)->patchJson(route('projects.replies.update', [$project, $made]), ['title' => 'Hours', 'body' => 'We open at 10.'])
            ->assertOk()
            ->assertJsonPath('replies.1.body', 'We open at 10.');

        // Another project's is not reached through this one
        $other = Project::factory()->withMember($owner)->create();
        $this->actingAs($owner)->deleteJson(route('projects.replies.destroy', [$other, $made]))->assertNotFound();

        $this->actingAs($editor)->deleteJson(route('projects.replies.destroy', [$project, $made]))
            ->assertOk()
            ->assertJsonPath('replies.*.title', ['Address']);
    }

    // --- what a customer asks, made a ticket ---

    public function test_what_a_customer_asks_is_made_a_ticket_that_points_back_at_the_chat()
    {
        [$project, $owner, $editor, $viewer] = $this->team();
        $room = $this->customer($project);
        $asked = $this->heard($room, 'The app crashes when I pay');
        $other = $this->heard($this->customer($project, 'Bo on LINE'), 'Elsewhere');

        $this->actingAs($viewer)->post(route('chats.tickets.store', $room), ['type' => 'bug', 'title' => 'Crash on pay'])->assertForbidden();
        // Only a message of this chat
        $this->actingAs($editor)->post(route('chats.tickets.store', $room), ['type' => 'bug', 'title' => 'Crash on pay', 'message' => $other->id])->assertSessionHasErrors('message');

        $this->actingAs($editor)->post(route('chats.tickets.store', $room), [
            'type' => 'bug', 'title' => 'Crash on pay', 'body' => 'The app crashes when I pay', 'message' => $asked->id,
        ])->assertRedirect();

        $ticket = $project->tickets()->sole();
        $this->assertSame([1, 'bug', 'open', $editor->id, $room->id, $asked->id], [$ticket->number, $ticket->type, $ticket->status, $ticket->created_by, $ticket->asked_in_room_id, $ticket->asked_in_message_id]);

        // The chat keeps a note of it, for the project alone, and is still theirs to answer
        $note = $room->messages()->reorder('id', 'desc')->first();
        $this->assertSame(['Made ticket #1: Crash on pay', true], [$note->content, $note->isInternal()]);
        $this->assertSame(ChatRoom::OPEN, $room->fresh()->status);

        $this->actingAs($owner)->get(route('chats.show', $room))
            ->assertInertia(fn (Assert $page) => $page
                ->where('room.desk.tickets.0.number', 1)
                ->where('room.desk.tickets.0.url', route('projects.tickets.index', [$project, 'ticket' => 1])));
        $this->actingAs($owner)->get(route('projects.tickets.index', $project))
            ->assertInertia(fn (Assert $page) => $page->where('tickets.0.asked_in.ref_id', $room->ref_id));
    }
}
