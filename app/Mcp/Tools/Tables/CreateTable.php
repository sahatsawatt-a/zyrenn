<?php

namespace App\Mcp\Tools\Tables;

use App\Mcp\Tools\TableTool;
use App\Models\Table\Table;
use App\Support\Table\TableFormulas;
use App\Support\Table\TableStorage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
Create a table: columns of a kind each, and rows of values. Every table starts with an "id" column
the database fills in; the columns you give come after it. A formula column works itself out from the
rest of its row and the table's parameters, so a value that is used everywhere -- an exchange rate,
how many people share the costs, the first day -- is set once, as a parameter.

For example:
columns: [{"label":"Owner"},{"label":"Budget","type":"currency"},
          {"label":"Status","type":"select","choices":["Lead","Won"]}]
rows:    [{"Owner":"Ada","Budget":300,"Status":"Lead"}]

parameters: {"rate": 5}
columns: [{"label":"CNY","type":"currency","summary":"sum"},
          {"label":"THB","type":"formula","expression":"cny * rate","summary":"sum"}]
TEXT)]
class CreateTable extends TableTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(255)->description('The table title.')->required(),
            'parameters' => $this->parametersArgument($schema),
            'columns' => $this->columnsArgument($schema, 'The columns, in order.'),
            'rows' => $this->rowsArgument($schema, 'Rows to start with.'),
            'folder' => $this->folderArgument($schema, 'Folder to create the table in; folders that don\'t exist yet are created.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);
        $owner = $this->targetOwner($request, changes: true);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'folder' => ['nullable', 'string', 'max:1000'],
            'parameters' => ['sometimes', 'array', 'max:'.TableFormulas::MAX_PARAMETERS],
            ...$this->columnRules('columns'),
            'rows' => ['nullable', 'array', 'max:1000'],
            'rows.*' => ['array'],
        ]);

        try {
            $table = DB::transaction(function () use ($owner, $user, $validated) {
                $table = (new Table(['title' => $validated['title']]))->ownedBy($owner, $user);
                $table->folder_id = $this->ensureFolderAt($owner, $validated['folder'] ?? '', $user)?->id;
                $this->setParameters($table, $validated['parameters'] ?? []);
                $table->save();

                TableStorage::create($table);
                $this->addColumns($table, $validated['columns'] ?? []);

                foreach ($validated['rows'] ?? [] as $given) {
                    TableStorage::insertRow($table, $this->rowValues($table, $given));
                }

                return $table;
            });
        } catch (TableProblem $problem) {
            return Response::error($problem->getMessage());
        }

        return Response::structured($this->answer($table->refresh()));
    }
}
