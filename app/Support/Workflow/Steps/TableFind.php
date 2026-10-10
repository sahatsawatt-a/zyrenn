<?php

namespace App\Support\Workflow\Steps;

use App\Support\Table\RowValues;
use App\Support\Table\TableStorage;

/**
 * Find rows: a table's rows, all or those whose column holds a value,
 * oldest first, up to a limit.
 */
final class TableFind implements Step
{
    /** The most rows looked through, so a huge table can't hold a worker. */
    private const LOOKED_THROUGH = 5000;

    public function run(StepContext $step): StepResult
    {
        $table = TableSteps::table($step);
        $limit = max(1, min(1000, (int) $step->setting('limit')));
        $name = trim((string) $step->setting('column'));
        $rows = TableStorage::page($table, self::LOOKED_THROUGH)['rows'];

        if ($name !== '') {
            $column = RowValues::column($table, $name)
                ?? throw new StepFailed("This table has no column \"{$name}\".");
            $value = $step->template('value');
            $rows = array_values(array_filter($rows, fn (array $row) => TableSteps::holds($row[$column->name] ?? null, $value)));
        }

        $rows = array_slice($rows, 0, $limit);

        return new StepResult(['rows' => $rows, 'count' => count($rows)]);
    }
}
