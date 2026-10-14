<?php

namespace App\Http\Controllers\Note;

use App\Http\Controllers\Controller;
use App\Models\Note\Note;
use App\Models\Table\Table;
use App\Models\Table\TableColumn;
use App\Support\Note\PageLinks;
use App\Support\Table\TableStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * What a graph in a note draws from a table: the numbers of the columns it
 * names, as points.
 */
class PlotController extends Controller
{
    /** No graph is read point by point past this many rows. */
    private const MOST = 2000;

    /**
     * The table is found by its title among the note's owner's, as a page
     * link's is, and its columns by their labels.
     */
    public function table(Request $request, Note $note): JsonResponse
    {
        Gate::authorize('view', $note);

        $validated = $request->validate([
            'table' => ['required', 'string', 'max:255'],
            'x' => ['required', 'string', 'max:120'],
            'y' => ['required', 'array', 'min:1', 'max:4'],
            'y.*' => ['string', 'max:120'],
        ]);

        $table = PageLinks::find($note->owner(), $validated['table'], 'table');

        if (! $table instanceof Table || $request->user()->cannot('view', $table)) {
            return $this->problem('There is no table called "'.$validated['table'].'" here.');
        }

        $columns = [];

        foreach ([$validated['x'], ...$validated['y']] as $named) {
            $column = $this->column($table, $named);

            if ($column === null) {
                return $this->problem('"'.$table->title.'" has no column called "'.$named.'": it has '
                    .$table->columns->pluck('label')->implode(', ').'.');
            }

            $columns[] = $column;
        }

        $across = array_shift($columns);
        $rows = TableStorage::page($table, self::MOST, 0, null, [$across->name, ...array_column($columns, 'name')])['rows'];

        $series = array_map(function (TableColumn $column) use ($rows, $across) {
            $points = [];

            foreach ($rows as $row) {
                // A row with no number in either place is not a point
                if (is_numeric($row[$across->name] ?? null) && is_numeric($row[$column->name] ?? null)) {
                    $points[] = [(float) $row[$across->name], (float) $row[$column->name]];
                }
            }

            usort($points, fn (array $a, array $b) => $a[0] <=> $b[0]);

            return ['name' => $column->label, 'points' => $points];
        }, $columns);

        foreach ($series as $each) {
            if ($each['points'] === []) {
                return $this->problem('"'.$across->label.'" and "'.$each['name'].'" have no row with a number in both.');
            }
        }

        return response()->json(['title' => $table->title, 'series' => $series]);
    }

    /** A column by what it is called, whatever the case. */
    private function column(Table $table, string $named): ?TableColumn
    {
        $named = mb_strtolower(trim($named));

        return $table->columns->first(fn (TableColumn $column) => mb_strtolower($column->label) === $named)
            ?? $table->columns->first(fn (TableColumn $column) => mb_strtolower($column->name) === $named);
    }

    /**
     * Why there is nothing to draw: an answer like any other, since a name
     * half typed is no fault of the page's.
     */
    private function problem(string $said): JsonResponse
    {
        return response()->json(['problem' => $said]);
    }
}
