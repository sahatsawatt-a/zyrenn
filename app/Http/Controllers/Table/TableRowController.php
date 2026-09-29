<?php

namespace App\Http\Controllers\Table;

use App\Http\Controllers\Controller;
use App\Models\Table\Table;
use App\Support\Table\TableStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The rows of a table. The grid edits them in place and saves each change as
 * it goes, so every action here answers with JSON.
 */
class TableRowController extends Controller
{
    /**
     * Add an empty row at the end, and hand it back with the id it was given.
     */
    public function store(Table $table): JsonResponse
    {
        Gate::authorize('update', $table);

        $id = DB::table(TableStorage::physicalName($table))
            ->insertGetId(['created_at' => now(), 'updated_at' => now()]);
        $table->touch();

        return response()->json(['row' => TableStorage::row($table, $id)], 201);
    }

    /**
     * Change one cell. The column is named by the grid, and has to be one the
     * table has; the value is stored the way that column's kind is stored.
     */
    public function update(Request $request, Table $table, int $row): JsonResponse
    {
        Gate::authorize('update', $table);

        $validated = $request->validate([
            'column' => ['required', 'string', 'max:64'],
            'value' => ['present'],
        ]);

        // Only a column this table has, and never the id the database gives out
        $column = $table->columns()
            ->where('name', $validated['column'])
            ->where('is_primary', false)
            ->first();

        if (! $column) {
            throw ValidationException::withMessages([
                'column' => __('This table has no column “:name” to write to.', ['name' => $validated['column']]),
            ]);
        }

        $changed = DB::table(TableStorage::physicalName($table))
            ->where('id', $row)
            ->update([
                $column->name => TableStorage::toStored($column, $validated['value']),
                'updated_at' => now(),
            ]);

        abort_if($changed === 0 && ! TableStorage::row($table, $row), 404);
        $table->touch();

        return response()->json(['updated_at' => $table->updated_at]);
    }

    /**
     * Copy a row, and put the copy at the end.
     */
    public function duplicate(Table $table, int $row): JsonResponse
    {
        Gate::authorize('update', $table);

        $source = DB::table(TableStorage::physicalName($table))->where('id', $row)->first();
        abort_unless($source !== null, 404);

        $values = collect((array) $source)
            ->except(['id', 'created_at', 'updated_at'])
            ->all();

        $id = DB::table(TableStorage::physicalName($table))
            ->insertGetId([...$values, 'created_at' => now(), 'updated_at' => now()]);
        $table->touch();

        return response()->json(['row' => TableStorage::row($table, $id)], 201);
    }

    /**
     * Delete several rows at once.
     */
    public function destroy(Request $request, Table $table): JsonResponse
    {
        Gate::authorize('update', $table);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:1000'],
            'ids.*' => ['integer'],
        ]);

        $deleted = DB::table(TableStorage::physicalName($table))
            ->whereIn('id', $validated['ids'])
            ->delete();
        $table->touch();

        return response()->json(['deleted' => $deleted]);
    }
}
