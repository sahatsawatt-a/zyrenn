<?php

namespace Tests\Feature;

use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Tables\CreateTable;
use App\Models\Note\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A graph in a note drawn from a table: the table found by its title among
 * the note's owner's, and the columns it names read as points.
 */
class NotePlotTest extends TestCase
{
    use RefreshDatabase;

    private function note(User $user): Note
    {
        $note = (new Note(['title' => 'Log']))->ownedBy($user, $user);
        $note->save();

        return $note;
    }

    private function readings(User $user): void
    {
        UserServer::actingAs($user)->tool(CreateTable::class, [
            'title' => 'Readings',
            'columns' => [
                ['label' => 'Hour', 'type' => 'numeric'],
                ['label' => 'Voltage', 'type' => 'numeric'],
                ['label' => 'Current', 'type' => 'numeric'],
                ['label' => 'Remark'],
            ],
            'rows' => [
                ['Hour' => 2, 'Voltage' => 3.9, 'Current' => 0.4, 'Remark' => 'late'],
                ['Hour' => 0, 'Voltage' => 4.2, 'Current' => 0.5],
                ['Hour' => 1, 'Voltage' => 4.0],
                ['Voltage' => 9.9, 'Remark' => 'no hour'],
            ],
        ])->assertOk();
    }

    public function test_the_columns_a_graph_names_come_back_as_points_in_order()
    {
        $user = User::factory()->create();
        $this->readings($user);

        $this->actingAs($user)
            ->getJson(route('notes.plot-table', ['note' => $this->note($user), 'table' => 'readings', 'x' => 'hour', 'y' => ['Voltage', 'Current']]))
            ->assertOk()
            ->assertExactJson([
                'title' => 'Readings',
                'series' => [
                    // Across the bottom in order, whatever order the rows are in;
                    // a row without a number in both columns is not a point
                    ['name' => 'Voltage', 'points' => [[0, 4.2], [1, 4], [2, 3.9]]],
                    ['name' => 'Current', 'points' => [[0, 0.5], [2, 0.4]]],
                ],
            ]);
    }

    public function test_what_cannot_be_drawn_is_said_in_words()
    {
        $user = User::factory()->create();
        $this->readings($user);
        $note = $this->note($user);
        $ask = fn (array $what) => $this->actingAs($user)->getJson(route('notes.plot-table', ['note' => $note, ...$what]));

        $ask(['table' => 'Nothing', 'x' => 'Hour', 'y' => ['Voltage']])
            ->assertOk()
            ->assertJsonPath('problem', 'There is no table called "Nothing" here.');
        $ask(['table' => 'Readings', 'x' => 'Hour', 'y' => ['Power']])
            ->assertOk()
            ->assertJsonPath('problem', '"Readings" has no column called "Power": it has ID, Hour, Voltage, Current, Remark.');
        $ask(['table' => 'Readings', 'x' => 'Hour', 'y' => ['Remark']])
            ->assertOk()
            ->assertJsonPath('problem', '"Hour" and "Remark" have no row with a number in both.');
    }

    public function test_a_table_is_only_drawn_for_someone_who_may_read_the_note_and_the_table()
    {
        $user = User::factory()->create();
        $this->readings($user);
        $other = User::factory()->create();
        $what = ['table' => 'Readings', 'x' => 'Hour', 'y' => ['Voltage']];

        // Someone else's note is not theirs to ask through
        $this->actingAs($other)->getJson(route('notes.plot-table', ['note' => $this->note($user), ...$what]))->assertForbidden();

        // ...and from a note of their own, the name finds nothing of the first person's
        $this->actingAs($other)
            ->getJson(route('notes.plot-table', ['note' => $this->note($other), ...$what]))
            ->assertOk()
            ->assertJsonPath('problem', 'There is no table called "Readings" here.');
    }
}
