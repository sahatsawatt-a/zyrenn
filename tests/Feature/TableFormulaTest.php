<?php

namespace Tests\Feature;

use App\Models\Table\Table;
use App\Models\User;
use App\Support\Table\TableFormulas;
use App\Support\Table\TableStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A table that does its own sums: parameters the formulas share, formula
 * columns worked out for every row, and totals under the columns.
 */
class TableFormulaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Table $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->table = Table::factory()->for($this->user)->create();

        // A trip's budget, in yuan, for two
        $this->column('Item', 'varchar');
        $this->column('Day', 'integer');
        $this->column('CNY', 'numeric', ['summary' => 'sum']);
        $this->parameters([['name' => 'rate', 'value' => 5], ['name' => 'people', 'value' => 2], ['name' => 'start', 'value' => '2026-12-03']]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function column(string $label, string $type, array $extra = []): array
    {
        return $this->actingAs($this->user)
            ->postJson(route('tables.columns.store', $this->table), ['label' => $label, 'type' => $type, ...$extra])
            ->assertCreated()
            ->json('column');
    }

    /**
     * @param  list<array{name: string, value: mixed}>  $parameters
     */
    private function parameters(array $parameters): void
    {
        $this->actingAs($this->user)
            ->patchJson(route('tables.update', $this->table), ['parameters' => $parameters])
            ->assertOk();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function row(array $values): int
    {
        return TableStorage::insertRow($this->table->fresh(), $values);
    }

    public function test_a_formula_column_is_worked_out_for_every_row_and_kept_nowhere()
    {
        $this->column('THB', 'formula', ['expression' => 'cny * rate', 'summary' => 'sum', 'currencySymbol' => '฿']);
        $this->column('Each', 'formula', ['expression' => 'round(thb / people)']);
        $this->column('Date', 'formula', ['expression' => 'date(start) + day - 1']);
        $this->column('Label', 'formula', ['expression' => '"Day " & day & " · " & text(date, "D j M") & " · " & item']);

        $this->row(['item' => 'Disney', 'day' => 5, 'cny' => 475]);
        $this->row(['item' => 'Metro card', 'day' => 1, 'cny' => 20.5]);

        // Nothing for them where the rows are kept
        $this->assertFalse(Schema::hasColumn(TableStorage::physicalName($this->table), 'thb'));

        $this->actingAs($this->user)
            ->get(route('tables.show', $this->table))
            ->assertInertia(fn (Assert $page) => $page
                ->where('parameters.0', ['name' => 'rate', 'value' => 5])
                ->where('rows.0.thb', 2375)
                ->where('rows.0.each', 1188)
                ->where('rows.0.date', '2026-12-07')
                ->where('rows.0.label', 'Day 5 · Mon 7 Dec · Disney')
                ->where('rows.1.thb', 102.5)
                ->where('columns.4.expression', 'cny * rate')
                ->where('columns.4.summary', 'sum')
                ->etc());

        $this->assertSame(['cny' => 495.5, 'thb' => 2477.5], TableFormulas::totals($this->table->fresh()));
    }

    public function test_changing_a_value_or_a_parameter_works_the_row_out_again()
    {
        $this->column('THB', 'formula', ['expression' => 'cny * rate']);
        $id = $this->row(['cny' => 100]);

        // The one who typed it gets the row back with its formulas worked out again
        $this->actingAs($this->user)
            ->patchJson(route('tables.rows.update', [$this->table, $id]), ['column' => 'cny', 'value' => 120])
            ->assertOk()
            ->assertJsonPath('row.thb', 600);

        $this->parameters([['name' => 'rate', 'value' => 5.2]]);

        $this->assertSame(624.0, TableStorage::row($this->table->fresh(), $id)['thb']);
    }

    public function test_a_formula_that_cant_work_says_why_before_it_is_saved()
    {
        $this->actingAs($this->user)
            ->postJson(route('tables.columns.store', $this->table), ['label' => 'Bad', 'type' => 'formula', 'expression' => 'cny * fx'])
            ->assertJsonValidationErrors(['expression' => 'Nothing in this table is called "fx": name a column, a [Column label] or a parameter.']);

        $this->actingAs($this->user)
            ->postJson(route('tables.columns.store', $this->table), ['label' => 'Bad', 'type' => 'formula', 'expression' => 'cny *'])
            ->assertJsonValidationErrors(['expression' => 'Expected a value but found the end of the formula at character 6.']);

        $this->actingAs($this->user)
            ->postJson(route('tables.columns.store', $this->table), ['label' => 'Bad', 'type' => 'formula'])
            ->assertJsonValidationErrors(['expression']);

        // Two formulas that lead back to each other
        $a = $this->column('A', 'formula', ['expression' => 'cny + 1']);
        $this->column('B', 'formula', ['expression' => 'a * 2']);

        $this->actingAs($this->user)
            ->patchJson(route('tables.columns.update', [$this->table, $a['name']]), ['expression' => '[B] + 1'])
            ->assertJsonValidationErrors(['expression' => 'This formula comes back round to itself: a → b → a.']);

        // A formula column can't become anything else, or anything else a formula
        $this->actingAs($this->user)
            ->patchJson(route('tables.columns.update', [$this->table, 'cny']), ['type' => 'formula'])
            ->assertJsonValidationErrors(['type']);
    }

    public function test_a_row_that_cant_be_worked_out_says_why_in_its_cell()
    {
        $this->column('Per item', 'formula', ['expression' => 'cny / day']);
        $this->column('Doubled', 'formula', ['expression' => '[Per item] * 2']);
        $id = $this->row(['cny' => 100, 'day' => 0]);

        $row = TableStorage::row($this->table->fresh(), $id);

        $this->assertSame(['error' => 'Dividing by zero.'], $row['per_item']);
        $this->assertSame(['error' => 'Dividing by zero.'], $row['doubled']);
    }

    public function test_parameters_are_names_a_formula_can_write()
    {
        foreach ([
            [[['name' => 'two words', 'value' => 1]], '"two words" can\'t be a parameter\'s name: use letters, digits and _, starting with a letter.'],
            [[['name' => 'rate', 'value' => 1], ['name' => 'Rate', 'value' => 2]], 'There are two parameters called "Rate".'],
            [[['name' => 'cny', 'value' => 1]], '"cny" is already a column of this table.'],
            [[['name' => 'list', 'value' => [1, 2]]], 'The parameter "list" must be a number, text, true or false, or a date.'],
        ] as [$given, $message]) {
            $this->actingAs($this->user)
                ->patchJson(route('tables.update', $this->table), ['parameters' => $given])
                ->assertJsonValidationErrors(['parameters' => $message]);
        }
    }

    public function test_removing_a_formula_column_leaves_the_rows_alone()
    {
        $thb = $this->column('THB', 'formula', ['expression' => 'cny * rate']);
        $this->row(['cny' => 10]);

        $this->actingAs($this->user)
            ->deleteJson(route('tables.columns.destroy', [$this->table, $thb['name']]))
            ->assertOk();

        $this->assertSame([10.0], array_column(TableStorage::rows($this->table->fresh()), 'cny'));
    }
}
