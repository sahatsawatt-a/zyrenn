<?php

namespace App\Support\Workflow\Steps;

use App\Support\Table\TableStorage;

/**
 * Change a row, found by its id: the values given are written over its own.
 */
final class TableUpdate implements Step
{
    public function run(StepContext $step): StepResult
    {
        $table = TableSteps::table($step);
        $id = $step->template('row');

        if (! is_numeric($id)) {
            throw new StepFailed('The row id should be a number, not '.json_encode($id).'.');
        }

        if (! TableStorage::updateRow($table, (int) $id, TableSteps::values($step, $table))) {
            throw new StepFailed("This table has no row {$id}.");
        }

        $row = TableStorage::row($table, (int) $id) ?? ['id' => (int) $id];
        TableSteps::tell($table, $row);

        return new StepResult(['row' => $row]);
    }
}
