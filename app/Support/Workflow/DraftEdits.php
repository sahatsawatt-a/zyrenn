<?php

namespace App\Support\Workflow;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Changes to a draft as a client asks for them, by step key -- never the
 * whole draft written over: steps taken away, changed (settings merged; a
 * key renamed is renamed in node("…") everywhere), added (set up with their
 * kind's defaults, placed beside the step they follow, joined to it), and
 * joins undone or made. In that order, so a step added may follow one just
 * renamed, and be joined at once.
 * What comes out is checked as any save is (Definition::normalize).
 *
 * @phpstan-import-type Shape from Definition
 */
final class DraftEdits
{
    private const KEY = '/^[a-z][a-z0-9_]{0,39}$/';

    /** How far apart steps are placed, across and down. */
    private const ACROSS = 320;

    private const DOWN = 160;

    /**
     * @param  Shape  $draft
     * @param  array{remove?: list<string>, add?: list<array<string, mixed>>, change?: list<array<string, mixed>>, unjoin?: list<array<string, mixed>>, join?: list<array<string, mixed>>}  $edits
     * @return array<string, mixed> the definition, to be saved
     *
     * @throws InvalidArgumentException naming what can't be done
     */
    public static function apply(array $draft, array $edits): array
    {
        $nodes = collect($draft['nodes'])->keyBy('key')->all();
        $edges = $draft['edges'];
        // Of the steps as they are by then: a key renamed earlier in the same call is found by its new name
        $idOf = function (string $key) use (&$nodes): string {
            return ($nodes[$key] ?? throw new InvalidArgumentException("There is no step \"{$key}\". get-workflow lists them."))['id'];
        };

        foreach ($edits['remove'] ?? [] as $key) {
            $id = $idOf((string) $key);
            unset($nodes[$key]);
            $edges = array_values(array_filter($edges, fn (array $edge) => $edge['source'] !== $id && $edge['target'] !== $id));
        }

        foreach ($edits['change'] ?? [] as $change) {
            $key = (string) ($change['key'] ?? '');
            $node = $nodes[$key] ?? throw new InvalidArgumentException("There is no step \"{$key}\". get-workflow lists them.");

            if (array_key_exists('label', $change)) {
                $node['label'] = (string) $change['label'];
            }

            if (array_key_exists('on_error', $change)) {
                $node['onError'] = $change['on_error'] === 'continue' ? 'continue' : 'stop';
            }

            // Merged: a setting given null goes back to nothing
            foreach (self::settings($change['settings'] ?? []) as $name => $value) {
                if ($value === null) {
                    unset($node['config'][$name]);
                } else {
                    $node['config'][$name] = $value;
                }
            }

            $nodes[$key] = $node;

            if (isset($change['new_key']) && $change['new_key'] !== $key) {
                $nodes = self::rekey($nodes, $key, (string) $change['new_key']);
            }
        }

        foreach ($edits['add'] ?? [] as $step) {
            $type = NodeTypes::get((string) ($step['type'] ?? ''))
                ?? throw new InvalidArgumentException('There is no kind of step "'.($step['type'] ?? '').'". list-workflow-steps lists them.');
            $key = isset($step['key']) ? (string) $step['key'] : self::freshKey($type['label'], array_keys($nodes));

            if (! preg_match(self::KEY, $key)) {
                throw new InvalidArgumentException("\"{$key}\" can't be a step's key: lowercase letters, digits and _, starting with a letter.");
            }

            if (isset($nodes[$key])) {
                throw new InvalidArgumentException("There is a step \"{$key}\" already.");
            }

            $after = isset($step['after']) ? ($nodes[(string) $step['after']] ?? throw new InvalidArgumentException("There is no step \"{$step['after']}\" to follow.")) : null;

            $nodes[$key] = [
                'id' => Str::lower(Str::random(8)),
                'key' => $key,
                'type' => $type['type'],
                'nodeVersion' => $type['version'],
                'label' => (string) ($step['label'] ?? $type['label']),
                'position' => self::placeFor(array_values($nodes), $after),
                'config' => [...self::defaults($type), ...self::settings($step['settings'] ?? [])],
                'onError' => ($step['on_error'] ?? 'stop') === 'continue' ? 'continue' : 'stop',
            ];

            if ($after !== null) {
                $edges[] = self::edge($after, isset($step['way']) ? (string) $step['way'] : null, $nodes[$key]);
            }
        }

        foreach ($edits['unjoin'] ?? [] as $join) {
            $from = $idOf((string) ($join['from'] ?? ''));
            $to = $idOf((string) ($join['to'] ?? ''));
            $before = count($edges);
            $edges = array_values(array_filter($edges, fn (array $edge) => ! (
                $edge['source'] === $from && $edge['target'] === $to
                && (! isset($join['way']) || $edge['sourceHandle'] === $join['way'])
            )));

            if (count($edges) === $before) {
                throw new InvalidArgumentException("\"{$join['from']}\" isn't joined to \"{$join['to']}\".");
            }
        }

        foreach ($edits['join'] ?? [] as $join) {
            $from = $nodes[(string) ($join['from'] ?? '')] ?? throw new InvalidArgumentException('There is no step "'.($join['from'] ?? '').'" to join from.');
            $to = $nodes[(string) ($join['to'] ?? '')] ?? throw new InvalidArgumentException('There is no step "'.($join['to'] ?? '').'" to join to.');
            $edges[] = self::edge($from, isset($join['way']) ? (string) $join['way'] : null, $to);
        }

        return [
            'schemaVersion' => $draft['schemaVersion'],
            'nodes' => array_values($nodes),
            'edges' => $edges,
            'settings' => $draft['settings'],
        ];
    }

    /**
     * A join from one step's way out to another; the way may be left out
     * when the step has only one.
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return array<string, string>
     */
    private static function edge(array $from, ?string $way, array $to): array
    {
        /** @var array{id: string, key: string, type: string, config?: array<string, mixed>} $from */
        $outputs = NodeTypes::outputsOf($from);
        $way ??= count($outputs) === 1 ? $outputs[0] : throw new InvalidArgumentException(
            "\"{$from['key']}\" has more than one way out; say which: ".implode(', ', $outputs).'.',
        );

        if (! in_array($way, $outputs, true)) {
            throw new InvalidArgumentException("\"{$from['key']}\" has no way out called \"{$way}\"; its ways are ".implode(', ', $outputs).'.');
        }

        return [
            'id' => "{$from['id']}.{$way}.{$to['id']}",
            'source' => (string) $from['id'],
            'sourceHandle' => $way,
            'target' => (string) $to['id'],
            'targetHandle' => NodeTypes::IN,
        ];
    }

    /**
     * A kind's settings as a new step of it has them: its defaults, and a
     * fresh secret for a webhook's address.
     *
     * @param  array{config: list<array{name: string, type: string, default?: mixed}>}  $type
     * @return array<string, mixed>
     */
    private static function defaults(array $type): array
    {
        $config = [];

        foreach ($type['config'] as $setting) {
            $config[$setting['name']] = $setting['type'] === 'token' ? Str::random(32) : ($setting['default'] ?? null);
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private static function settings(mixed $given): array
    {
        if (! is_array($given)) {
            throw new InvalidArgumentException('Settings are an object of name: value.');
        }

        return $given;
    }

    /**
     * A key no step has: the kind's name in snake case, numbered when taken.
     *
     * @param  list<string>  $taken
     */
    private static function freshKey(string $label, array $taken): string
    {
        $base = mb_substr(trim(preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii($label))) ?? '', '_'), 0, 36) ?: 'step';
        $base = ctype_alpha($base[0]) ? $base : "s_{$base}";
        $key = $base;

        for ($n = 2; in_array($key, $taken, true); $n++) {
            $key = "{$base}_{$n}";
        }

        return $key;
    }

    /**
     * Where a new step goes: just after the one it follows, below any it
     * would sit on; else after the rightmost.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, mixed>|null  $after
     * @return array{x: float, y: float}
     */
    private static function placeFor(array $nodes, ?array $after): array
    {
        if ($after !== null) {
            $x = $after['position']['x'] + self::ACROSS;
            $y = $after['position']['y'];

            while (collect($nodes)->contains(fn (array $node) => abs($node['position']['x'] - $x) < self::ACROSS / 2 && abs($node['position']['y'] - $y) < self::DOWN / 2)) {
                $y += self::DOWN;
            }

            return ['x' => (float) $x, 'y' => (float) $y];
        }

        if ($nodes === []) {
            return ['x' => 0.0, 'y' => 0.0];
        }

        return ['x' => (float) (max(array_map(fn (array $node) => $node['position']['x'], $nodes)) + self::ACROSS), 'y' => 0.0];
    }

    /**
     * A step's key renamed, and every node("old") in every setting with it.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @return array<string, array<string, mixed>>
     */
    private static function rekey(array $nodes, string $old, string $new): array
    {
        if (! preg_match(self::KEY, $new)) {
            throw new InvalidArgumentException("\"{$new}\" can't be a step's key: lowercase letters, digits and _, starting with a letter.");
        }

        if (isset($nodes[$new])) {
            throw new InvalidArgumentException("There is a step \"{$new}\" already.");
        }

        $pattern = '/node\(\s*"'.preg_quote($old, '/').'"\s*\)/';
        $renamed = [];

        foreach ($nodes as $key => $node) {
            array_walk_recursive($node['config'], function (&$value) use ($pattern, $new) {
                if (is_string($value)) {
                    $value = preg_replace($pattern, "node(\"{$new}\")", $value);
                }
            });

            if ($key === $old) {
                $node['key'] = $new;
                $key = $new;
            }

            $renamed[$key] = $node;
        }

        return $renamed;
    }
}
