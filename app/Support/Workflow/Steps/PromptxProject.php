<?php

namespace App\Support\Workflow\Steps;

/**
 * PromptX's calls about what a project has besides flows, each a kind of
 * step of its own: the account's projects, a project's connections (by
 * name, never what they hold), its folders, and its tables and their
 * records.
 *
 * Making a folder and adding records leave something new, so they are
 * recorded and never simply made twice; the rest only ask.
 */
final class PromptxProject implements Step
{
    public function run(StepContext $step): StepResult
    {
        $account = PromptxApi::account($step);
        $in = PromptxApi::project($step, $account);

        return new StepResult(match ($step->node['type']) {
            'promptx.projects' => self::list($in, '/users/projects', [], fn (array $one) => ['id' => $one['id'] ?? null, 'name' => $one['displayName'] ?? null], 'projects'),
            'promptx.connections' => self::list($in, '/app-connections', ['projectId' => $in['project']], fn (array $one) => [
                'id' => $one['id'] ?? null,
                'name' => $one['displayName'] ?? null,
                // What a flow's step names it by
                'external_id' => $one['externalId'] ?? null,
                'piece' => $one['pieceName'] ?? null,
                'status' => $one['status'] ?? null,
            ], 'connections'),
            'promptx.folders' => self::list($in, '/folders', ['projectId' => $in['project']], fn (array $one) => ['id' => $one['id'] ?? null, 'name' => $one['displayName'] ?? null, 'flows' => $one['numberOfFlows'] ?? null], 'folders'),
            'promptx.folder_create' => self::folder($step, $in),
            'promptx.tables' => self::list($in, '/tables', ['projectId' => $in['project']], fn (array $one) => ['id' => $one['id'] ?? null, 'name' => $one['name'] ?? null, 'created' => $one['created'] ?? null], 'tables'),
            'promptx.table_records' => self::records($step, $in),
            'promptx.table_add' => self::add($step, $in),

            default => throw new StepFailed("This app doesn't know the PromptX step \"{$step->node['type']}\"."),
        });
    }

    /**
     * One of PromptX's lists, each in a few named parts, and how many.
     *
     * @param  array{token: string, project: string}  $in
     * @param  array<string, string>  $query
     * @param  callable(array<string, mixed>): array<string, mixed>  $each
     * @return array<string, mixed>
     */
    private static function list(array $in, string $path, array $query, callable $each, string $named): array
    {
        $found = PromptxApi::call(fn () => PromptxApi::as($in['token'])->get(PromptxApi::FLOWS.$path, [...$query, 'limit' => 100]))->json('data');
        $found = array_map($each, array_values(array_filter(is_array($found) ? $found : [], 'is_array')));

        return [$named => $found, 'count' => count($found)];
    }

    /**
     * @param  array{token: string, project: string}  $in
     * @return array{id: mixed, name: mixed}
     */
    private static function folder(StepContext $step, array $in): array
    {
        $name = trim($step->text('name')) ?: throw new StepFailed('The folder has no name.');
        $made = PromptxApi::send($step, $in['project'], fn () => PromptxApi::as($in['token'])
            ->post(PromptxApi::FLOWS.'/folders', ['displayName' => $name, 'projectId' => $in['project']]));

        return ['id' => $made->json('id'), 'name' => $made->json('displayName') ?? $name];
    }

    /**
     * A table's records, each by its fields' names, with its fields.
     *
     * @param  array{token: string, project: string}  $in
     * @return array{fields: list<array<string, mixed>>, records: list<array<string, mixed>>, count: int}
     */
    private static function records(StepContext $step, array $in): array
    {
        $table = PromptxCall::id($step, 'table', 'table');
        $rows = PromptxApi::call(fn () => PromptxApi::as($in['token'])->get(PromptxApi::FLOWS.'/records', [
            'tableId' => $table,
            'limit' => max(1, min(500, (int) ($step->setting('count') ?: 100))),
        ]))->json('data');
        $records = [];

        foreach (array_filter(is_array($rows) ? $rows : [], 'is_array') as $row) {
            $record = ['id' => $row['id'] ?? null];

            foreach ((array) ($row['cells'] ?? []) as $cell) {
                if (is_array($cell) && isset($cell['fieldName'])) {
                    $record[(string) $cell['fieldName']] = $cell['value'] ?? null;
                }
            }

            $records[] = $record;
        }

        return ['fields' => self::fields($in, $table), 'records' => $records, 'count' => count($records)];
    }

    /**
     * Adds records to a table, each a record of values by its fields' names.
     *
     * @param  array{token: string, project: string}  $in
     * @return array{added: int, ids: list<mixed>}
     */
    private static function add(StepContext $step, array $in): array
    {
        $table = PromptxCall::id($step, 'table', 'table');
        $given = $step->template('records');
        $given = is_string($given) ? json_decode($given, true) : $given;

        if (! is_array($given) || $given === []) {
            throw new StepFailed('There are no records to add: a list of them, or one, each with values by field name.');
        }

        $given = array_is_list($given) ? $given : [$given];
        $fields = array_column(self::fields($in, $table), 'id', 'name');
        $records = [];

        foreach ($given as $record) {
            $cells = [];

            foreach ((array) $record as $name => $value) {
                $cells[] = [
                    'fieldId' => $fields[$name] ?? throw new StepFailed("The table has no field \"{$name}\": it has ".implode(', ', array_keys($fields)).'.'),
                    'value' => is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];
            }

            $records[] = $cells;
        }

        $made = PromptxApi::send($step, $table, fn () => PromptxApi::as($in['token'])
            ->post(PromptxApi::FLOWS.'/records', ['tableId' => $table, 'records' => $records]))->json();
        $made = is_array($made) ? (array_is_list($made) ? $made : (array) ($made['data'] ?? [])) : [];

        return ['added' => count($records), 'ids' => array_values(array_map(fn ($one) => is_array($one) ? ($one['id'] ?? null) : null, $made))];
    }

    /**
     * A table's fields: each one's id, name and kind.
     *
     * @param  array{token: string, project: string}  $in
     * @return list<array{id: mixed, name: mixed, type: mixed}>
     */
    private static function fields(array $in, string $table): array
    {
        $fields = PromptxApi::call(fn () => PromptxApi::as($in['token'])->get(PromptxApi::FLOWS.'/fields', ['tableId' => $table]))->json();
        $fields = is_array($fields) ? (array_is_list($fields) ? $fields : (array) ($fields['data'] ?? [])) : [];

        return array_values(array_map(fn (array $one) => ['id' => $one['id'] ?? null, 'name' => $one['name'] ?? null, 'type' => $one['type'] ?? null], array_filter($fields, 'is_array')));
    }
}
