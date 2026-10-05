<?php

namespace App\Mcp\Tools\Tables;

use App\Events\TableChanged;
use App\Mcp\Tools\TableTool;
use App\Models\Table\Table;
use App\Models\User;
use App\Support\Formula\Parser;
use App\Support\Live\Live;
use App\Support\Table\TableFormulas;
use App\Support\Table\TableStorage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Change a table: rename or move it, set its parameters, add or change columns, and add, change or delete rows. Only what you pass changes. Rows are changed and deleted by the "id" get-table shows; a changed row keeps every value you leave out. A formula column works itself out from the rest of its row and its table\'s parameters: change a parameter once ({"rate": 5.2}) and every row follows.')]
class UpdateTable extends TableTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $this->refIdArgument($schema),
            'title' => $schema->string()->max(255)->description('New title.'),
            'folder' => $this->folderArgument($schema, 'Move the table to this folder; folders that don\'t exist yet are created.'),
            'parameters' => $this->parametersArgument($schema),
            'add_columns' => $this->columnsArgument($schema, 'Columns to add after the ones there are.'),
            'update_columns' => $schema->array()->max(50)->items($schema->object([
                'column' => $schema->string()->max(120)->description('The column, by label or name.')->required(),
                'label' => $schema->string()->max(120)->description('A new label; its name stays.'),
                'expression' => $schema->string()->max(Parser::MAX_LENGTH)->description('A formula column\'s new formula.'),
                'summary' => $schema->string()->enum([...TableFormulas::SUMMARIES, 'none'])->description('What the footer shows under it: sum, avg, min, max, count, or none.'),
            ]))->description('Columns to change.'),
            'add_rows' => $this->rowsArgument($schema, 'Rows to add at the end.'),
            'update_rows' => $schema->array()->max(1000)->items($schema->object([
                'id' => $schema->integer()->description('The row\'s id.')->required(),
                'values' => $schema->object()->description('The values to change, keyed by column label or name.')->required(),
            ]))->description('Rows to change.'),
            'delete_rows' => $schema->array()->max(1000)->items($schema->integer())->description('The ids of rows to delete.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);
        $owner = $this->targetOwner($request, changes: true);

        $validated = $request->validate([
            'ref_id' => ['required', 'string', 'max:16'],
            'title' => ['sometimes', 'string', 'max:255'],
            'folder' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'parameters' => ['sometimes', 'array', 'max:'.TableFormulas::MAX_PARAMETERS],
            ...$this->columnRules('add_columns'),
            'update_columns' => ['nullable', 'array', 'max:50'],
            'update_columns.*.column' => ['required', 'string', 'max:120'],
            'update_columns.*.label' => ['sometimes', 'string', 'max:120'],
            'update_columns.*.expression' => ['sometimes', 'string', 'max:'.Parser::MAX_LENGTH],
            'update_columns.*.summary' => ['sometimes', 'string', 'in:'.implode(',', [...TableFormulas::SUMMARIES, 'none'])],
            'add_rows' => ['nullable', 'array', 'max:1000'],
            'add_rows.*' => ['array'],
            'update_rows' => ['nullable', 'array', 'max:1000'],
            'update_rows.*.id' => ['required', 'integer'],
            'update_rows.*.values' => ['required', 'array'],
            'delete_rows' => ['nullable', 'array', 'max:1000'],
            'delete_rows.*' => ['integer'],
        ]);

        $table = $this->find($owner, $validated['ref_id']);

        if (! $table) {
            return $this->notFound($validated['ref_id']);
        }

        try {
            $added = DB::transaction(fn () => $this->apply($table, $user, $validated));

            // Rows and columns may both have changed; whoever has it open loads it again
            Live::tell(new TableChanged($table, 'reload'));
        } catch (TableProblem $problem) {
            return Response::error($problem->getMessage());
        }

        return Response::structured($this->answer($table->refresh(), array_filter([
            // A new row's id is how it is changed or deleted later
            'added_rows' => $added,
            'updated_rows' => count($validated['update_rows'] ?? []),
            'deleted_rows' => count($validated['delete_rows'] ?? []),
        ])));
    }

    /**
     * Makes the changes. A problem part way through throws, and the
     * transaction around this keeps none of them.
     *
     * @param  array<string, mixed>  $validated
     * @return list<int> the ids of the rows added
     */
    private function apply(Table $table, User $user, array $validated): array
    {
        if (array_key_exists('title', $validated)) {
            $table->title = $validated['title'];
        }

        if (array_key_exists('folder', $validated)) {
            $table->folder_id = $this->ensureFolderAt($table->owner(), $validated['folder'] ?? '', $user)?->id;
        }

        if (array_key_exists('parameters', $validated)) {
            $this->setParameters($table, $validated['parameters']);
        }

        $table->save();
        $this->addColumns($table, $validated['add_columns'] ?? []);
        $this->changeColumns($table, $validated['update_columns'] ?? []);

        $added = [];

        foreach ($validated['add_rows'] ?? [] as $given) {
            $added[] = TableStorage::insertRow($table, $this->rowValues($table, $given));
        }

        foreach ($validated['update_rows'] ?? [] as $row) {
            if (! TableStorage::updateRow($table, $row['id'], $this->rowValues($table, $row['values']))) {
                throw new TableProblem("This table has no row {$row['id']}.");
            }
        }

        if (! empty($validated['delete_rows'])) {
            TableStorage::deleteRows($table, $validated['delete_rows']);
        }

        return $added;
    }

    /**
     * @param  list<array{column: string, label?: string, expression?: string, summary?: string}>  $changes
     *
     * @throws TableProblem
     */
    private function changeColumns(Table $table, array $changes): void
    {
        foreach ($changes as $change) {
            [$name] = $this->columnNames($table, [$change['column']]);
            $column = $table->columns->firstWhere('name', $name);
            $meta = $column->options_meta ?? [];
            $meta = array_is_list($meta) ? ['options' => $meta] : $meta;

            if (array_key_exists('expression', $change)) {
                if ($column->type !== 'formula') {
                    throw new TableProblem("\"{$column->label}\" is not a formula column; only those have an expression.");
                }

                $this->checkExpression($table, $column->label, 'formula', $change['expression'], $column->name);
                $meta['expression'] = $change['expression'];
            }

            if (array_key_exists('summary', $change)) {
                $meta['summary'] = $change['summary'] === 'none' ? null : $change['summary'];
            }

            $column->options_meta = $meta;
            $column->label = $change['label'] ?? $column->label;
            $column->save();
            $table->unsetRelation('columns');
        }
    }
}
