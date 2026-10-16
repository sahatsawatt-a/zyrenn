<?php

namespace App\Support\Workflow\Steps;

/**
 * CSV: text read into rows -- each a record named by the first row, or a
 * list -- or rows written out as CSV text, with a comma, semicolon, tab or
 * bar between.
 */
final class CsvStep implements Step
{
    private const SEPARATORS = ['comma' => ',', 'semicolon' => ';', 'tab' => "\t", 'bar' => '|'];

    public function run(StepContext $step): StepResult
    {
        return $step->setting('do') === 'write' ? $this->write($step) : $this->read($step);
    }

    private function read(StepContext $step): StepResult
    {
        $text = (string) preg_replace('/^\xEF\xBB\xBF/', '', $step->text('text'));
        $separator = self::SEPARATORS[$step->setting('separator')] ?? self::guess($text);
        $in = self::memory();
        fwrite($in, $text);
        rewind($in);
        $rows = [];

        while (($row = fgetcsv($in, null, $separator, '"', '')) !== false) {
            // A blank line is no row
            if ($row !== [null]) {
                $rows[] = array_map(fn (?string $cell) => (string) $cell, $row);
            }
        }

        fclose($in);

        if ($step->setting('header') === 'none') {
            return new StepResult(['rows' => $rows, 'columns' => [], 'count' => count($rows)]);
        }

        $columns = self::names(array_shift($rows) ?? []);
        $records = array_map(function (array $row) use ($columns) {
            $record = [];

            foreach ($columns as $at => $name) {
                $record[$name] = $row[$at] ?? '';
            }

            return $record;
        }, $rows);

        return new StepResult(['rows' => $records, 'columns' => $columns, 'count' => count($records)]);
    }

    private function write(StepContext $step): StepResult
    {
        $rows = $step->formula('rows');

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new StepFailed('"Rows" should come to a list, e.g. {{ node("find").rows }}.');
        }

        // Every name any record has, in the order they come
        $columns = [];

        foreach ($rows as $row) {
            if (is_array($row) && ! array_is_list($row)) {
                $columns += array_fill_keys(array_map('strval', array_keys($row)), true);
            }
        }

        $columns = array_keys($columns);
        $separator = self::SEPARATORS[$step->setting('separator')] ?? ',';
        $out = self::memory();

        if ($columns !== [] && $step->setting('header') !== 'none') {
            fputcsv($out, $columns, $separator, '"', '');
        }

        foreach ($rows as $row) {
            $cells = match (true) {
                ! is_array($row) => [$row],
                array_is_list($row) => $row,
                default => array_map(fn (string $name) => $row[$name] ?? '', $columns),
            };
            fputcsv($out, array_map(self::cell(...), $cells), $separator, '"', '');
        }

        rewind($out);
        $text = (string) stream_get_contents($out);
        fclose($out);

        return new StepResult(['text' => $text, 'columns' => $columns, 'count' => count($rows)]);
    }

    /**
     * Somewhere to read or write CSV, in memory.
     *
     * @return resource
     */
    private static function memory()
    {
        return fopen('php://memory', 'r+') ?: throw new StepFailed('There was no room to work in.');
    }

    /** A value as one cell: a list or record as JSON, yes and no as words. */
    private static function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }

    /**
     * The first row as names: each kept, blank ones and repeats given one of
     * their own (column_3, name_2).
     *
     * @param  list<string>  $first
     * @return list<string>
     */
    private static function names(array $first): array
    {
        $names = [];

        foreach ($first as $at => $name) {
            $name = trim($name) === '' ? 'column_'.($at + 1) : trim($name);
            $base = $name;

            for ($n = 2; in_array($name, $names, true); $n++) {
                $name = "{$base}_{$n}";
            }

            $names[] = $name;
        }

        return $names;
    }

    /** What separates the first line's cells, of those it has most of. */
    private static function guess(string $text): string
    {
        $line = strtok($text, "\n") ?: '';
        $counts = array_map(fn (string $one) => substr_count($line, $one), self::SEPARATORS);
        arsort($counts);

        return reset($counts) > 0 ? self::SEPARATORS[array_key_first($counts)] : ',';
    }
}
