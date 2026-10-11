<?php

namespace App\Support\Workflow;

use App\Support\Formula\Formula;
use App\Support\Formula\FormulaError;
use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Support\Str;

/**
 * What a workflow is, as it is kept: its steps (nodes), the connections
 * between them (edges), and where each sits on the canvas -- independent of
 * the editor that draws it.
 *
 *     {
 *       "schemaVersion": 1,
 *       "nodes": [{ "id", "key", "type", "nodeVersion", "label", "position": {x, y}, "config": {}, "onError" }],
 *       "edges": [{ "id", "source", "sourceHandle", "target", "targetHandle" }],
 *       "settings": {}
 *     }
 *
 * Two levels of wrong. A definition that isn't a workflow at all -- a step of
 * no known type, a connection to nowhere -- is refused when it is saved
 * (normalize). One that is a workflow but unfinished -- a condition not yet
 * written, a step nothing leads to -- can be saved as a draft, and is only
 * refused when it is published (problems).
 *
 * @phpstan-type Node array{id: string, key: string, type: string, nodeVersion: int, label: string, position: array{x: float, y: float}, config: array<string, mixed>, onError: string}
 * @phpstan-type Edge array{id: string, source: string, sourceHandle: string, target: string, targetHandle: string}
 * @phpstan-type Shape array{schemaVersion: int, nodes: list<Node>, edges: list<Edge>, settings: array<string, mixed>}
 * @phpstan-type Problem array{message: string, node?: string, edge?: string}
 */
final class Definition
{
    public const SCHEMA_VERSION = 1;

    public const MAX_NODES = 200;

    public const MAX_EDGES = 500;

    private const MAX_TEXT = 10000;

    private const MAX_FIELDS = 100;

    /**
     * A new workflow: something to start it, and nothing yet after.
     *
     * @return Shape
     */
    public static function blank(): array
    {
        return self::normalize([
            'nodes' => [[
                'id' => Str::lower(Str::random(8)),
                'key' => 'start',
                'type' => 'trigger.manual',
                'position' => ['x' => 0, 'y' => 0],
            ]],
            'edges' => [],
        ]);
    }

    /**
     * The definition as it is kept: every step's settings filled in to their
     * defaults, anything unknown left out.
     *
     * @return Shape
     *
     * @throws DefinitionInvalid listing everything that makes it not a workflow
     */
    public static function normalize(mixed $raw): array
    {
        $errors = [];
        $raw = is_array($raw) ? $raw : [];
        $nodesIn = is_array($raw['nodes'] ?? null) ? array_values($raw['nodes']) : [];
        $edgesIn = is_array($raw['edges'] ?? null) ? array_values($raw['edges']) : [];

        if (($raw['schemaVersion'] ?? self::SCHEMA_VERSION) !== self::SCHEMA_VERSION) {
            throw new DefinitionInvalid(['This workflow was made by a newer version of the app.']);
        }

        if (count($nodesIn) > self::MAX_NODES) {
            throw new DefinitionInvalid(['A workflow can have at most '.self::MAX_NODES.' steps.']);
        }

        if (count($edgesIn) > self::MAX_EDGES) {
            throw new DefinitionInvalid(['A workflow can have at most '.self::MAX_EDGES.' connections.']);
        }

        /** @var array<string, Node> $nodes */
        $nodes = [];
        $keys = [];

        foreach ($nodesIn as $at => $node) {
            $node = is_array($node) ? $node : [];
            $id = $node['id'] ?? null;
            $where = is_string($id) ? "Step {$id}" : 'Step '.($at + 1);

            if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
                $errors[] = "{$where} has no proper id.";

                continue;
            }

            if (isset($nodes[$id])) {
                $errors[] = "Two steps have the id {$id}.";

                continue;
            }

            $type = NodeTypes::get(is_string($node['type'] ?? null) ? $node['type'] : '');

            if ($type === null) {
                $errors[] = "{$where} is of a kind that does not exist: ".json_encode($node['type'] ?? null).'.';

                continue;
            }

            $version = $node['nodeVersion'] ?? $type['version'];

            if (! is_int($version) || $version < 1 || $version > $type['version']) {
                $errors[] = "{$where} ({$type['label']}) is of a version this app does not have.";

                continue;
            }

            $key = $node['key'] ?? null;

            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) {
                $errors[] = "{$where} needs a key of lowercase letters, digits and _, starting with a letter.";

                continue;
            }

            if (isset($keys[$key])) {
                $errors[] = "Two steps have the key \"{$key}\".";

                continue;
            }

            $label = is_string($node['label'] ?? null) ? trim($node['label']) : '';
            $config = self::config($type, is_array($node['config'] ?? null) ? $node['config'] : [], $where, $errors);

            $keys[$key] = true;
            $nodes[$id] = [
                'id' => $id,
                'key' => $key,
                'type' => $type['type'],
                'nodeVersion' => $version,
                'label' => mb_substr($label !== '' ? $label : $type['label'], 0, 80),
                'position' => [
                    'x' => self::coordinate($node['position']['x'] ?? 0),
                    'y' => self::coordinate($node['position']['y'] ?? 0),
                ],
                'config' => $config,
                'onError' => ($node['onError'] ?? 'stop') === 'continue' ? 'continue' : 'stop',
            ];
        }

        $edges = [];
        $seen = [];

        foreach ($edgesIn as $at => $edge) {
            $edge = is_array($edge) ? $edge : [];
            $id = $edge['id'] ?? null;

            if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $id) || isset($edges[$id])) {
                $errors[] = 'Connection '.($at + 1).' has no id of its own.';

                continue;
            }

            $source = $nodes[$edge['source'] ?? ''] ?? null;
            $target = $nodes[$edge['target'] ?? ''] ?? null;

            if ($source === null || $target === null) {
                $errors[] = "Connection {$id} leads from or to a step that isn't there.";

                continue;
            }

            if ($source['id'] === $target['id']) {
                $errors[] = "Connection {$id} leads from a step back to itself.";

                continue;
            }

            /** @var array{outputs: list<string>, inputs: list<string>, label: string} $to */
            $to = NodeTypes::get($target['type']);
            $outputs = NodeTypes::outputsOf($source);
            $handle = $edge['sourceHandle'] ?? null;
            $handle = $handle === null && count($outputs) === 1 ? $outputs[0] : $handle;

            if (! in_array($handle, $outputs, true)) {
                $errors[] = "\"{$source['label']}\" has no way out called ".json_encode($handle).'.';

                continue;
            }

            if ($to['inputs'] === []) {
                $errors[] = "Nothing can lead into \"{$target['label']}\": it starts a run.";

                continue;
            }

            $pair = "{$source['id']}|{$handle}|{$target['id']}";

            if (isset($seen[$pair])) {
                continue;
            }

            $seen[$pair] = true;
            $edges[$id] = [
                'id' => $id,
                'source' => $source['id'],
                'sourceHandle' => $handle,
                'target' => $target['id'],
                'targetHandle' => $to['inputs'][0],
            ];
        }

        if ($errors !== []) {
            throw new DefinitionInvalid(array_values(array_unique($errors)));
        }

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'nodes' => array_values($nodes),
            'edges' => array_values($edges),
            'settings' => [],
        ];
    }

    /**
     * What stands in the way of publishing a kept definition, each tied to the
     * step or connection it is about when there is one.
     *
     * @param  Shape  $definition
     * @return list<Problem>
     */
    public static function problems(array $definition): array
    {
        $problems = [];
        $nodes = array_column($definition['nodes'], null, 'id');
        $byKey = array_column($definition['nodes'], 'id', 'key');
        $parents = array_fill_keys(array_keys($nodes), []);
        $children = array_fill_keys(array_keys($nodes), []);

        foreach ($definition['edges'] as $edge) {
            $parents[$edge['target']][] = $edge['source'];
            $children[$edge['source']][] = $edge['target'];
        }

        $triggers = array_keys(array_filter($nodes, fn (array $node) => NodeTypes::get($node['type'])['trigger'] ?? false));

        if ($triggers === []) {
            $problems[] = ['message' => 'Nothing starts this workflow. Add a trigger, such as "Run by hand".'];
        }

        $cycle = self::cycle($children);

        if ($cycle !== null) {
            $names = array_map(fn (string $id) => $nodes[$id]['label'], $cycle);
            $problems[] = [
                'message' => 'These steps go round in a circle: '.implode(' → ', $names).'.',
                'node' => $cycle[0],
            ];
        }

        // What a trigger leads to, directly or not
        $reached = [];
        $queue = $triggers;

        while ($queue !== []) {
            $id = array_shift($queue);

            if (isset($reached[$id])) {
                continue;
            }

            $reached[$id] = true;
            array_push($queue, ...$children[$id]);
        }

        foreach ($nodes as $id => $node) {
            /** @var array{label: string, config: list<array{name: string, label: string, type: string, required: bool}>, trigger: bool} $type */
            $type = NodeTypes::get($node['type']);

            if (! isset($reached[$id])) {
                $problems[] = ['message' => 'Nothing leads to this step, so it would never run.', 'node' => $id];
            }

            // The steps before it, whose output it may read
            $before = $cycle === null ? self::ancestors($id, $parents) : null;

            foreach ($type['config'] as $setting) {
                $value = $node['config'][$setting['name']] ?? null;
                $empty = $value === null || $value === '' || $value === [] || (is_string($value) && trim($value) === '');

                if ($empty) {
                    if ($setting['required']) {
                        $problems[] = ['message' => "Give it a {$setting['label']}.", 'node' => $id];
                    }

                    continue;
                }

                foreach (self::settingProblems($setting, $value, $before, $byKey) as $message) {
                    $problems[] = ['message' => "{$setting['label']}: {$message}", 'node' => $id];
                }

                if ($setting['name'] === 'timezone' && ! in_array($value, DateTimeZone::listIdentifiers(), true)) {
                    $problems[] = ['message' => "{$setting['label']}: there is no time zone called \"{$value}\".", 'node' => $id];
                }
            }
        }

        return $problems;
    }

    /**
     * A step's settings, each of the kind it should be, defaults for what is missing.
     *
     * @param  array{config: list<array<string, mixed>>, label: string}  $type
     * @param  array<string, mixed>  $given
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    private static function config(array $type, array $given, string $where, array &$errors): array
    {
        $config = [];

        foreach ($type['config'] as $setting) {
            $name = $setting['name'];
            $value = array_key_exists($name, $given) ? $given[$name] : ($setting['default'] ?? null);
            $label = "{$where} ({$type['label']}): {$setting['label']}";

            switch ($setting['type']) {
                case 'choice':
                    if ($value !== null && ! array_key_exists((string) $value, $setting['options'] ?? [])) {
                        $errors[] = "{$label} is not one of its choices.";
                        $value = null;
                    }
                    break;

                case 'fields':
                    $value = is_array($value) ? array_values($value) : [];

                    if (count($value) > self::MAX_FIELDS) {
                        $errors[] = "{$label} has more than ".self::MAX_FIELDS.' values.';
                        $value = [];
                    }

                    $value = array_map(fn ($field) => [
                        'name' => mb_substr(is_array($field) && is_string($field['name'] ?? null) ? trim($field['name']) : '', 0, 80),
                        'value' => mb_substr(is_array($field) && is_scalar($field['value'] ?? null) ? (string) $field['value'] : '', 0, self::MAX_TEXT),
                    ], $value);
                    break;

                case 'number':
                    if ($value !== null && $value !== '' && ! is_numeric($value)) {
                        $errors[] = "{$label} should be a number.";
                    }

                    $value = is_numeric($value) ? (int) $value : null;
                    break;

                case 'token':
                    // Made once, on the step's first save; kept from then on
                    if (! is_string($value) || $value === '') {
                        $value = Str::random(32);
                    } elseif (! preg_match('/^[A-Za-z0-9]{20,64}$/', $value)) {
                        $errors[] = "{$label} is not one this app made.";
                        $value = null;
                    }
                    break;

                default:
                    if ($value !== null && ! is_string($value)) {
                        $errors[] = "{$label} should be text.";
                        $value = null;
                    } elseif (is_string($value) && mb_strlen($value) > self::MAX_TEXT) {
                        $errors[] = "{$label} is longer than ".self::MAX_TEXT.' characters.';
                        $value = null;
                    }
            }

            $config[$name] = $value;
        }

        return $config;
    }

    /**
     * What is wrong with one filled-in setting, in its own terms.
     *
     * @param  array{type: string, ways?: bool}  $setting
     * @param  array<string, true>|null  $before  the ids of the steps before this one; null when that can't be known
     * @param  array<string, string>  $byKey  step id by key
     * @return list<string>
     */
    private static function settingProblems(array $setting, mixed $value, ?array $before, array $byKey): array
    {
        $trees = [];

        try {
            switch ($setting['type']) {
                case 'formula':
                    $trees[] = Formula::parse((string) $value);
                    break;

                case 'template':
                case 'room':
                case 'folder':
                    $trees = Template::parse((string) $value);
                    break;

                case 'cron':
                    return CronExpression::isValidExpression((string) $value)
                        ? []
                        : ['it is not a cron line, like "0 9 * * 1-5" for 9:00 on weekdays.'];

                case 'json':
                    $decoded = json_decode((string) $value, true);

                    if (! is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
                        return ['it should be a JSON object, like {"name": "Ada"}.'];
                    }

                    return [];

                case 'fields':
                    $messages = [];
                    $names = [];

                    foreach ((array) $value as $field) {
                        if ($field['name'] === '') {
                            $messages[] = 'every value needs a name.';
                        } elseif (isset($names[$field['name']])) {
                            $messages[] = "\"{$field['name']}\" is named twice.";
                        } elseif (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field['name'])) {
                            $messages[] = "\"{$field['name']}\" can only have letters, digits and _, so a formula can read it.";
                        }

                        $names[$field['name']] = true;

                        // A Switch's ways: each a formula, none called what its last way is
                        if ($setting['ways'] ?? false) {
                            if ($field['name'] === NodeTypes::OTHERWISE) {
                                $messages[] = '"otherwise" is the way taken when no other is; name this one something else.';
                            }

                            if (trim($field['value']) === '') {
                                $messages[] = ($field['name'] !== '' ? "\"{$field['name']}\" " : 'every way ').'needs a formula saying when it is taken.';

                                continue;
                            }

                            try {
                                $trees[] = Formula::parse($field['value']);
                            } catch (FormulaError $e) {
                                $messages[] = ($field['name'] !== '' ? "\"{$field['name']}\": " : '').$e->getMessage();
                            }

                            continue;
                        }

                        try {
                            array_push($trees, ...Template::parse($field['value']));
                        } catch (FormulaError $e) {
                            $messages[] = ($field['name'] !== '' ? "\"{$field['name']}\": " : '').$e->getMessage();
                        }
                    }

                    if ($messages !== []) {
                        return array_values(array_unique($messages));
                    }
                    break;
            }
        } catch (FormulaError $e) {
            return [$e->getMessage()];
        }

        if ($before === null) {
            return [];
        }

        $messages = [];

        foreach ($trees as $tree) {
            foreach (Template::nodesNamed($tree) as $key) {
                if (! isset($byKey[$key])) {
                    $messages[] = "there is no step with the key \"{$key}\".";
                } elseif (! isset($before[$byKey[$key]])) {
                    $messages[] = "node(\"{$key}\") does not come before this step, so its output isn't there yet.";
                }
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * The steps before one, directly or not.
     *
     * @param  array<string, list<string>>  $parents
     * @return array<string, true>
     */
    private static function ancestors(string $id, array $parents): array
    {
        $found = [];
        $queue = $parents[$id] ?? [];

        while ($queue !== []) {
            $next = array_shift($queue);

            if (! isset($found[$next])) {
                $found[$next] = true;
                array_push($queue, ...$parents[$next] ?? []);
            }
        }

        return $found;
    }

    /**
     * A circle of steps, in order, or null when there is none.
     *
     * @param  array<string, list<string>>  $children
     * @return array<int, string>|null
     */
    private static function cycle(array $children): ?array
    {
        // 0 unseen, 1 on the current path, 2 done
        $state = array_fill_keys(array_keys($children), 0);
        $path = [];

        $visit = function (string $id) use (&$visit, &$state, &$path, $children): ?array {
            $state[$id] = 1;
            $path[] = $id;

            foreach ($children[$id] as $next) {
                if ($state[$next] === 1) {
                    return array_slice($path, (int) array_search($next, $path, true));
                }

                if ($state[$next] === 0 && ($found = $visit($next)) !== null) {
                    return $found;
                }
            }

            array_pop($path);
            $state[$id] = 2;

            return null;
        };

        foreach (array_keys($children) as $id) {
            if ($state[$id] === 0 && ($found = $visit($id)) !== null) {
                return $found;
            }
        }

        return null;
    }

    private static function coordinate(mixed $value): float
    {
        return is_numeric($value) && is_finite((float) $value) ? round((float) $value, 1) : 0.0;
    }
}
