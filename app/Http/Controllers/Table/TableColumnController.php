<?php

namespace App\Http\Controllers\Table;

use App\Events\TableChanged;
use App\Http\Controllers\Controller;
use App\Models\Table\Table;
use App\Models\Table\TableColumn;
use App\Support\Formula\Parser;
use App\Support\Live\Live;
use App\Support\Table\TableFormulas;
use App\Support\Table\TableStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The columns of a table. Adding or removing one changes the table its rows
 * are kept in, so each goes through TableStorage; the rest is how it is shown.
 */
class TableColumnController extends Controller
{
    /**
     * Add a column. Its name is made from the label, here, never taken from
     * the request.
     */
    public function store(Request $request, Table $table): JsonResponse
    {
        Gate::authorize('update', $table);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(TableStorage::TYPES)],
            ...self::settingsRules(),
        ]);

        self::checkExpression($table, $validated['type'], $validated['expression'] ?? null);

        $column = TableStorage::newColumn($table, [
            'label' => $validated['label'],
            'type' => $validated['type'],
            'width' => $validated['width'] ?? 180,
            'options_meta' => self::settings($validated),
        ]);

        $table->touch();

        // Its columns changed; others load the table again
        Live::tell(new TableChanged($table, 'reload'));

        return response()->json(['column' => $column->toGrid()], 201);
    }

    /**
     * Change how a column is labelled, drawn or ordered. Its kind can change
     * only to one stored the same way; anything else would mean converting
     * every value in it.
     */
    public function update(Request $request, Table $table, TableColumn $column): JsonResponse
    {
        Gate::authorize('update', $table);

        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:120'],
            'type' => ['sometimes', Rule::in(TableStorage::TYPES)],
            'hidden' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            ...self::settingsRules(),
        ]);

        if (isset($validated['type']) && $validated['type'] !== $column->type) {
            if ($column->is_primary || ! TableStorage::canChange($column->type, $validated['type'])) {
                throw ValidationException::withMessages([
                    'type' => __('A :from column can’t become a :to column: its values are stored differently.', [
                        'from' => $column->type,
                        'to' => $validated['type'],
                    ]),
                ]);
            }
        }

        // The id column is always there, always first, and never hidden
        if ($column->is_primary) {
            unset($validated['type'], $validated['hidden'], $validated['sort_order']);
        }

        if (array_key_exists('expression', $validated)) {
            self::checkExpression($table, $validated['type'] ?? $column->type, $validated['expression'], $column->name);
        }

        $settings = self::settings($validated);

        if ($settings !== []) {
            $current = $column->options_meta ?? [];
            $column->options_meta = [
                ...(array_is_list($current) ? ['options' => $current] : $current),
                ...$settings,
            ];
        }

        $column->fill(array_intersect_key($validated, array_flip(['label', 'type', 'width', 'hidden', 'sort_order'])));
        $column->save();
        $table->touch();

        // Its columns changed; others load the table again
        Live::tell(new TableChanged($table, 'reload'));

        return response()->json(['column' => $column->toGrid()]);
    }

    /**
     * Remove a column, and every value in it.
     */
    public function destroy(Table $table, TableColumn $column): JsonResponse
    {
        Gate::authorize('update', $table);

        if ($column->is_primary) {
            throw ValidationException::withMessages([
                'column' => __('The ID column can’t be removed.'),
            ]);
        }

        DB::transaction(function () use ($table, $column) {
            if (! TableStorage::isComputed($column->type)) {
                TableStorage::dropColumn($table, $column->name);
            }

            $column->delete();
        });

        $table->touch();

        // Its columns changed; others load the table again
        Live::tell(new TableChanged($table, 'reload'));

        return response()->json(['deleted' => $column->name]);
    }

    /**
     * What else a column can say about itself.
     *
     * @return array<string, list<mixed>>
     */
    private static function settingsRules(): array
    {
        return [
            'width' => ['sometimes', 'integer', 'min:60', 'max:800'],
            'options' => ['sometimes', 'array', 'max:200'],
            'options.*.id' => ['required_with:options', 'string', 'max:64'],
            'options.*.value' => ['required_with:options', 'string', 'max:120'],
            'options.*.color' => ['nullable', 'string', 'max:160'],
            'currencySymbol' => ['sometimes', 'nullable', 'string', 'max:8'],
            'maxRating' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10'],
            'expression' => ['sometimes', 'nullable', 'string', 'max:'.Parser::MAX_LENGTH],
            'summary' => ['sometimes', 'nullable', Rule::in(TableFormulas::SUMMARIES)],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private static function settings(array $validated): array
    {
        return array_intersect_key($validated, array_flip(['options', 'currencySymbol', 'maxRating', 'expression', 'summary']));
    }

    /**
     * A formula column needs a formula that can be worked out from this table.
     */
    private static function checkExpression(Table $table, string $type, ?string $expression, ?string $for = null): void
    {
        if ($type !== 'formula') {
            return;
        }

        $problem = blank($expression)
            ? __('A formula column needs a formula, e.g. cny * rate.')
            : TableFormulas::problem($table, (string) $expression, $for);

        if ($problem !== null) {
            throw ValidationException::withMessages(['expression' => $problem]);
        }
    }
}
