<?php

namespace App\Support\Workflow;

use App\Models\Agent\Agent;
use App\Models\Chat\ChatRoom;
use App\Models\Connection;
use App\Models\Knowledge\KnowledgeBase;
use App\Models\Owner;
use App\Models\Project;
use App\Models\Table\Table;
use App\Models\Workflow\CodeLibrary;
use App\Models\Workflow\Workflow;
use App\Support\Connections\Services;
use App\Support\Workflow\Code\CodeSettings;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a workflow's steps point at in the app -- a connection, a table, a
 * chat room -- and whether each is its owner's, and of the right kind. A
 * personal workflow reaches its owner's own; a project's, the project's.
 * Steps look things up here too, so a step never reaches past its owner.
 *
 * @phpstan-import-type Shape from Definition
 * @phpstan-import-type Problem from Definition
 */
final class References
{
    /** "The chat's own" connection, or "any chat linked to <service>" for a room. */
    public const LINKED = '*linked';

    /** For a project's chat trigger: any of its chats with customers, whichever service. */
    public const CUSTOMERS = '*customers';

    /**
     * What the draft points at that isn't there, or isn't its owner's to use.
     *
     * @param  Shape  $definition
     * @return list<Problem>
     */
    public static function problems(Workflow $workflow, array $definition): array
    {
        $owner = $workflow->owner();
        $problems = [];

        foreach ($definition['nodes'] as $node) {
            foreach (NodeTypes::get($node['type'])['config'] ?? [] as $setting) {
                $value = $node['config'][$setting['name']] ?? null;

                if (! is_string($value) || $value === '') {
                    continue;
                }

                // Worked out from the run's linked chat, when it runs
                if (($setting['linked'] ?? false) && ($value === self::LINKED || $value === self::CUSTOMERS || str_starts_with($value, self::LINKED.':'))) {
                    continue;
                }

                $message = match ($setting['type']) {
                    'connection' => self::connection($owner, $value, $setting['services'] ?? []) === null
                        ? 'choose one of this workflow\'s own connections, of the right kind.'
                        : null,
                    'table' => self::table($owner, $value) === null
                        ? 'choose one of this workflow\'s own tables.'
                        : null,
                    'knowledge' => self::knowledge($owner, $value) === null
                        ? 'choose one of this workflow\'s own knowledge bases.'
                        : null,
                    'agent' => self::agent($owner, $value) === null
                        ? 'choose one of this workflow\'s own agents.'
                        : null,
                    'workflow' => $value === $workflow->ref_id
                        ? 'a workflow can\'t run itself this way.'
                        : (self::callable($owner, $value) === null
                            ? 'choose one of this workflow\'s own published workflows that starts "When run by another workflow".'
                            : null),
                    // A room worked out while it runs can only be checked then
                    'room' => ! str_contains($value, '{{') && self::room($owner, $value) === null
                        ? ($owner instanceof Project ? 'choose one of the project\'s chats.' : 'choose one of your own chats.')
                        : null,
                    default => null,
                };

                if ($message !== null) {
                    $problems[] = ['message' => "{$setting['label']}: {$message}", 'node' => $node['id']];
                }
            }

            if ($node['type'] === 'data.code') {
                array_push($problems, ...array_map(fn (string $message) => ['message' => $message, 'node' => $node['id']], self::codeProblems($node['config'])));
            }
        }

        return $problems;
    }

    /**
     * What stands in the way of a "Run code" step: a language the app's
     * admins have not allowed, or libraries they have not added (or have
     * switched off).
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function codeProblems(array $config): array
    {
        $language = (string) ($config['language'] ?? CodeLibrary::JAVASCRIPT);
        $problems = [];

        if ($language === CodeLibrary::PYTHON && ! CodeSettings::pythonOn()) {
            $problems[] = 'Language: Python is switched off by the app\'s admins.';
        }

        $names = array_values(array_filter((array) ($config['libraries'] ?? []), is_string(...)));
        $missing = array_diff($names, CodeLibrary::query()->usable($language)->whereIn('name', $names)->pluck('name')->all());

        if ($missing !== []) {
            $problems[] = 'Libraries: '.implode(', ', $missing).(count($missing) === 1 ? ' is' : ' are').' not there for '.($language === CodeLibrary::PYTHON ? 'Python' : 'JavaScript').': the app\'s admins have not added '.(count($missing) === 1 ? 'it' : 'them').', or switched '.(count($missing) === 1 ? 'it' : 'them').' off.';
        }

        return $problems;
    }

    /**
     * Everything in the way of publishing or running: the draft's own shape,
     * then what it points at.
     *
     * @return list<Problem>
     */
    public static function allProblems(Workflow $workflow): array
    {
        $draft = $workflow->draft();

        return [...Definition::problems($draft), ...self::problems($workflow, $draft)];
    }

    /**
     * One of the owner's connections, by ref_id, of one of the services
     * given ("ai" standing for every AI service).
     *
     * @param  list<string>  $services
     */
    public static function connection(Owner $owner, string $refId, array $services): ?Connection
    {
        $allowed = collect($services)->flatMap(fn (string $service) => $service === 'ai' ? Services::ai() : [$service])->all();

        return $owner->connections()
            ->where('ref_id', $refId)
            ->when($allowed !== [], fn ($query) => $query->whereIn('service', $allowed))
            ->first();
    }

    /**
     * One of the owner's workflows another may run: published, with a "When
     * run by another workflow" trigger in what is published.
     */
    public static function callable(Owner $owner, string $refId): ?Workflow
    {
        $workflow = $owner->workflows()->where('ref_id', $refId)->whereNotNull('published_version_id')->with('publishedVersion')->first();

        return $workflow !== null && self::callTrigger($workflow) !== null ? $workflow : null;
    }

    /**
     * The id of the published trigger a workflow is run by from another.
     */
    public static function callTrigger(Workflow $workflow): ?string
    {
        /** @var list<array{id: string, type: string}> $nodes */
        $nodes = $workflow->publishedVersion->definition['nodes'] ?? [];

        return collect($nodes)->firstWhere('type', 'trigger.call')['id'] ?? null;
    }

    public static function agent(Owner $owner, string $refId): ?Agent
    {
        return Agent::query()->of($owner)->where('ref_id', $refId)->first();
    }

    public static function knowledge(Owner $owner, string $refId): ?KnowledgeBase
    {
        return KnowledgeBase::query()->of($owner)->where('ref_id', $refId)->first();
    }

    public static function table(Owner $owner, string $refId): ?Table
    {
        return $owner->tables()->where('ref_id', $refId)->first();
    }

    /**
     * A room the owner's workflows may use: one's own chats for a user, the
     * project's group chats and its chats with customers for a project --
     * never a direct one between two people.
     */
    public static function room(Owner $owner, string $refId): ?ChatRoom
    {
        return self::rooms($owner)->where('ref_id', $refId)->first();
    }

    /**
     * @return Builder<ChatRoom>
     */
    public static function rooms(Owner $owner): Builder
    {
        return $owner instanceof Project
            ? ChatRoom::whereIn('kind', [ChatRoom::GROUP, ChatRoom::CUSTOMER])->where('project_id', $owner->getKey())
            : ChatRoom::where('kind', ChatRoom::PERSONAL)->where('user_id', $owner->getKey());
    }

    /**
     * The connection a step names -- or, for "the chat's own", the one the
     * chat that started the run is linked through -- of the services given.
     *
     * @param  list<string>  $services
     * @param  array<string, mixed>|null  $trigger  what started the run
     */
    public static function connectionFor(Owner $owner, string $refId, array $services, ?array $trigger): ?Connection
    {
        if ($refId === self::LINKED) {
            $refId = (string) ($trigger['room']['link']['connection'] ?? '');
        }

        return $refId === '' ? null : self::connection($owner, $refId, $services);
    }
}
