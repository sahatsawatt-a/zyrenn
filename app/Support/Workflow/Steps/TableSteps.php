<?php

namespace App\Support\Workflow\Steps;

use App\Events\TableChanged;
use App\Models\Table\Table;
use App\Support\Live\Live;
use App\Support\Table\RowValues;
use App\Support\Workflow\References;
use App\Support\Workflow\Template;
use RuntimeException;

/**
 * What the Table steps share: the table, which must be the workflow
 * owner's, and values written the way a person writes them -- by a
 * column's name or label -- each worked out from what came before.
 */
final class TableSteps
{
    /**
     * @throws StepFailed when the workflow's owner has no such table
     */
    public static function table(StepContext $step): Table
    {
        return References::table($step->owner(), (string) $step->setting('table'))
            ?? throw new StepFailed('Its table is gone, or is not this workflow\'s.');
    }

    /**
     * The step's "values", worked out and keyed by column name.
     *
     * @return array<string, mixed>
     *
     * @throws StepFailed when one names no column, or one worked out by a formula
     */
    public static function values(StepContext $step, Table $table): array
    {
        $given = [];

        foreach ((array) $step->setting('values') as $field) {
            $given[$field['name']] = $step->template("values.{$field['name']}", $field['value']);
        }

        try {
            return RowValues::of($table, $given);
        } catch (RuntimeException $e) {
            throw new StepFailed($e->getMessage());
        }
    }

    /**
     * Everyone with the table open sees the row as it is now.
     *
     * @param  array<string, mixed>  $row
     */
    public static function tell(Table $table, array $row): void
    {
        Live::tellEveryone(new TableChanged($table, 'row', ['row' => $row]));
    }

    /**
     * Whether a cell holds a value, read the way it is shown: a choice
     * among several holds each of them.
     */
    public static function holds(mixed $cell, mixed $value): bool
    {
        $wanted = mb_strtolower(trim(Template::text($value)));

        if (is_array($cell) && array_is_list($cell)) {
            return collect($cell)->contains(fn ($item) => mb_strtolower(trim(Template::text($item))) === $wanted);
        }

        return mb_strtolower(trim(Template::text($cell))) === $wanted;
    }
}
