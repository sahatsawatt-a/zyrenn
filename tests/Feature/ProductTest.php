<?php

namespace Tests\Feature;

use App\Models\Agent\Agent;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Connection;
use App\Models\Product\ProductElement;
use App\Models\Product\Requirement;
use App\Models\Project;
use App\Models\Ticket\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A project's product, tracked: a tree of components and capabilities with
 * codes never given twice, what each uses apart from where it sits and what
 * a change would reach, requests kept word for word, requirements verified
 * only by their criteria, and coverage of the trail between them.
 */
class ProductTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Project, 1: User, 2: User}
     */
    private function team(): array
    {
        [$owner, $viewer] = User::factory()->count(2)->create()->all();
        $project = Project::factory()->withMember($owner)->create();
        $project->members()->attach($viewer, ['role' => Project::VIEWER]);

        return [$project, $owner, $viewer];
    }

    private function part(Project $project, User $owner, string $name, string $kind = 'component', ?ProductElement $under = null, bool $shared = false): ProductElement
    {
        $this->actingAs($owner)->post(route('projects.product.elements.store', $project), ['kind' => $kind, 'name' => $name, 'parent' => $under?->ref_id, 'shared' => $shared])->assertRedirect();

        return $project->productElements()->latest('id')->firstOrFail();
    }

    public function test_the_tree_has_codes_never_given_twice_and_counts_what_is_under_each_part()
    {
        [$project, $owner, $viewer] = $this->team();
        $board = $this->part($project, $owner, 'Board');
        $shape = $this->part($project, $owner, 'Shape', under: $board);
        $border = $this->part($project, $owner, 'Change border colour', 'capability', $shape);
        $fill = $this->part($project, $owner, 'Change fill colour', 'capability', $shape);
        $gone = $this->part($project, $owner, 'Gradient', 'capability', $shape);

        $this->assertSame(['CMP-1', 'CMP-2', 'CAP-1', 'CAP-2', 'CAP-3'], [$board->code, $shape->code, $border->code, $fill->code, $gone->code]);

        // Taken away, its code is not given again
        $this->actingAs($owner)->delete(route('product.elements.destroy', $gone))->assertRedirect();
        $this->assertSame('CAP-4', $this->part($project, $owner, 'Opacity', 'capability', $shape)->code);
        // Nor is a part with others under it taken away
        $this->actingAs($owner)->delete(route('product.elements.destroy', $shape))->assertSessionHasErrors('element');

        $this->actingAs($owner)->patch(route('product.elements.update', $border), ['status' => 'live'])->assertRedirect();
        $this->actingAs($owner)->patch(route('product.elements.update', $fill), ['status' => 'building'])->assertRedirect();
        // Never under itself, nor under what is under it
        $this->actingAs($owner)->patch(route('product.elements.update', $board), ['parent' => $shape->ref_id])->assertSessionHasErrors('parent');

        // A component sums up its capabilities, at any depth: counted, not a percentage
        $this->actingAs($viewer)->get(route('projects.product.index', $project))->assertInertia(fn (Assert $page) => $page
            ->component('product/Index')
            ->where('elements', fn ($elements) => collect($elements)->firstWhere('code', 'CMP-1')['counts'] == ['live' => 1, 'building' => 1, 'planned' => 1])
            ->where('can.change', false));
        $this->actingAs($viewer)->post(route('projects.product.elements.store', $project), ['kind' => 'component', 'name' => 'Mine'])->assertForbidden();

        // Its history: made, then its status
        $this->assertSame(['status', 'made'], $border->events()->pluck('what')->all());
    }

    public function test_a_change_to_a_shared_part_shows_what_it_would_reach()
    {
        [$project, $owner] = $this->team();
        $picker = $this->part($project, $owner, 'Colour picker', shared: true);
        $border = $this->part($project, $owner, 'Change border colour', 'capability');
        $fill = $this->part($project, $owner, 'Change fill colour', 'capability');
        $theme = $this->part($project, $owner, 'Themes', 'capability');

        foreach ([[$border, $picker, 'uses'], [$fill, $picker, 'uses'], [$theme, $fill, 'depends_on']] as [$from, $to, $kind]) {
            $this->actingAs($owner)->post(route('product.elements.link', $from), ['to' => $to->ref_id, 'kind' => $kind])->assertRedirect();
        }

        $this->actingAs($owner)->post(route('product.elements.link', $border), ['to' => $border->ref_id, 'kind' => 'uses'])->assertSessionHasErrors('to');

        $bug = Ticket::file($project, $owner, ['type' => Ticket::BUG, 'title' => 'Fill colour lost on undo']);
        $this->actingAs($owner)->post(route('product.elements.ticket', $fill), ['number' => $bug->number, 'linked' => true])->assertRedirect();
        $this->actingAs($owner)->post(route('projects.product.requirements.store', $project), ['title' => 'Type a colour as HEX', 'element' => $picker->ref_id])->assertRedirect();

        $impact = $this->actingAs($owner)->getJson(route('product.elements.show', $picker))->assertOk()->json('impact');

        $this->assertEqualsCanonicalizing(['CAP-1', 'CAP-2'], array_column($impact['direct'], 'code'));
        // And what depends on those, a step further
        $this->assertSame(['CAP-3'], array_column($impact['further'], 'code'));
        $this->assertSame(['Type a colour as HEX'], array_column($impact['requirements'], 'title'));
        $this->assertSame([$bug->number], array_column($impact['open_tickets'], 'number'));
    }

    public function test_a_request_is_kept_word_for_word_and_worked_out_into_requirements()
    {
        [$project, $owner] = $this->team();
        $this->actingAs($owner)->post(route('projects.product.requests.store', $project), ['original' => 'Make the colour picking better.', 'from_name' => 'Ada'])->assertRedirect();
        $request = $project->productRequests()->sole();
        $this->assertSame(['RQ-1', 'typed', 'new'], [$request->code, $request->source, $request->status]);

        // What was asked never changes
        $this->actingAs($owner)->patch(route('product.requests.update', $request), ['original' => 'Something else', 'status' => 'analysing'])->assertSessionHasErrors('original');
        $this->assertSame('Make the colour picking better.', $request->fresh()->original);

        $this->actingAs($owner)->post(route('projects.product.requirements.store', $project), ['title' => 'Type a colour as HEX', 'need' => 'Faster colour picking', 'request' => $request->ref_id])->assertRedirect();
        $requirement = $project->requirements()->sole();
        $this->assertSame(['REQ-1', 'split'], [$requirement->code, $request->fresh()->status]);

        // Verified only once every criterion is met -- never because the work is done
        $this->actingAs($owner)->patch(route('product.requirements.update', $requirement), ['status' => 'verified'])->assertSessionHasErrors('status');
        $this->actingAs($owner)->patch(route('product.requirements.update', $requirement), [
            'criteria' => [['text' => 'A colour can be typed as #RRGGBB', 'met' => true], ['text' => 'A wrong one is refused', 'met' => false]],
            'questions' => ['RGB too?', ' '],
        ])->assertRedirect();
        $this->actingAs($owner)->patch(route('product.requirements.update', $requirement), ['status' => 'verified'])->assertSessionHasErrors('status');
        $this->actingAs($owner)->patch(route('product.requirements.update', $requirement), [
            'criteria' => [['text' => 'A colour can be typed as #RRGGBB', 'met' => true], ['text' => 'A wrong one is refused', 'met' => true]],
            'status' => 'verified',
        ])->assertRedirect();
        $this->assertSame(['verified', ['RGB too?']], [$requirement->fresh()->status, $requirement->fresh()->questions]);

        // A change to it is a requirement of its own, and the first stays as it was
        $this->actingAs($owner)->post(route('projects.product.requirements.store', $project), ['title' => 'Alpha too', 'changes' => $requirement->ref_id])->assertRedirect();
        $change = $project->requirements()->latest('id')->firstOrFail();
        $this->assertSame(['change', $requirement->id, 'verified'], [$change->kind, $change->changes_id, $requirement->fresh()->status]);
    }

    public function test_what_a_customer_wrote_is_kept_as_a_request_with_a_note_in_their_chat()
    {
        [$project, $owner, $viewer] = $this->team();
        $room = ChatRoom::startGroup($project, $owner, 'Ada on LINE');
        $room->forceFill(['link' => ['service' => 'line', 'connection' => 'c', 'chat' => 'U1']])->save();
        $room->becomeCustomers();
        $said = $room->messages()->create(['role' => ChatMessage::WORKFLOW, 'content' => 'Could you add gift wrapping?', 'meta' => ['name' => 'Ada', 'outside' => true]]);

        $this->actingAs($viewer)->post(route('chats.requests.store', $room), ['message' => $said->id])->assertForbidden();
        $this->actingAs($owner)->post(route('chats.requests.store', $room), ['message' => $said->id])->assertRedirect();

        $request = $project->productRequests()->sole();
        $this->assertSame(['Could you add gift wrapping?', 'chat', $said->id, 'Ada on LINE'], [$request->original, $request->source, $request->chat_message_id, $request->from_name]);
        $note = $room->messages()->reorder('id', 'desc')->first();
        $this->assertSame(['Kept as request RQ-1 of the product', true], [$note->content, $note->isInternal()]);

        // With a way back to the chat it was said in
        $this->actingAs($owner)->get(route('projects.product.index', $project))->assertInertia(fn (Assert $page) => $page
            ->where('requests.0.chat', $room->ref_id));
    }

    public function test_a_complaint_in_the_inbox_is_filed_as_a_bug_on_the_part_it_is_about()
    {
        [$project, $owner] = $this->team();
        $fill = $this->part($project, $owner, 'Change fill colour', 'capability');
        $this->actingAs($owner)->patch(route('product.elements.update', $fill), ['status' => 'live']);
        $room = ChatRoom::startGroup($project, $owner, 'Ada on LINE');
        $room->forceFill(['link' => ['service' => 'line', 'connection' => 'c', 'chat' => 'U1']])->save();
        $room->becomeCustomers();
        $said = $room->messages()->create(['role' => ChatMessage::WORKFLOW, 'content' => 'The fill colour goes back after undo', 'meta' => ['name' => 'Ada', 'outside' => true]]);

        // The parts are there to choose from
        $this->actingAs($owner)->get(route('chats.show', $room))->assertInertia(fn (Assert $page) => $page
            ->where('room.desk.parts.0.code', 'CAP-1'));

        // Not a part of another project's
        $other = Project::factory()->withMember($owner)->create();
        $theirs = $this->part($other, $owner, 'Theirs', 'capability');
        $this->actingAs($owner)->post(route('chats.tickets.store', $room), ['type' => 'bug', 'title' => 'Fill lost on undo', 'message' => $said->id, 'element' => $theirs->ref_id])
            ->assertSessionHasErrors('element');

        $this->actingAs($owner)->post(route('chats.tickets.store', $room), ['type' => 'bug', 'title' => 'Fill lost on undo', 'message' => $said->id, 'element' => $fill->ref_id])->assertRedirect();

        $bug = $project->tickets()->sole();
        $this->assertSame([$bug->id], $fill->tickets()->pluck('tickets.id')->all());
        $this->assertSame('Made ticket #1: Fill lost on undo, about CAP-1 Change fill colour', $room->messages()->reorder('id', 'desc')->first()->content);

        // The tree counts it, and coverage says the live part has a bug open
        $this->actingAs($owner)->get(route('projects.product.index', $project))->assertInertia(fn (Assert $page) => $page
            ->where('elements.0.open_bugs', 1)
            ->where('coverage.gaps', fn ($gaps) => collect($gaps)->contains(fn ($gap) => $gap['code'] === 'CAP-1' && $gap['kind'] === 'bugs')));
    }

    public function test_an_agent_works_a_request_out_into_requirements_that_a_person_keeps()
    {
        [$project, $owner, $viewer] = $this->team();
        $picker = $this->part($project, $owner, 'Colour picker', shared: true);
        $this->part($project, $owner, 'Change fill colour', 'capability', $picker);
        Connection::factory()->forProject($project, $owner)->create([
            'name' => 'Local', 'service' => 'ollama', 'settings' => ['base_url' => 'http://ollama:11434', 'default_model' => 'qwen2.5:7b'],
        ]);
        $this->actingAs($owner)->post(route('projects.agents.store', $project), ['preset' => 'analyst'])->assertRedirect();
        $agent = Agent::query()->of($project)->sole();
        $this->actingAs($owner)->post(route('projects.product.requests.store', $project), ['original' => 'Make the colour picking better: typing a colour, and the last few colours used.', 'from_name' => 'Ada']);
        $asked = $project->productRequests()->sole();

        // A model that answers as asked -- with one part it made up
        $says = json_encode(['requirements' => [
            ['title' => 'A colour can be typed as HEX', 'need' => 'Picking an exact colour is slow', 'kind' => 'change', 'understanding' => 'partial', 'criteria' => ['Typing #RRGGBB sets the colour', ''], 'questions' => ['RGB too?'], 'parts' => ['cmp-1', 'CAP-99']],
            ['title' => 'The last colours used are offered again', 'criteria' => ['The last 8 colours show under the picker'], 'parts' => ['CMP-1']],
        ], 'note' => 'Two things asked at once.']);
        $sent = [];
        Http::preventStrayRequests();
        Http::fake(['ollama:11434/v1/chat/completions' => function (Sent $request) use (&$says, &$sent) {
            $sent[] = $request->data();

            return Http::response('data: '.json_encode(['choices' => [['delta' => ['content' => $says]]]])."\n\ndata: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream']);
        }]);

        $this->actingAs($viewer)->postJson(route('product.requests.work-out', $asked), ['agent' => $agent->ref_id])->assertForbidden();
        $draft = $this->actingAs($owner)->postJson(route('product.requests.work-out', $asked), ['agent' => $agent->ref_id])->assertOk()->json();

        $this->assertSame(['A colour can be typed as HEX', 'The last colours used are offered again'], array_column($draft['requirements'], 'title'));
        $this->assertSame([['CMP-1'], ['Typing #RRGGBB sets the colour'], 'Two things asked at once.', 'Product analyst'], [$draft['requirements'][0]['parts'], $draft['requirements'][0]['criteria'], $draft['note'], $draft['agent']]);
        // It was told the product's parts and the request word for word -- and nothing is kept yet
        $this->assertStringContainsString('CAP-1 Change fill colour (under Colour picker) [planned]', $sent[0]['messages'][0]['content']);
        $this->assertStringContainsString('Make the colour picking better', $sent[0]['messages'][1]['content']);
        $this->assertSame(0, $project->requirements()->count());

        // An answer in some other shape is said to be so
        $says = 'Sure! Here are some ideas.';
        $this->actingAs($owner)->postJson(route('product.requests.work-out', $asked), ['agent' => $agent->ref_id])->assertOk()->assertJsonPath('failed', fn ($why) => str_contains($why, 'did not answer with requirements'));

        // A person keeps what they want of it, as they changed it
        $first = [...$draft['requirements'][0], 'title' => 'A colour can be typed as HEX or RGB'];
        $this->actingAs($owner)->post(route('product.requests.requirements.store', $asked), ['requirements' => [$first, $draft['requirements'][1]]])->assertRedirect();

        $kept = $project->requirements()->orderBy('id')->get();
        $this->assertSame(['REQ-1', 'REQ-2'], $kept->pluck('code')->all());
        $this->assertSame(['A colour can be typed as HEX or RGB', 'clarifying', 'change', [['text' => 'Typing #RRGGBB sets the colour', 'met' => false]], ['CMP-1']], [$kept[0]->title, $kept[0]->status, $kept[0]->kind, $kept[0]->criteria, $kept[0]->elements->pluck('code')->all()]);
        $this->assertSame(['draft', $asked->id, 'split'], [$kept[1]->status, $kept[1]->product_request_id, $asked->fresh()->status]);
    }

    public function test_coverage_shows_how_far_each_capability_trail_goes_and_its_gaps()
    {
        [$project, $owner] = $this->team();
        $asked = $this->part($project, $owner, 'Asked, not started', 'capability');
        $built = $this->part($project, $owner, 'Built without a reason', 'capability');
        $live = $this->part($project, $owner, 'Live, not verified', 'capability');
        $bare = $this->part($project, $owner, 'Live with nothing behind it', 'capability');

        $this->actingAs($owner)->post(route('projects.product.requirements.store', $project), ['title' => 'Do it', 'element' => $asked->ref_id]);
        $this->actingAs($owner)->post(route('projects.product.requirements.store', $project), ['title' => 'Do that', 'element' => $live->ref_id]);
        $work = Ticket::file($project, $owner, ['type' => Ticket::FEATURE, 'title' => 'Build it']);
        $this->actingAs($owner)->post(route('product.elements.ticket', $built), ['number' => $work->number, 'linked' => true]);
        $this->actingAs($owner)->post(route('product.elements.ticket', $live), ['number' => $work->number, 'linked' => true]);
        $this->actingAs($owner)->patch(route('product.elements.update', $live), ['status' => 'live']);
        $this->actingAs($owner)->patch(route('product.elements.update', $bare), ['status' => 'live']);

        $this->actingAs($owner)->get(route('projects.product.index', $project))->assertInertia(fn (Assert $page) => $page
            ->where('coverage.gaps', fn ($gaps) => collect($gaps)->map(fn ($gap) => "{$gap['code']}:{$gap['kind']}")->sort()->values()->all() === ['CAP-1:no_work', 'CAP-2:no_requirement', 'CAP-3:unverified', 'CAP-4:no_requirement'])
            ->where('coverage.rows', fn ($rows) => collect($rows)->firstWhere('code', 'CAP-3')['live'] === true && collect($rows)->firstWhere('code', 'CAP-3')['verified'] === false));
    }
}
