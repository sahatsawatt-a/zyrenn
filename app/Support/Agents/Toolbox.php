<?php

namespace App\Support\Agents;

use App\Mcp\Tools;
use App\Models\Connection;
use App\Models\Owner;
use App\Models\Project;
use App\Models\User;
use App\Support\Connections\Services;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Client;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool as ServerTool;
use Throwable;

/**
 * The tools an agent may be given, and the calling of them: Zyrenn's own --
 * the MCP tools it offers AI clients, run here as the person the agent works
 * for, in its owner's project -- and those of the MCP servers its owner has
 * connected. A tool that says it only reads is "reads"; any other may change
 * something, and is offered to a model only where that is allowed.
 *
 * @phpstan-type Offered array{server: string, server_name: string, name: string, description: string, reads: bool, schema: array<string, mixed>}
 * @phpstan-type Done array{tool: string, server: string, arguments: array<string, mixed>, ok: bool, said: string}
 */
final class Toolbox
{
    /** Zyrenn itself, as a tool's server. */
    public const OWN = 'zyrenn';

    /** The most of a tool's answer given back to the model, and shown beside the answer. */
    private const MAX_RESULT = 8000;

    private const SHOWN = 2000;

    /** How long an MCP server's list of tools is kept before it is asked again. */
    private const LISTED_FOR = 600;

    /** Zyrenn's own tools an agent may be given: its owner's things, never other people's. */
    private const OWN_TOOLS = [
        Tools\Notes\ListNotes::class,
        Tools\Notes\GetNote::class,
        Tools\Notes\CreateNote::class,
        Tools\Notes\EditNote::class,
        Tools\Tables\ListTables::class,
        Tools\Tables\GetTable::class,
        Tools\Tables\UpdateTable::class,
        Tools\Tickets\ListTickets::class,
        Tools\Tickets\GetTicket::class,
        Tools\Tickets\CreateTicket::class,
        Tools\Tickets\UpdateTicket::class,
        Tools\Chats\ListChats::class,
        Tools\Chats\GetChat::class,
        Tools\Chats\UpdateChat::class,
        Tools\Drive\ListDrive::class,
        Tools\Drive\GetFile::class,
        Tools\Places\SearchPlaces::class,
    ];

    /**
     * Every tool the owner's agents may be given, server by server. A server
     * that cannot be asked is listed with why, and no tools.
     *
     * @return list<array{server: string, name: string, problem: string|null, tools: list<Offered>}>
     */
    public static function catalog(Owner $owner): array
    {
        $servers = [['server' => self::OWN, 'name' => 'Zyrenn', 'problem' => null, 'tools' => self::own()]];

        foreach ($owner->connections()->where('service', Services::MCP)->orderBy('name')->get() as $connection) {
            try {
                $servers[] = ['server' => $connection->ref_id, 'name' => $connection->name, 'problem' => null, 'tools' => self::listed($connection)];
            } catch (Throwable $e) {
                $servers[] = ['server' => $connection->ref_id, 'name' => $connection->name, 'problem' => mb_strimwidth($e->getMessage(), 0, 200, '…'), 'tools' => []];
            }
        }

        return $servers;
    }

    /**
     * What an agent was given of them, as a model is offered tools -- only
     * those that read, unless changing things is allowed -- with what each
     * name the model uses stands for.
     *
     * @param  list<array{server: string, name: string}>  $chosen
     * @return array{0: list<array<string, mixed>>, 1: array<string, Offered>}
     */
    public static function offer(Owner $owner, array $chosen, bool $changes): array
    {
        $offered = [];
        $named = [];
        $catalog = collect(self::catalog($owner))->keyBy('server');

        foreach ($chosen as $one) {
            $tool = collect($catalog[$one['server']]['tools'] ?? [])->firstWhere('name', $one['name']);

            if ($tool === null || (! $tool['reads'] && ! $changes)) {
                continue;
            }

            // As a model may name a function: letters, digits, _ and -, at most 64
            $as = mb_substr(preg_replace('/[^A-Za-z0-9_-]/', '_', ($tool['server'] === self::OWN ? '' : 'x'.substr($tool['server'], 0, 6).'__').$tool['name']) ?? '', 0, 64);
            $named[$as] = $tool;
            $offered[] = ['type' => 'function', 'function' => [
                'name' => $as,
                'description' => mb_substr($tool['description'], 0, 1000),
                'parameters' => $tool['schema'],
            ]];
        }

        return [$offered, $named];
    }

    /**
     * Use a tool, as $user, for $owner; what it says is given back to the
     * model, and kept short to be shown beside the answer.
     *
     * @param  Offered  $tool
     * @param  array<string, mixed>  $arguments
     * @return array{0: string, 1: Done}
     */
    public static function use(array $tool, array $arguments, User $user, Owner $owner): array
    {
        try {
            $said = $tool['server'] === self::OWN
                ? self::ownCall($tool['name'], $arguments, $user, $owner)
                : self::remoteCall($tool, $arguments, $owner);
            $ok = true;
        } catch (Throwable $e) {
            $said = 'It failed: '.mb_strimwidth($e->getMessage(), 0, 500, '…');
            $ok = false;
        }

        $said = mb_strcut($said, 0, self::MAX_RESULT, 'UTF-8');

        return [$said, [
            'tool' => $tool['name'],
            'server' => $tool['server_name'],
            'arguments' => $arguments,
            'ok' => $ok,
            'said' => mb_strimwidth($said, 0, self::SHOWN, '…'),
        ]];
    }

    /**
     * Zyrenn's own tools, as an agent of a project's or of someone's own is
     * offered them: "project" is filled in for it, so it is never asked for.
     *
     * @return list<Offered>
     */
    private static function own(): array
    {
        return array_map(function (string $class) {
            $tool = self::make($class);
            $described = $tool->toArray();
            $schema = json_decode((string) json_encode($described['inputSchema'] ?? []), true) ?: ['type' => 'object'];
            unset($schema['properties']['project']);
            $schema['required'] = array_values(array_diff($schema['required'] ?? [], ['project']));

            if ($schema['required'] === []) {
                unset($schema['required']);
            }

            $annotations = (array) ($described['annotations'] ?? []);

            return [
                'server' => self::OWN,
                'server_name' => 'Zyrenn',
                'name' => $tool->name(),
                'description' => $tool->description(),
                'reads' => (bool) ($annotations['readOnlyHint'] ?? false),
                'schema' => $schema,
            ];
        }, self::OWN_TOOLS);
    }

    private static function make(string $class): ServerTool
    {
        $tool = new $class;

        return $tool instanceof ServerTool ? $tool : throw new \RuntimeException("{$class} is not a tool.");
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function ownCall(string $name, array $arguments, User $user, Owner $owner): string
    {
        $class = collect(self::OWN_TOOLS)->first(fn (string $class) => self::make($class)->name() === $name)
            ?? throw new \RuntimeException("Zyrenn has no tool {$name}.");
        $handle = [self::make($class), 'handle'];

        if (! is_callable($handle)) {
            throw new \RuntimeException("Zyrenn's tool {$name} cannot be used.");
        }

        if ($owner instanceof Project) {
            $arguments['project'] = $owner->ref_id;
        } else {
            unset($arguments['project']);
        }

        // As the person the agent works for, and no one else after
        $before = Auth::user();
        Auth::setUser($user);

        try {
            $answer = Container::getInstance()->call($handle, ['request' => new Request($arguments)]);
        } finally {
            $before instanceof User ? Auth::setUser($before) : Auth::forgetUser();
        }

        if (self::failed($answer)) {
            throw new \RuntimeException(self::said($answer));
        }

        return self::said($answer);
    }

    /**
     * Whether a tool of Zyrenn's own answered with what went wrong.
     */
    private static function failed(mixed $answer): bool
    {
        return match (true) {
            $answer instanceof ResponseFactory => $answer->responses()->contains(fn (Response $one) => $one->isError()),
            $answer instanceof Response => $answer->isError(),
            default => false,
        };
    }

    /**
     * What a tool of Zyrenn's own answered, as text.
     */
    private static function said(mixed $answer): string
    {
        if ($answer instanceof ResponseFactory) {
            $structured = $answer->getStructuredContent();

            return $structured !== null
                ? (string) json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $answer->responses()->map(fn (Response $one) => (string) $one->content())->implode("\n");
        }

        return $answer instanceof Response ? (string) $answer->content() : (string) json_encode($answer);
    }

    /**
     * An MCP server's tools, as it lists them; kept a while, as asking takes time.
     *
     * @return list<Offered>
     */
    private static function listed(Connection $server): array
    {
        return Cache::remember("mcp-tools:{$server->id}:{$server->updated_at?->timestamp}", self::LISTED_FOR, function () use ($server) {
            $client = self::client($server);

            try {
                return array_values($client->tools()->map(fn ($tool) => [
                    'server' => $server->ref_id,
                    'server_name' => $server->name,
                    'name' => $tool->name,
                    'description' => (string) ($tool->description ?? $tool->title ?? ''),
                    'reads' => (bool) ($tool->annotations['readOnlyHint'] ?? false),
                    'schema' => $tool->inputSchema ?: ['type' => 'object', 'properties' => (object) []],
                ])->all());
            } finally {
                $client->disconnect();
            }
        });
    }

    /**
     * @param  Offered  $tool
     * @param  array<string, mixed>  $arguments
     */
    private static function remoteCall(array $tool, array $arguments, Owner $owner): string
    {
        $server = $owner->connections()->where('service', Services::MCP)->where('ref_id', $tool['server'])->first()
            ?? throw new \RuntimeException('Its MCP server is gone.');
        $client = self::client($server);

        try {
            $result = $client->callTool($tool['name'], $arguments);
        } finally {
            $client->disconnect();
        }

        $text = $result->structuredContent !== null ? (string) json_encode($result->structuredContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $result->text();

        return $result->isError ? "It failed: {$text}" : $text;
    }

    public static function client(Connection $server): Client
    {
        $client = Client::web((string) $server->setting('url'))->withTimeout(30);

        if (filled($server->secret('token'))) {
            $client->withToken((string) $server->secret('token'));
        }

        return $client->connect();
    }
}
