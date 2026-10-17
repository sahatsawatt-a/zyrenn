<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Models\Board\Board;
use App\Models\Table\Table;
use App\Support\Prototype\PrototypeTables;
use App\Support\Table\RowValues;
use App\Support\Table\TableStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Play saving to a prototype's tables and deleting from them, when it is set
 * to change the tables themselves (data: 'tables') rather than each play's
 * own copy: only for whoever may change the project, and only in tables of
 * its own project that its items name. Values come by column label, as Play
 * has them, and the row comes back as the table now keeps it.
 */
class PrototypeRowController extends Controller
{
    public function store(Request $request, Board $board): JsonResponse
    {
        $valid = $request->validate([
            'table' => ['required', 'string', 'max:32'],
            'values' => ['present', 'array'],
            'row' => ['nullable', 'integer', 'min:1'],
        ]);
        $table = $this->table($board, (string) $valid['table']);

        try {
            $values = RowValues::of($table, $valid['values']);
        } catch (RuntimeException $problem) {
            abort(422, $problem->getMessage());
        }

        if (isset($valid['row'])) {
            abort_unless(TableStorage::updateRow($table, (int) $valid['row'], $values), 404, __('That row is no longer in the table.'));
            $id = (int) $valid['row'];
        } else {
            $id = TableStorage::insertRow($table, $values);
        }

        return response()->json(
            ['row' => PrototypeTables::byLabel($table->load('columns'), (array) TableStorage::row($table, $id))],
            isset($valid['row']) ? 200 : 201,
        );
    }

    public function destroy(Request $request, Board $board): Response
    {
        $valid = $request->validate([
            'table' => ['required', 'string', 'max:32'],
            'row' => ['required', 'integer', 'min:1'],
        ]);

        TableStorage::deleteRows($this->table($board, (string) $valid['table']), [(int) $valid['row']]);

        return response()->noContent();
    }

    /**
     * The table, when Play may write to it: a prototype set to change its
     * tables, a player who may change it, and one of its project's tables
     * that its items name.
     */
    private function table(Board $board, string $ref): Table
    {
        Gate::authorize('update', $board);
        abort_unless($board->isPrototype(), 404);
        abort_unless(($board->prototype['data'] ?? 'play') === 'tables', 403, __('This prototype keeps what is saved in Play to each play.'));

        $table = PrototypeTables::find($board, $ref);
        abort_if($table === null, 404, __('This prototype does not use that table.'));
        Gate::authorize('update', $table);

        return $table;
    }
}
