<?php

namespace App\Mcp\Tools\Tables;

use App\Events\TableChanged;
use App\Mcp\Tools\TableTool;
use App\Models\Table\Table;
use App\Models\User;
use App\Support\Live\Live;
use App\Support\Table\TableStorage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Change a table: rename or move it, add columns, and add, change or delete rows. Only what you pass changes. Rows are changed and deleted by the "id" get-table shows; a changed row keeps every value you leave out.')]
class UpdateTable extends TableTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $this->refIdArgument($schema),
            'title' => $schema->string()->max(255)->description('New title.'),
            'folder' => $this->folderArgument($schema, 'Move the table to this folder; folders that don\'t exist yet are created.'),
            'add_columns' => $this->columnsArgument($schema, 'Columns to add after the ones there are.'),
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
            ...$this->columnRules('add_columns'),
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

        $table->save();
        $this->addColumns($table, $validated['add_columns'] ?? []);

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
}
