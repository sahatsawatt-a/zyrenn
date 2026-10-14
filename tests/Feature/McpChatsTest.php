<?php

namespace Tests\Feature;

use App\Mcp\Servers\GlobalServer;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Chats\GetChat;
use App\Mcp\Tools\Chats\ListChats;
use App\Mcp\Tools\Chats\SayInChat;
use App\Mcp\Tools\Chats\UpdateChat;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * A project's chats with customers over MCP: listed, read, answered, noted
 * on and looked after, by the project's name, by those allowed to.
 */
class McpChatsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private User $viewer;

    private ChatRoom $ada;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email' => 'owner@example.com']);
        $this->viewer = User::factory()->create();
        $this->project = Project::factory()->withMember($this->owner)->create(['name' => 'Shop']);
        $this->project->members()->attach($this->viewer, ['role' => Project::VIEWER]);

        $this->ada = $this->chat('Ada on LINE', 'line');
        $this->ada->messages()->create(['role' => ChatMessage::WORKFLOW, 'content' => 'Is it open?', 'meta' => ['name' => 'Ada (LINE)', 'outside' => true]]);
        $this->chat('Bo on WhatsApp', 'whatsapp')->forceFill(['status' => ChatRoom::CLOSED])->save();
    }

    private function chat(string $title, string $service): ChatRoom
    {
        $room = ChatRoom::startGroup($this->project, $this->owner, $title);
        $room->forceFill(['link' => ['service' => $service, 'connection' => 'c', 'chat' => 'U1']])->save();
        $room->becomeCustomers();

        return $room;
    }

    public function test_a_projects_chats_are_listed_read_answered_and_closed()
    {
        UserServer::actingAs($this->viewer)
            ->tool(ListChats::class, ['project' => 'shop'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('chats', 1)
                ->where('chats.0.chat', $this->ada->ref_id)
                ->where('chats.0.status', 'open')
                ->where('chats.0.service', 'line')
                ->where('chats.0.customer', 'Ada on LINE')
                ->where('chats.0.last', 'Ada (LINE): Is it open?')
                ->where('counts', ['open' => 1, 'closed' => 1])
                ->etc());

        UserServer::actingAs($this->viewer)
            ->tool(ListChats::class, ['project' => 'Shop', 'status' => 'all', 'service' => 'whatsapp'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->has('chats', 1)->where('chats.0.title', 'Bo on WhatsApp')->etc());

        // A viewer reads, and answers nobody
        UserServer::actingAs($this->viewer)
            ->tool(SayInChat::class, ['project' => 'Shop', 'chat' => $this->ada->ref_id, 'text' => 'Hi'])
            ->assertHasErrors(['only its owners and editors']);

        UserServer::actingAs($this->owner)
            ->tool(SayInChat::class, ['project' => 'Shop', 'chat' => $this->ada->ref_id, 'text' => 'She asked last week too', 'note' => true])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('said.note', true)->where('status', 'open')->etc());

        UserServer::actingAs($this->owner)
            ->tool(SayInChat::class, ['project' => 'Shop', 'chat' => $this->ada->ref_id, 'text' => 'Yes, until six.'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('said.from', 'team')->where('said.text', 'Yes, until six.')->where('status', 'waiting')->etc());

        UserServer::actingAs($this->viewer)
            ->tool(GetChat::class, ['project' => 'Shop', 'chat' => $this->ada->ref_id, 'limit' => 2])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('status', 'waiting')
                ->where('customer.name', 'Ada on LINE')
                ->where('customer.chats.0.service', 'line')
                ->where('earlier', true)
                ->has('messages', 2)
                ->where('messages.0.note', true)
                ->where('messages.1.text', 'Yes, until six.')
                ->etc());

        GlobalServer::actingAs($this->owner)
            ->tool(UpdateChat::class, ['user_id' => $this->owner->id, 'project' => 'Shop', 'chat' => $this->ada->ref_id, 'status' => 'closed', 'assignee' => 'owner@example.com', 'add_tags' => ['VIP', 'refund'], 'remove_tags' => ['refund']])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('status', 'closed')->where('assignee', 'owner@example.com')->where('tags', ['vip'])->etc());

        // Only someone who answers for the project is given one; a chat of another project is not found here
        UserServer::actingAs($this->owner)
            ->tool(UpdateChat::class, ['project' => 'Shop', 'chat' => $this->ada->ref_id, 'assignee' => $this->viewer->email])
            ->assertHasErrors(['an owner or an editor']);
        UserServer::actingAs($this->owner)
            ->tool(GetChat::class, ['project' => 'Shop', 'chat' => 'nosuchchat'])
            ->assertHasErrors(['has no chat with a customer']);
    }
}
