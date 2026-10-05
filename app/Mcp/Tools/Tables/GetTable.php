<?php

namespace App\Mcp\Tools\Tables;

use App\Mcp\Tools\TableTool;
use App\Models\Table\Table;
use App\Support\Table\TableFormulas;
use App\Support\Table\TableStorage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TEXT'
Get a table: its columns (name, label, type, the choices of a select, a formula column's expression)
and a page of its rows, each with its "id" and a value per column name. "total" says how many rows
there are (or match the search), and "next_offset" where the next page starts, when there is one.
"parameters" are the named values its formulas share, and "totals" what its footer shows under a
column, over every row. A formula that can't be worked out for a row says why: {"error": "..."}.

Read only what you need: "limit" and "offset" page through the rows (100 at a time unless asked),
"search" keeps rows whose text holds it, and "columns" (labels or names) keeps just those columns.
Use a row's id with update-table to change or delete it.
TEXT)]
class GetTable extends TableTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $this->refIdArgument($schema),
            'limit' => $schema->integer()->min(1)->max(self::MAX_ROWS)->default(100)->description('How many rows, at most.'),
            'offset' => $schema->integer()->min(0)->default(0)->description('How many rows to skip first.'),
            'search' => $schema->string()->max(255)->description('Only rows with this in a text column (any case).'),
            'columns' => $schema->array()->max(50)->items($schema->string()->max(120))->description('Only these columns, by label or name; the id always comes.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $owner = $this->targetOwner($request);

        $validated = $request->validate([
            'ref_id' => ['required', 'string', 'max:16'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_ROWS],
            'offset' => ['nullable', 'integer', 'min:0'],
            'search' => ['nullable', 'string', 'max:255'],
            'columns' => ['nullable', 'array', 'max:50'],
            'columns.*' => ['string', 'max:120'],
        ]);

        /** @var Table|null $table */
        $table = $this->find($owner, $validated['ref_id']);

        if (! $table) {
            return $this->notFound($validated['ref_id']);
        }

        try {
            $only = filled($validated['columns'] ?? null) ? $this->columnNames($table, $validated['columns']) : null;
        } catch (TableProblem $problem) {
            return Response::error($problem->getMessage());
        }

        $limit = $validated['limit'] ?? 100;
        $offset = $validated['offset'] ?? 0;
        $page = TableStorage::page($table, $limit, $offset, $validated['search'] ?? null, $only);

        return Response::structured([
            ...$this->summary($table),
            ...($table->parameters ? ['parameters' => array_column($table->parameters, 'value', 'name')] : []),
            'columns' => $this->describeColumns($table, $only),
            'rows' => $page['rows'],
            'total' => $page['total'],
            ...(($totals = TableFormulas::totals($table)) !== [] ? ['totals' => $totals] : []),
            ...($offset + $limit < $page['total'] ? ['next_offset' => $offset + $limit] : []),
        ]);
    }
}
