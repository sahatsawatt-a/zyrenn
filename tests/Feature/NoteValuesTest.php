<?php

namespace Tests\Feature;

use App\Events\ValuesChanged;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Notes\CreateNote;
use App\Mcp\Tools\Notes\GetNote;
use App\Mcp\Tools\Tables\CreateTable;
use App\Models\Note\Note;
use App\Models\Table\Table;
use App\Models\User;
use App\Support\Markdown\TiptapMarkdown;
use App\Support\Table\TableStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * A note's live values, {{ … }}: only the formula is kept in it; what it comes
 * to is worked out each time, among the same owner's trips and tables.
 */
class NoteValuesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        UserServer::actingAs($this->user)->tool(CreateTable::class, [
            'title' => 'Budget',
            'parameters' => ['rate' => 5],
            'columns' => [['label' => 'CNY', 'type' => 'numeric'], ['label' => 'THB', 'type' => 'formula', 'expression' => 'cny * rate']],
            'rows' => [['CNY' => 100], ['CNY' => 475]],
        ])->assertOk();
    }

    private function note(string $markdown): Note
    {
        UserServer::actingAs($this->user)->tool(CreateNote::class, ['title' => 'Plan', 'markdown' => $markdown])->assertOk();

        return Note::query()->latest('id')->firstOrFail();
    }

    public function test_a_value_is_kept_as_its_formula_and_comes_back_the_same()
    {
        $markdown = 'Total: {{ sum(table("Budget").thb) }} baht, ${{x}}$ stays math, and {{ }} or {{a{b}} stay text.';
        $doc = TiptapMarkdown::toDoc($markdown);
        $inline = $doc['content'][0]['content'];

        $this->assertSame(['type' => 'formula', 'attrs' => ['expression' => 'sum(table("Budget").thb)']], $inline[1]);
        $this->assertSame('Total: {{ sum(table("Budget").thb) }} baht,', trim(explode(' $', TiptapMarkdown::toMarkdown($doc))[0]));
        $this->assertStringContainsString('{{ }} or {{a{b}} stay text.', TiptapMarkdown::toMarkdown($doc));
    }

    public function test_the_page_asks_what_its_values_come_to()
    {
        $note = $this->note('Spent {{ sum(table("Budget").thb) }}, from {{ table("Budget").cny }}.');

        // Formulas have dots in them, so the answer is read whole rather than by path
        $values = $this->actingAs($this->user)
            ->postJson(route('notes.values', $note), ['expressions' => ['sum(table("Budget").thb)', 'table("Budget").cny', 'trip("Nowhere").nights', '1 +']])
            ->assertOk()
            ->json('values');

        $this->assertEquals([
            'sum(table("Budget").thb)' => ['value' => 2875, 'text' => '2875'],
            'table("Budget").cny' => ['value' => '100, 475', 'text' => '100, 475'],
            'trip("Nowhere").nights' => ['error' => 'There is no trip called "Nowhere" here.'],
            '1 +' => ['error' => 'Expected a value but found the end of the formula at character 4.'],
        ], $values);

        // Only for those who can read the note
        $this->actingAs(User::factory()->create())
            ->postJson(route('notes.values', $note), ['expressions' => ['1']])
            ->assertForbidden();
    }

    public function test_get_note_says_what_each_value_comes_to()
    {
        $note = $this->note("# Budget\n\nIn baht: {{ sum(table(\"Budget\").thb) }}\n\nBroken: {{ 1 / 0 }}");

        UserServer::actingAs($this->user)->tool(GetNote::class, ['ref_id' => $note->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('markdown', "# Budget\n\nIn baht: {{ sum(table(\"Budget\").thb) }}\n\nBroken: {{ 1 / 0 }}\n")
                ->where('values', ['sum(table("Budget").thb)' => '2875', '1 / 0' => ['error' => 'Dividing by zero.']])
                ->etc());
    }

    public function test_an_open_note_is_told_when_what_its_values_read_changes()
    {
        $reading = $this->note('{{ sum(table("Budget").thb) }}');
        $this->note('{{ 1 + 1 }} and table("Budget") in plain words');

        Event::fake([ValuesChanged::class]);

        TableStorage::updateRow(Table::query()->where('title', 'Budget')->sole(), 1, ['cny' => 200]);

        Event::assertDispatched(ValuesChanged::class, fn (ValuesChanged $event) => $event->note->is($reading));
        Event::assertDispatchedTimes(ValuesChanged::class, 1);
    }
}
