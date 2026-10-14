<?php

namespace Tests\Feature;

use App\Models\Agent\Agent;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Connection;
use App\Models\Drive\DriveFile;
use App\Models\Knowledge\KnowledgeBase;
use App\Models\Note\Note;
use App\Models\Project;
use App\Models\Table\Table;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Support\Knowledge\Knowledge;
use App\Support\Markdown\TiptapMarkdown;
use App\Support\Table\TableStorage;
use App\Support\Workflow\References;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * What AI answers from: knowledge bases read from notes, tables and Drive
 * files and searched by meaning or by words -- and a project's AI desk,
 * which answers its customers' questions from one and leaves their requests
 * for a bug fix or a feature to a person, who makes a ticket of them.
 */
class KnowledgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database', 'services.workflows.public_url' => 'https://hooks.example.test']);
        Http::preventStrayRequests();
    }

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

    private function note(Project $project, User $by, string $title, string $markdown, ?string $folder = null): Note
    {
        $note = new Note;
        $note->project_id = $project->id;
        $note->created_by = $by->id;
        $note->title = $title;
        $note->content = TiptapMarkdown::toDoc($markdown);
        $note->folder_id = $folder === null ? null : $project->noteFolders()->create(['name' => $folder, 'created_by' => $by->id])->id;
        $note->save();

        return $note;
    }

    private function base(Project $project, User $by, array $sources, ?Connection $ai = null): KnowledgeBase
    {
        $base = new KnowledgeBase(['title' => 'Shop', 'sources' => $sources, 'connection_id' => $ai?->id, 'embed_model' => $ai ? 'nomic-embed-text' : null]);
        $base->ownedBy($project, $by)->save();

        return $base;
    }

    /**
     * An embedding model, faked: a text is as near another as the words
     * "open", "ship" and "price" it holds.
     */
    private function embeddings(): void
    {
        Http::fake(['ollama:11434/v1/embeddings' => function (Request $request) {
            $vector = fn (string $text) => array_map(fn (string $word) => str_contains(mb_strtolower($text), $word) ? 1.0 : 0.0, ['open', 'ship', 'price']);

            return Http::response(['data' => array_map(fn (string $text, int $at) => ['index' => $at, 'embedding' => $vector($text)], $request['input'], array_keys($request['input']))]);
        }]);
    }

    private function ollama(Project $project, User $by): Connection
    {
        return Connection::factory()->forProject($project, $by)->create([
            'name' => 'Local', 'service' => 'ollama', 'settings' => ['base_url' => 'http://ollama:11434', 'default_model' => 'qwen2.5:7b'],
        ]);
    }

    public function test_notes_tables_and_files_are_read_into_passages_and_found_by_meaning()
    {
        $this->embeddings();
        [$project, $owner] = $this->team();
        $this->note($project, $owner, 'Opening hours', 'We are open from 9 to 6, Monday to Saturday.', 'FAQ');
        $this->note($project, $owner, 'Shipping', 'We ship anywhere in two days.', 'FAQ');
        $this->note($project, $owner, 'Private plans', 'Nobody outside should see this.');
        $table = Table::factory()->create(['project_id' => $project->id, 'user_id' => null, 'title' => 'Prices']);
        TableStorage::newColumn($table, ['label' => 'Item', 'type' => 'varchar']);
        TableStorage::newColumn($table, ['label' => 'Price', 'type' => 'varchar']);
        $table = $table->fresh('columns');
        $item = $table->columns->firstWhere('label', 'Item')->name;
        $price = $table->columns->firstWhere('label', 'Price')->name;
        TableStorage::insertRow($table, [$item => 'Mug', $price => '250 baht']);
        $temp = (string) tempnam(sys_get_temp_dir(), 'kb-');
        file_put_contents($temp, "Returns\n\nA price paid is given back within 7 days.");
        DriveFile::keep($temp, 'returns.md', $project);
        unlink($temp);

        $base = $this->base($project, $owner, [['kind' => 'notes', 'ref' => 'FAQ'], ['kind' => 'table', 'ref' => $table->ref_id], ['kind' => 'drive', 'ref' => '']], $this->ollama($project, $owner));
        $knowledge = app(Knowledge::class);
        $knowledge->read($base);

        $this->assertSame([KnowledgeBase::IDLE, null], [$base->state, $base->problem]);
        $this->assertNotNull($base->read_at);
        // Only the folder asked for: the note outside it is not read
        $this->assertEqualsCanonicalizing(['Opening hours', 'Shipping', 'Prices', 'returns.md'], $base->chunks()->pluck('title')->all());
        $this->assertStringContainsString('Item: Mug; Price: 250 baht', $base->chunks()->where('source_kind', 'table')->value('text'));

        $found = $knowledge->find($base, 'When are you open?', 2);
        $this->assertSame('Opening hours', $found[0]['title']);
        $this->assertStringContainsString('9 to 6', Knowledge::context($found));

        // Read again with nothing changed: no passage is sent to the model a second time
        $asked = 0;
        Http::fake(['ollama:11434/v1/embeddings' => function () use (&$asked) {
            $asked++;

            return Http::response(['data' => []]);
        }]);
        $knowledge->read($base->fresh());
        $this->assertSame([0, KnowledgeBase::IDLE], [$asked, $base->fresh()->state]);
    }

    public function test_without_a_model_passages_are_found_by_their_words_thai_too()
    {
        [$project, $owner] = $this->team();
        $this->note($project, $owner, 'เวลาเปิด', 'ร้านเปิดทุกวัน 9 โมงถึง 6 โมงเย็น');
        $this->note($project, $owner, 'การส่ง', 'ส่งทั่วประเทศภายในสองวัน');
        $base = $this->base($project, $owner, [['kind' => 'notes', 'ref' => '']]);
        $knowledge = app(Knowledge::class);
        $knowledge->read($base);

        $this->assertSame('เวลาเปิด', $knowledge->find($base, 'ร้านเปิดกี่โมง', 1)[0]['title']);
        $this->assertSame([], $knowledge->find($base, 'zzzz', 3));
    }

    public function test_a_projects_knowledge_is_set_up_by_its_owners_and_editors_and_tried_by_all()
    {
        [$project, $owner, $viewer] = $this->team();
        $this->note($project, $owner, 'Opening hours', 'We are open from 9 to 6.');

        $this->actingAs($viewer)->post(route('projects.knowledge.store', $project), ['title' => 'Shop'])->assertForbidden();
        $this->actingAs($owner)->post(route('projects.knowledge.store', $project), ['title' => 'Shop'])->assertRedirect();
        $base = KnowledgeBase::query()->of($project)->sole();
        // Made reading every note, at once
        $this->assertSame([['kind' => 'notes', 'ref' => '']], $base->sources);
        $this->work();
        $this->assertSame(1, $base->chunks()->count());

        $this->actingAs($viewer)->get(route('projects.knowledge.index', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->component('knowledge/Index')
                ->where('bases.0.sources.0.label', 'Notes: all notes')
                ->where('bases.0.passages', 1)
                ->where('can.manage', false));
        $this->actingAs($viewer)->postJson(route('knowledge.ask', $base), ['question' => 'open hours'])
            ->assertOk()
            ->assertJsonPath('found.0.title', 'Opening hours');
        $this->actingAs($viewer)->patch(route('knowledge.update', $base), ['title' => 'Mine'])->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson(route('knowledge.ask', $base), ['question' => 'x'])->assertForbidden();

        // A table of another owner's is not one it can read
        $theirs = Table::factory()->create(['title' => 'Elsewhere']);
        $this->actingAs($owner)->patch(route('knowledge.update', $base), ['sources' => [['kind' => 'table', 'ref' => $theirs->ref_id]]])->assertSessionHasErrors('sources');
        $this->actingAs($owner)->patch(route('knowledge.update', $base), ['sources' => [['kind' => 'notes', 'ref' => '/FAQ/'], ['kind' => 'notes', 'ref' => 'faq']]])->assertRedirect();
        $this->assertSame([['kind' => 'notes', 'ref' => 'FAQ']], $base->fresh()->sources);

        $this->actingAs($owner)->delete(route('knowledge.destroy', $base))->assertRedirect();
        $this->assertModelMissing($base);
    }

    public function test_the_ai_desk_answers_a_question_and_leaves_a_request_for_a_person()
    {
        $this->embeddings();
        $replies = [
            ['kind' => 'question', 'answered' => true, 'reply' => 'We open at 9.', 'summary' => 'Asks when we open'],
            ['kind' => 'feature', 'answered' => false, 'reply' => 'Thank you, the team will look at it.', 'summary' => 'Wants gift wrapping'],
        ];
        $prompts = [];
        Http::fake([
            'ollama:11434/v1/embeddings' => fn (Request $request) => Http::response(['data' => [['index' => 0, 'embedding' => [1.0, 0.0, 0.0]]]]),
            'ollama:11434/v1/chat/completions' => function (Request $request) use (&$replies, &$prompts) {
                $prompts[] = $request['messages'];
                $said = json_encode(array_shift($replies));

                return Http::response('data: '.json_encode(['choices' => [['delta' => ['content' => $said]]]])."\n\ndata: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream']);
            },
        ]);
        [$project, $owner] = $this->team();
        $ai = $this->ollama($project, $owner);
        $this->note($project, $owner, 'Opening hours', 'We are open from 9 to 6.');
        $base = $this->base($project, $owner, [['kind' => 'notes', 'ref' => '']], $ai);
        app(Knowledge::class)->read($base);

        // The desk, from its template, with the knowledge base chosen after
        // Its agent, made from the "Customer desk" preset: the connection and the knowledge base made last
        $this->actingAs($owner)->post(route('projects.agents.store', $project), ['preset' => 'desk'])->assertRedirect();
        $agent = Agent::query()->of($project)->sole();
        $this->assertSame([$ai->id, $base->id, 1], [$agent->connection_id, $agent->knowledge_base_id, $agent->version]);

        $this->actingAs($owner)->post(route('projects.workflows.store', $project), ['template' => 'ai-desk'])->assertRedirect();
        $workflow = $project->workflows()->sole();
        $definition = $workflow->definition;
        $definition['nodes'] = array_map(fn (array $node) => $node['key'] === 'ask' ? [...$node, 'config' => [...$node['config'], 'agent' => $agent->ref_id]] : $node, $definition['nodes']);
        $workflow->forceFill(['definition' => $definition])->save();
        $this->assertSame([], References::allProblems($workflow->fresh()));
        $this->actingAs($owner)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->fresh()->revision])->assertOk();
        $this->actingAs($owner)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertOk();

        $room = ChatRoom::startGroup($project, $owner, 'Ada on LINE');
        $room->forceFill(['link' => ['service' => 'line', 'connection' => 'c', 'chat' => 'U1']])->save();
        $room->becomeCustomers();
        $customer = fn (string $words) => $room->messages()->create(['role' => ChatMessage::WORKFLOW, 'content' => $words, 'meta' => ['name' => 'Ada (LINE)', 'outside' => true]]);

        // A question: answered from what the base knows, and the chat waits on them
        $customer('When do you open?');
        $this->work();
        $answer = $room->messages()->reorder('id', 'desc')->first();
        $this->assertSame(['We open at 9.', false, false], [$answer->content, $answer->isFromOutside(), $answer->isInternal()]);
        // Kept with it, for the project to check it by: what it answered from
        $this->assertSame('Opening hours', $answer->toChat()['sources'][0]['title']);
        $this->assertSame(ChatRoom::WAITING, $room->fresh()->status);
        $this->assertStringContainsString('We are open from 9 to 6.', $prompts[0][1]['content']);
        $this->assertStringContainsString('Customer: When do you open?', $prompts[0][1]['content']);

        // A request: thanked for, tagged, noted for a person, and left open
        $customer('Could you gift wrap orders?');
        $this->work();
        $said = $room->messages()->reorder('id', 'desc')->limit(2)->get()->reverse()->values();
        $this->assertSame(['Thank you, the team will look at it.', false], [$said[0]->content, $said[0]->isInternal()]);
        $this->assertTrue($said[1]->isInternal());
        $this->assertStringContainsString('feature request: Wants gift wrapping', $said[1]->content);
        $room->refresh();
        $this->assertSame([ChatRoom::OPEN, ['feature']], [$room->status, $room->tags]);

        // Someone takes the chat: AI keeps out of it
        $room->forceFill(['assigned_to' => $owner->id])->save();
        $customer('Hello?');
        $this->work();
        $this->assertCount(2, $prompts);
        $this->assertSame('Hello?', $room->messages()->reorder('id', 'desc')->first()->content);
    }

    public function test_a_chat_trigger_hears_only_customers_or_only_answers_as_it_is_set()
    {
        [$project, $owner] = $this->team();
        $room = ChatRoom::startGroup($project, $owner, 'Ada on LINE');
        $room->forceFill(['link' => ['service' => 'line', 'connection' => 'c', 'chat' => 'U1']])->save();
        $room->becomeCustomers();
        $listening = function (string $include, string $where) use ($project, $owner) {
            $workflow = Workflow::factory()->forProject($project, $owner)->steps([
                ['id' => 'heard', 'key' => 'heard', 'type' => 'trigger.chat', 'position' => ['x' => 0, 'y' => 0], 'config' => ['room' => $where, 'include' => $include]],
            ])->create();
            $this->actingAs($owner)->postJson(route('workflows.publish', $workflow), ['revision' => $workflow->revision])->assertOk();
            $this->actingAs($owner)->patchJson(route('workflows.enable', $workflow), ['enabled' => true])->assertOk();

            return $workflow;
        };
        $outside = $listening('outside', References::CUSTOMERS);
        $answers = $listening('answers', $room->ref_id);

        $room->messages()->create(['role' => ChatMessage::WORKFLOW, 'content' => 'Hi', 'meta' => ['name' => 'Ada (LINE)', 'outside' => true]]);
        $room->messages()->create(['role' => ChatMessage::WORKFLOW, 'content' => 'AI answer', 'meta' => ['name' => 'AI']]);
        $room->say($owner, 'A person answers');
        $room->say($owner, 'A note', ['internal' => true]);

        $this->assertSame(['Hi'], $outside->runs()->get()->map(fn ($run) => $run->trigger_data['text'])->all());
        $this->assertSame(['AI answer', 'A person answers'], $answers->runs()->orderBy('id')->get()->map(fn ($run) => $run->trigger_data['text'])->all());

        // Another project's chats are never heard by this one's
        $other = Project::factory()->withMember($owner)->create();
        $elsewhere = ChatRoom::startGroup($other, $owner, 'Bo');
        $elsewhere->forceFill(['link' => ['service' => 'line', 'connection' => 'c', 'chat' => 'U2']])->save();
        $elsewhere->becomeCustomers();
        $elsewhere->messages()->create(['role' => ChatMessage::WORKFLOW, 'content' => 'Elsewhere', 'meta' => ['name' => 'Bo', 'outside' => true]]);
        $this->assertSame(1, $outside->runs()->count());
    }
}
