<?php

namespace App\Mcp;

use App\Mcp\Servers\Toolset;
use Laravel\Sanctum\Contracts\HasAbilities;

/**
 * What an MCP key may do, part by part: for each part of the app (notes,
 * boards, the Drive ...) nothing, read, or read and change. A client using
 * the key is offered only the tools it allows, and cannot call the others.
 *
 * Kept as the key's abilities: "mcp" (any MCP key), "mcp:scoped" (one with
 * its parts chosen), then "<part>:read" or "<part>:change" for each part it
 * may use. A key made before parts could be chosen has "mcp" alone, and may
 * do everything, as it always could.
 */
final class Scopes
{
    public const OFF = 'off';

    public const READ = 'read';

    public const CHANGE = 'change';

    public const LEVELS = [self::OFF, self::READ, self::CHANGE];

    /** Each part, as a person reads it. */
    public const PARTS = [
        'notes' => 'Notes',
        'boards' => 'Boards',
        'tables' => 'Tables',
        'trips' => 'Trips',
        'places' => 'Saved places',
        'drive' => 'Drive',
        'workflows' => 'Workflows',
        'checks' => 'Browser checks',
        'projects' => 'Projects',
        'tickets' => 'Tickets',
        'product' => 'Product',
        'chats' => 'Chats with customers',
    ];

    /** The part each tool's folder (App\Mcp\Tools\<folder>) is about. */
    private const FOLDERS = [
        'Notes' => 'notes',
        'Boards' => 'boards',
        'Tables' => 'tables',
        'Trips' => 'trips',
        'Places' => 'places',
        'Drive' => 'drive',
        'Workflows' => 'workflows',
        'BrowserChecks' => 'checks',
        'Projects' => 'projects',
        'Tickets' => 'tickets',
        'Product' => 'product',
        'Chats' => 'chats',
    ];

    /** Tools that change nothing, by how their names begin -- or by name. */
    private const READING = ['List', 'Get', 'Search'];

    private const ALSO_READING = ['LookAtPage'];

    /**
     * The part a tool is about, and whether it changes anything; null for a
     * tool of no part, which a key with its parts chosen never offers.
     *
     * @param  class-string|object  $tool
     * @return array{part: string, changes: bool}|null
     */
    public static function of(string|object $tool): ?array
    {
        $class = is_object($tool) ? $tool::class : $tool;
        $parts = explode('\\', $class);
        $part = self::FOLDERS[$parts[count($parts) - 2] ?? ''] ?? null;

        if ($part === null) {
            return null;
        }

        $name = end($parts);
        $reads = in_array($name, self::ALSO_READING, true)
            || collect(self::READING)->contains(fn (string $prefix) => str_starts_with($name, $prefix));

        return ['part' => $part, 'changes' => ! $reads];
    }

    /**
     * The abilities a key is given for `$levels`, part => off|read|change;
     * a part left out is off.
     *
     * @param  array<string, string>  $levels
     * @return list<string>
     */
    public static function abilities(array $levels): array
    {
        // Everything is a key of every part, those to come too
        if (collect(array_keys(self::PARTS))->every(fn (string $part) => ($levels[$part] ?? self::OFF) === self::CHANGE)) {
            return ['mcp'];
        }

        $abilities = ['mcp', 'mcp:scoped'];

        foreach (array_keys(self::PARTS) as $part) {
            $level = $levels[$part] ?? self::OFF;

            if ($level !== self::OFF) {
                $abilities[] = "{$part}:{$level}";
            }
        }

        return $abilities;
    }

    /**
     * What a key may do in each part; a key of "mcp" alone, all of it.
     *
     * @return array<string, string>
     */
    public static function levels(HasAbilities $token): array
    {
        $abilities = (array) ($token->abilities ?? []);
        $scoped = in_array('mcp:scoped', $abilities, true);

        return collect(self::PARTS)->map(fn (string $label, string $part) => match (true) {
            ! $scoped, in_array("{$part}:".self::CHANGE, $abilities, true) => self::CHANGE,
            in_array("{$part}:".self::READ, $abilities, true) => self::READ,
            default => self::OFF,
        })->all();
    }

    /**
     * Whether the key allows a tool. No key -- a call made inside the app, or
     * by the admin server -- allows every tool.
     *
     * @param  class-string|object  $tool
     */
    public static function allows(?HasAbilities $token, string|object $tool): bool
    {
        if (! $token instanceof HasAbilities || ! $token->can('mcp:scoped')) {
            return true;
        }

        $of = self::of($tool);

        if ($of === null) {
            return false;
        }

        $level = self::levels($token)[$of['part']];

        return $level === self::CHANGE || ($level === self::READ && ! $of['changes']);
    }

    /**
     * Every tool of each part, by name, and whether it changes anything: what
     * a person choosing a key's parts sees each level offer.
     *
     * @return array<string, list<array{name: string, changes: bool}>>
     */
    public static function tools(): array
    {
        $tools = array_fill_keys(array_keys(self::PARTS), []);

        foreach (Toolset::tools(global: false) as $tool) {
            $of = self::of($tool);

            if ($of !== null) {
                $tools[$of['part']][] = ['name' => $tool->name(), 'changes' => $of['changes']];
            }
        }

        return $tools;
    }

    /**
     * The parts a key lets a client see at all: for the server's own
     * instructions, which then leave out the rest.
     *
     * @return list<string>
     */
    public static function seen(?HasAbilities $token): array
    {
        if (! $token instanceof HasAbilities || ! $token->can('mcp:scoped')) {
            return array_keys(self::PARTS);
        }

        return array_keys(array_filter(self::levels($token), fn (string $level) => $level !== self::OFF));
    }
}
