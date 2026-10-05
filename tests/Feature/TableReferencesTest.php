<?php

namespace Tests\Feature;

use App\Events\TableChanged;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Tables\CreateTable;
use App\Mcp\Tools\Tables\GetTable;
use App\Mcp\Tools\Trips\CreateTrip;
use App\Mcp\Tools\Trips\UpdateTrip;
use App\Models\Map\Trip;
use App\Models\Project;
use App\Models\Table\Table;
use App\Models\User;
use App\Support\Table\TableStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * Formulas that read beyond their own table: a trip's totals, another
 * table's columns -- the same owner's only -- and that follow them.
 */
class TableReferencesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * Three days in Shanghai: ¥190 of sights, a hotel for three nights at ¥1800.
     */
    private function trip(?Project $project = null): Trip
    {
        UserServer::actingAs($this->user)->tool(CreateTrip::class, [
            'title' => 'Shanghai',
            ...($project ? ['project' => $project->ref_id] : []),
            'start_date' => '2026-11-03',
            'currency' => '¥',
            'stays' => [['name' => 'Hotel Indigo', 'lat' => 31.2347, 'lng' => 121.4972, 'check_in' => '2026-11-03T15:00', 'check_out' => '2026-11-06T11:00', 'cost' => 1800]],
            'days' => [
                ['stops' => [['name' => 'The Bund', 'lat' => 31.2397, 'lng' => 121.4906, 'cost' => 50]]],
                ['stops' => [['name' => 'Yu Garden', 'lat' => 31.2272, 'lng' => 121.4921, 'cost' => 40]]],
                ['stops' => [['name' => 'Xintiandi', 'lat' => 31.2196, 'lng' => 121.4747, 'cost' => 100]]],
            ],
        ])->assertOk();

        return Trip::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $parameters
     */
    private function table(string $title, array $columns, array $rows = [], array $parameters = [], ?Project $project = null): Table
    {
        UserServer::actingAs($this->user)->tool(CreateTable::class, [
            'title' => $title,
            ...($project ? ['project' => $project->ref_id] : []),
            'parameters' => $parameters,
            'columns' => $columns,
            'rows' => $rows,
        ])->assertOk();

        return Table::query()->where('title', $title)->latest('id')->firstOrFail();
    }

    public function test_a_formula_reads_a_trips_totals_by_its_title_or_ref()
    {
        $trip = $this->trip();
        $budget = $this->table('Budget', [
            ['label' => 'Day', 'type' => 'integer'],
            ['label' => 'Date', 'type' => 'formula', 'expression' => 'text(trip("Shanghai").day(day).date, "D j M")'],
            ['label' => 'Spent', 'type' => 'formula', 'expression' => 'trip("shanghai").day(day).cost * rate'],
            ['label' => 'All in', 'type' => 'formula', 'expression' => 'trip("'.$trip->ref_id.'").total_cost & " over " & trip("Shanghai").nights & " nights, hotel " & trip("Shanghai").stay(1).cost'],
            ['label' => 'Bad', 'type' => 'formula', 'expression' => 'trip("Shanghai").day(day + 5).cost'],
        ], [['Day' => 2]], ['rate' => 5]);

        $row = TableStorage::rows($budget->fresh())[0];

        $this->assertSame('Wed 4 Nov', $row['date']);
        $this->assertSame(200.0, $row['spent']);
        $this->assertSame('1990 over 3 nights, hotel 1800', $row['all_in']);
        $this->assertSame(['error' => 'The trip has days 1 to 3, not day 7.'], $row['bad']);
    }

    public function test_a_formula_reads_another_tables_columns_as_lists()
    {
        $this->table('Costs', [
            ['label' => 'Day', 'type' => 'integer'],
            ['label' => 'CNY', 'type' => 'numeric'],
            ['label' => 'THB', 'type' => 'formula', 'expression' => 'cny * 5'],
        ], [['Day' => 1, 'CNY' => 100], ['Day' => 1, 'CNY' => 20], ['Day' => 5, 'CNY' => 475]]);

        $summary = $this->table('Summary', [
            ['label' => 'Day', 'type' => 'integer'],
            ['label' => 'That day', 'type' => 'formula', 'expression' => 'sum(if(table("Costs").day = day, table("Costs").thb, 0))'],
            ['label' => 'Everything', 'type' => 'formula', 'expression' => 'sum(table("costs").[THB])'],
        ], [['Day' => 1], ['Day' => 5]]);

        UserServer::actingAs($this->user)->tool(GetTable::class, ['ref_id' => $summary->ref_id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('rows.0.that_day', 600)
                ->where('rows.1.that_day', 2375)
                ->where('rows.0.everything', 2975)
                ->etc());
    }

    public function test_only_the_same_owners_things_can_be_read_and_loops_are_stopped()
    {
        $project = Project::start($this->user, 'Trip');
        $this->trip($project);

        // The trip is the project's, not the user's own
        $mine = $this->table('Mine', [['label' => 'Cost', 'type' => 'formula', 'expression' => 'trip("Shanghai").total_cost']], [[]]);
        $this->assertSame(['error' => 'There is no trip called "Shanghai" here.'], TableStorage::rows($mine->fresh())[0]['cost']);

        $theirs = $this->table('Theirs', [['label' => 'Cost', 'type' => 'formula', 'expression' => 'trip("Shanghai").total_cost']], [[]], project: $project);
        $this->assertSame(1990.0, TableStorage::rows($theirs->fresh())[0]['cost']);

        // B reads A's plain numbers, A's formula reads them back: no loop
        $this->table('A', [['label' => 'N', 'type' => 'numeric'], ['label' => 'From B', 'type' => 'formula', 'expression' => 'sum(table("B").n)']], [['N' => 1]]);
        $b = $this->table('B', [['label' => 'N', 'type' => 'numeric'], ['label' => 'From A', 'type' => 'formula', 'expression' => 'sum(table("A").from_b)']], [['N' => 2]]);
        $this->assertSame(2.0, TableStorage::rows($b->fresh())[0]['from_a']);

        // Each formula reading the other's: a loop, said rather than summed to nothing
        $this->table('C', [['label' => 'From D', 'type' => 'formula', 'expression' => 'sum(table("D").from_c) + 1']], [[]]);
        $d = $this->table('D', [['label' => 'From C', 'type' => 'formula', 'expression' => 'sum(table("C").from_d) + 1']], [[]]);
        $this->assertSame(['error' => '"C" comes back round to itself through another table\'s formulas.'], TableStorage::rows($d->fresh())[0]['from_c']);

        // A row that failed fails a total over it, rather than make it short
        $this->table('Costs', [['label' => 'N', 'type' => 'numeric'], ['label' => 'Each', 'type' => 'formula', 'expression' => '10 / n']], [['N' => 2], ['N' => 0]]);
        $total = $this->table('Total', [['label' => 'Sum', 'type' => 'formula', 'expression' => 'sum(table("Costs").each)']], [[]]);
        $this->assertSame(['error' => 'Dividing by zero.'], TableStorage::rows($total->fresh())[0]['sum']);
    }

    public function test_tables_that_read_a_trip_or_table_are_told_when_it_changes()
    {
        $trip = $this->trip();
        $reading = $this->table('Reading', [['label' => 'Cost', 'type' => 'formula', 'expression' => 'trip("Shanghai").total_cost']], [[]]);
        $costs = $this->table('Costs', [['label' => 'CNY', 'type' => 'numeric']], [['CNY' => 1]]);
        $summing = $this->table('Summing', [['label' => 'All', 'type' => 'formula', 'expression' => 'sum(table("Costs").cny)']], [[]]);
        $this->table('Unrelated', [['label' => 'One', 'type' => 'formula', 'expression' => '1']], [[]]);

        Event::fake([TableChanged::class]);

        UserServer::actingAs($this->user)->tool(UpdateTrip::class, ['ref_id' => $trip->ref_id, 'title' => 'Shanghai'])->assertOk();
        Event::assertNotDispatched(TableChanged::class);

        UserServer::actingAs($this->user)->tool(UpdateTrip::class, [
            'ref_id' => $trip->ref_id,
            'update_stops' => [['id' => $trip->content['days'][0]['stops'][0]['id'], 'cost' => 80]],
        ]);
        Event::assertDispatched(TableChanged::class, fn (TableChanged $event) => $event->table->is($reading) && $event->change === 'reload');

        TableStorage::updateRow($costs->fresh(), 1, ['cny' => 2]);
        Event::assertDispatched(TableChanged::class, fn (TableChanged $event) => $event->table->is($summing));
        Event::assertDispatched(TableChanged::class, fn (TableChanged $event) => $event->table->is($reading), 1);
    }
}
