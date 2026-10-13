<?php

namespace Tests\Feature;

use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Notes\CreateNote;
use App\Mcp\Tools\Notes\EditNote;
use App\Mcp\Tools\Notes\GetNote;
use App\Mcp\Tools\Notes\UpdateNote;
use App\Models\Board\Board;
use App\Models\Note\Note;
use App\Models\User;
use App\Support\Markdown\TiptapMarkdown;
use App\Support\Note\PageLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * Links from a note to another page by its name: written [[Title]], kept by
 * the page's ref_id, and known to the page linked to.
 */
class PageLinksTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The page links in a note's content, in order.
     *
     * @return list<array<string, mixed>>
     */
    private function links(Note $note): array
    {
        $found = [];

        $walk = function (array $node) use (&$walk, &$found) {
            if (($node['type'] ?? '') === PageLinks::NODE) {
                $found[] = $node['attrs'];
            }

            foreach ($node['content'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($note->refresh()->content ?? []);

        return $found;
    }

    private function note(User $user, string $title, string $markdown = ''): Note
    {
        $note = (new Note(['title' => $title, 'content' => TiptapMarkdown::toDoc($markdown)]))->ownedBy($user, $user);
        $note->save();

        return $note;
    }

    public function test_a_title_in_double_brackets_links_to_the_page_of_that_name()
    {
        $user = User::factory()->create();
        $budget = $this->note($user, 'Budget');

        UserServer::actingAs($user)
            ->tool(CreateNote::class, ['title' => 'Plan', 'markdown' => 'Costs are in [[budget]], or see [[Budget|the numbers]].'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->missing('unlinked')->etc());

        $plan = $user->notes()->where('title', 'Plan')->sole();

        // Found whatever the case, kept by ref_id, and named as the page is
        $this->assertEquals([
            ['kind' => 'note', 'ref' => $budget->ref_id, 'title' => 'Budget', 'text' => ''],
            ['kind' => 'note', 'ref' => $budget->ref_id, 'title' => 'Budget', 'text' => 'the numbers'],
        ], $this->links($plan));

        $this->assertSame([['kind' => 'note', 'ref' => $budget->ref_id]], PageLinks::found($plan->content));

        // The page linked to knows of it
        UserServer::actingAs($user)
            ->tool(GetNote::class, ['ref_id' => $budget->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('linked_from', [['ref_id' => $plan->ref_id, 'title' => 'Plan']])
                ->etc()
            );
    }

    public function test_a_link_follows_its_page_through_a_rename()
    {
        $user = User::factory()->create();
        $budget = $this->note($user, 'Budget');
        $plan = $this->note($user, 'Plan', 'See [[Budget]].');

        $budget->update(['title' => 'Budget 2027']);

        UserServer::actingAs($user)
            ->tool(GetNote::class, ['ref_id' => $plan->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('markdown', "See [[Budget 2027]].\n")
                ->etc()
            );

        $this->assertSame($budget->ref_id, $this->links($plan)[0]['ref']);
    }

    public function test_a_name_nothing_goes_by_is_kept_and_said()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(CreateNote::class, ['title' => 'Plan', 'markdown' => 'See [[Nowhere]].'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('unlinked', ['Nowhere'])->etc());

        $plan = $user->notes()->sole();

        $this->assertSame('', $this->links($plan)[0]['ref']);
        $this->assertSame('See [[Nowhere]].', trim((string) $plan->plain_text));

        // Made later, the page is found the next time the note is saved
        $nowhere = $this->note($user, 'Nowhere');

        UserServer::actingAs($user)
            ->tool(UpdateNote::class, ['ref_id' => $plan->ref_id, 'markdown' => 'See [[Nowhere]].'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->missing('unlinked')->etc());

        $this->assertSame($nowhere->ref_id, $this->links($plan)[0]['ref']);
    }

    public function test_a_board_can_be_linked_to_by_name_too()
    {
        $user = User::factory()->create();
        $board = Board::factory()->for($user)->create(['title' => 'Launch']);
        $this->note($user, 'Roadmap');

        $plan = $this->note($user, 'Plan', 'On [[Launch]], [[board:launch]] and [[note:Roadmap]], not [[board:Roadmap]].');
        $links = $this->links($plan);

        // A bare title is a note first, then whatever else goes by it
        $this->assertSame(['board', $board->ref_id], [$links[0]['kind'], $links[0]['ref']]);
        $this->assertSame(['board', $board->ref_id], [$links[1]['kind'], $links[1]['ref']]);
        $this->assertSame('note', $links[2]['kind']);
        $this->assertSame('', $links[3]['ref']);

        $this->assertSame(
            'On [[board:Launch]], [[board:Launch]] and [[Roadmap]], not [[board:Roadmap]].',
            trim(TiptapMarkdown::toMarkdown(PageLinks::named($plan->content, $user))),
        );

        $this->assertSame([$plan->ref_id], array_column(PageLinks::linkedFrom($board, $user), 'ref_id'));
    }

    public function test_one_of_two_pages_of_a_name_is_written_by_its_ref_id()
    {
        $user = User::factory()->create();
        $first = $this->note($user, 'Notes');
        $this->note($user, 'Notes');

        $plan = $this->note($user, 'Plan', "See [[{$first->ref_id}]].");

        $this->assertSame($first->ref_id, $this->links($plan)[0]['ref']);

        // Read back, it is the same page again, and shown by its name
        $markdown = trim(TiptapMarkdown::toMarkdown(PageLinks::named($plan->content, $user)));

        $this->assertSame("See [[{$first->ref_id}|Notes]].", $markdown);

        $again = $this->note($user, 'Again', $markdown);

        $this->assertEquals(
            ['kind' => 'note', 'ref' => $first->ref_id, 'title' => 'Notes', 'text' => ''],
            $this->links($again)[0],
        );
    }

    public function test_an_ordinary_link_to_a_page_counts_as_a_link_to_it()
    {
        $user = User::factory()->create();
        $budget = $this->note($user, 'Budget');
        $plan = $this->note($user, 'Plan', "See [the budget](/notes/{$budget->ref_id}) and [it again](https://app.test/notes/{$budget->ref_id}?x=1).");

        $this->assertSame([$plan->ref_id], array_column(PageLinks::linkedFrom($budget, $user), 'ref_id'));
        $this->assertSame(1, DB::table('note_links')->where('note_id', $plan->id)->count());

        // A note does not link to itself, and a link taken out is gone
        $budget->update(['content' => TiptapMarkdown::toDoc("[Me](/notes/{$budget->ref_id})")]);
        $plan->update(['content' => TiptapMarkdown::toDoc('Nothing now.')]);

        $this->assertSame(0, DB::table('note_links')->count());

        $plan = $this->note($user, 'Other', '[[Budget]]');
        $plan->delete();

        $this->assertSame(0, DB::table('note_links')->count());
    }

    public function test_a_link_written_into_part_of_a_note_is_found_too()
    {
        $user = User::factory()->create();
        $budget = $this->note($user, 'Budget');
        $plan = $this->note($user, 'Plan', 'First.');

        UserServer::actingAs($user)
            ->tool(EditNote::class, ['ref_id' => $plan->ref_id, 'changes' => [['op' => 'append', 'markdown' => 'See [[Budget]] and [[Missing]].']]])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('unlinked', ['Missing'])->etc());

        $this->assertSame([$budget->ref_id, ''], array_column($this->links($plan), 'ref'));
    }

    public function test_only_the_owners_pages_are_found_or_offered()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $theirs = $this->note($other, 'Secret plan');
        $mine = $this->note($user, 'Plan', 'See [[Secret plan]].');

        $this->assertSame('', $this->links($mine)[0]['ref']);

        $this->actingAs($user)
            ->getJson(route('notes.page-links.pick', ['note' => $mine, 'q' => 'plan']))
            ->assertOk()
            ->assertJsonPath('pages', []);

        // Named outright by ref_id, another's page is still not the reader's to see
        $this->actingAs($user)
            ->postJson(route('notes.page-links.show', $mine), ['links' => [['kind' => 'note', 'ref' => $theirs->ref_id, 'title' => 'x']]])
            ->assertOk()
            ->assertJsonPath('links.0.found', false)
            ->assertJsonPath('links.0.url', null);

        $this->actingAs($other)->getJson(route('notes.linked-from', $mine))->assertForbidden();
    }

    public function test_the_page_showing_a_note_can_ask_about_its_links()
    {
        $user = User::factory()->create();
        $budget = $this->note($user, 'Budget');
        $board = Board::factory()->for($user)->create(['title' => 'Budget board']);
        $plan = $this->note($user, 'Plan', 'See [[Budget]].');

        // What there is to link to: a title that starts with the letters first, never the note itself
        $this->actingAs($user)
            ->getJson(route('notes.page-links.pick', ['note' => $plan, 'q' => 'budget']))
            ->assertOk()
            ->assertJsonPath('pages.0.title', 'Budget')
            ->assertJsonPath('pages.1', ['kind' => 'board', 'ref' => $board->ref_id, 'title' => 'Budget board', 'folder' => null])
            ->assertJsonCount(2, 'pages');

        // What a link points at now, by ref_id or by the name it wants
        $this->actingAs($user)
            ->postJson(route('notes.page-links.show', $plan), ['links' => [
                ['kind' => 'note', 'ref' => $budget->ref_id, 'title' => 'old name'],
                ['kind' => '', 'ref' => '', 'title' => 'budget board'],
                ['kind' => '', 'ref' => '', 'title' => 'nothing'],
            ]])
            ->assertOk()
            ->assertJsonPath('links.0', ['kind' => 'note', 'ref' => $budget->ref_id, 'title' => 'Budget', 'url' => "/notes/{$budget->ref_id}", 'found' => true])
            ->assertJsonPath('links.1.ref', $board->ref_id)
            ->assertJsonPath('links.1.url', "/boards/{$board->ref_id}")
            ->assertJsonPath('links.2.found', false);

        $this->actingAs($user)
            ->getJson(route('notes.linked-from', $budget))
            ->assertOk()
            ->assertJsonPath('notes', [['ref_id' => $plan->ref_id, 'title' => 'Plan', 'url' => "/notes/{$plan->ref_id}", 'folder' => null]]);
    }
}
