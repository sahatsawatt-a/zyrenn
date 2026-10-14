<?php

namespace Tests\Feature;

use App\Mcp\Servers\GlobalServer;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Product\GetProduct;
use App\Mcp\Tools\Product\GetProductPart;
use App\Mcp\Tools\Product\ListProductRequests;
use App\Mcp\Tools\Product\ListRequirements;
use App\Mcp\Tools\Product\SaveProductRequest;
use App\Mcp\Tools\Product\SaveRequirement;
use App\Mcp\Tools\Product\UpdateProduct;
use App\Models\Project;
use App\Models\Ticket\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * A project's product over MCP: a tree laid out in one call by keys, read
 * whole or a part at a time with what a change would reach, requests kept
 * word for word and worked out into requirements verified only by their
 * criteria -- by the project's name, by those allowed to.
 */
class McpProductTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->project = Project::factory()->withMember($this->owner)->create(['name' => 'Lakeshore']);
    }

    /**
     * A board with a shape, two colour capabilities and a shared picker both use.
     */
    private function layOut(): void
    {
        UserServer::actingAs($this->owner)->tool(UpdateProduct::class, [
            'project' => 'lakeshore',
            'add' => [
                ['key' => 'board', 'kind' => 'component', 'name' => 'Board'],
                ['key' => 'shape', 'kind' => 'component', 'name' => 'Shape', 'under' => 'board'],
                ['key' => 'border', 'kind' => 'capability', 'name' => 'Change border colour', 'under' => 'shape', 'status' => 'live'],
                ['key' => 'fill', 'kind' => 'capability', 'name' => 'Change fill colour', 'under' => 'shape'],
                ['key' => 'picker', 'kind' => 'component', 'name' => 'Colour picker', 'shared' => true],
            ],
            'link' => [
                ['from' => 'border', 'kind' => 'uses', 'to' => 'picker'],
                ['from' => 'fill', 'kind' => 'uses', 'to' => 'picker'],
            ],
        ])->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('given', ['board' => 'CMP-1', 'shape' => 'CMP-2', 'border' => 'CAP-1', 'fill' => 'CAP-2', 'picker' => 'CMP-3'])
            ->where('parts.2', ['code' => 'CAP-1', 'kind' => 'capability', 'name' => 'Change border colour', 'status' => 'live', 'under' => 'CMP-2', 'depth' => 2])
            ->where('links', ['CAP-1 uses CMP-3', 'CAP-2 uses CMP-3'])
            ->etc());

    }

    public function test_a_tree_is_laid_out_in_one_call_and_read_back()
    {
        $this->layOut();

        UserServer::actingAs($this->owner)->tool(GetProduct::class, ['project' => 'Lakeshore'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('parts.0', ['code' => 'CMP-1', 'kind' => 'component', 'name' => 'Board', 'depth' => 0, 'capabilities' => ['live' => 1, 'planned' => 1]])
                ->where('parts.4.shared', true)
                // Live, with nothing saying what it must do
                ->where('gaps', ['CAP-1 Change border colour (Shape): Live, and no requirement saying what it must do.'])
                ->where('gaps_by_kind', ['no_requirement' => 1])
                ->etc());

        // One part and what is under it
        UserServer::actingAs($this->owner)->tool(GetProduct::class, ['project' => 'Lakeshore', 'under' => 'cmp-2'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('parts', fn ($parts) => collect($parts)->pluck('code')->all() === ['CMP-2', 'CAP-1', 'CAP-2'])
                ->etc());

        // A small change answers with what it touched, not the whole tree
        UserServer::actingAs($this->owner)->tool(UpdateProduct::class, ['project' => 'Lakeshore', 'update' => [['code' => 'CAP-2', 'status' => 'building']]])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('parts', [['code' => 'CAP-2', 'kind' => 'capability', 'name' => 'Change fill colour', 'status' => 'building', 'under' => 'CMP-2', 'depth' => 2]])
                ->where('links', ['CAP-2 uses CMP-3'])
                ->etc());
    }

    public function test_a_part_says_what_a_change_to_it_would_reach()
    {
        $this->layOut();
        $bug = Ticket::file($this->project, $this->owner, ['type' => 'bug', 'title' => 'Fill lost on undo']);

        UserServer::actingAs($this->owner)->tool(UpdateProduct::class, [
            'project' => 'Lakeshore',
            'tickets' => [['code' => 'CAP-2', 'number' => $bug->number]],
            'update' => [['code' => 'CAP-2', 'status' => 'issues', 'summary' => 'Fill a shape with a colour']],
        ])->assertOk();

        UserServer::actingAs($this->owner)->tool(GetProductPart::class, ['project' => 'Lakeshore', 'code' => 'CMP-3'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('linked_from', ['CAP-1 Change border colour (Shape) uses it', 'CAP-2 Change fill colour (Shape) uses it'])
                ->where('impact.direct', ['CAP-1 Change border colour (Shape)', 'CAP-2 Change fill colour (Shape)'])
                ->where('impact.open_tickets', ['#1 bug: Fill lost on undo'])
                ->etc());

        UserServer::actingAs($this->owner)->tool(GetProductPart::class, ['project' => 'Lakeshore', 'code' => 'CAP-2'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('status', 'issues')
                ->where('tickets', ['#1 bug: Fill lost on undo (open)'])
                ->where('history', fn ($history) => str_contains(collect($history)->implode("\n"), 'status planned → issues'))
                ->etc());
    }

    public function test_what_cannot_be_is_refused_and_a_call_is_all_or_nothing()
    {
        $this->layOut();

        // Never under itself, nor under what is under it -- and the part added before it is not kept
        UserServer::actingAs($this->owner)->tool(UpdateProduct::class, [
            'project' => 'Lakeshore',
            'add' => [['kind' => 'component', 'name' => 'Undo']],
            'update' => [['code' => 'CMP-1', 'under' => 'CMP-2']],
        ])->assertHasErrors();
        $this->assertSame(5, $this->project->productElements()->count());

        // Nor a part with others under it taken away, nor one that is not there
        UserServer::actingAs($this->owner)->tool(UpdateProduct::class, ['project' => 'Lakeshore', 'remove' => ['CMP-2']])->assertHasErrors();
        UserServer::actingAs($this->owner)->tool(UpdateProduct::class, ['project' => 'Lakeshore', 'link' => [['from' => 'CAP-1', 'kind' => 'uses', 'to' => 'CAP-99']]])->assertHasErrors();

        // Taken away, its code is not given again
        UserServer::actingAs($this->owner)->tool(UpdateProduct::class, ['project' => 'Lakeshore', 'remove' => ['CAP-2']])->assertOk();
        UserServer::actingAs($this->owner)->tool(UpdateProduct::class, ['project' => 'Lakeshore', 'add' => [['kind' => 'capability', 'name' => 'Opacity', 'under' => 'CMP-2']]])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('given', ['Opacity' => 'CAP-3'])->etc());

        // A viewer reads, and changes nothing; nobody outside reaches it
        $viewer = User::factory()->create();
        $this->project->members()->attach($viewer, ['role' => Project::VIEWER]);
        UserServer::actingAs($viewer)->tool(GetProduct::class, ['project' => 'Lakeshore'])->assertOk();
        UserServer::actingAs($viewer)->tool(UpdateProduct::class, ['project' => 'Lakeshore', 'remove' => ['CAP-1']])->assertHasErrors();
        UserServer::actingAs($viewer)->tool(SaveProductRequest::class, ['project' => 'Lakeshore', 'original' => 'Mine'])->assertHasErrors();
        UserServer::actingAs(User::factory()->create())->tool(GetProduct::class, ['project' => $this->project->ref_id])->assertHasErrors();
    }

    public function test_a_request_is_kept_word_for_word_and_worked_out_into_a_requirement()
    {
        $this->layOut();

        UserServer::actingAs($this->owner)->tool(SaveProductRequest::class, ['project' => 'Lakeshore', 'original' => 'Make the colour picking better.', 'from' => 'Ada'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('code', 'RQ-1')->where('status', 'new')->etc());

        // What was asked never changes
        UserServer::actingAs($this->owner)->tool(SaveProductRequest::class, ['project' => 'Lakeshore', 'code' => 'RQ-1', 'original' => 'Something else'])->assertHasErrors();

        UserServer::actingAs($this->owner)->tool(SaveRequirement::class, [
            'project' => 'Lakeshore',
            'request' => 'RQ-1',
            'title' => 'A colour can be typed as HEX',
            'need' => 'Picking an exact brand colour is slow',
            'understanding' => 'partial',
            'criteria' => [['text' => 'Typing #RRGGBB sets the colour'], ['text' => 'A wrong one is refused']],
            'questions' => ['RGB too, or HEX only?', ' '],
            'parts' => ['CMP-3'],
        ])->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('code', 'REQ-1')
            ->where('request', 'RQ-1')
            ->where('met', '0 of 2')
            ->where('questions', ['RGB too, or HEX only?'])
            ->where('parts', ['CMP-3'])
            ->etc());

        UserServer::actingAs($this->owner)->tool(ListProductRequests::class, ['project' => 'Lakeshore', 'status' => 'all'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('requests.0', fn ($one) => $one['original'] === 'Make the colour picking better.' && $one['status'] === 'split' && $one['requirements'] === ['REQ-1'])
                ->etc());

        // Verified only once every criterion is met -- and a refused call keeps nothing of it
        UserServer::actingAs($this->owner)->tool(SaveRequirement::class, ['project' => 'Lakeshore', 'code' => 'REQ-1', 'status' => 'verified'])->assertHasErrors();
        UserServer::actingAs($this->owner)->tool(SaveRequirement::class, [
            'project' => 'Lakeshore',
            'code' => 'REQ-1',
            'criteria' => [['text' => 'Typing #RRGGBB sets the colour', 'met' => true], ['text' => 'A wrong one is refused', 'met' => true]],
            'status' => 'verified',
        ])->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json->where('status', 'verified')->where('met', '2 of 2')->etc());

        // A change to it is its own requirement; the first stays as it was
        UserServer::actingAs($this->owner)->tool(SaveRequirement::class, ['project' => 'Lakeshore', 'title' => 'Alpha too', 'changes' => 'REQ-1'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('code', 'REQ-2')->where('kind', 'change')->where('changes', 'REQ-1')->etc());

        UserServer::actingAs($this->owner)->tool(ListRequirements::class, ['project' => 'Lakeshore', 'part' => 'CMP-3', 'status' => 'all'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('requirements', fn ($all) => collect($all)->pluck('code')->all() === ['REQ-1'])
                ->etc());
    }

    public function test_the_admin_server_keeps_the_product_as_the_user_it_is_given()
    {
        config(['services.mcp.global_token' => 'secret']);

        GlobalServer::tool(UpdateProduct::class, [
            'user_id' => $this->owner->id,
            'project' => 'Lakeshore',
            'add' => [['kind' => 'component', 'name' => 'Board']],
        ])->assertOk();

        $this->assertSame([['made', $this->owner->id]], $this->project->productElements()->sole()->events()->get()->map(fn ($event) => [$event->what, $event->user_id])->all());
    }
}
