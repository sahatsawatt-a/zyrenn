<?php

namespace App\Support\Workflow\Steps;

use App\Support\Table\TableStorage;

/**
 * Add a row: written into the table, shown at once to whoever has it open.
 */
final class TableAdd implements Step
{
    public function run(StepContext $step): StepResult
    {
        $table = TableSteps::table($step);
        $id = TableStorage::insertRow($table, TableSteps::values($step, $table));
        $row = TableStorage::row($table, $id) ?? ['id' => $id];

        TableSteps::tell($table, $row);

        return new StepResult(['row' => $row]);
    }
}
