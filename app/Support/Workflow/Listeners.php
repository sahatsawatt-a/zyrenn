<?php

namespace App\Support\Workflow;

use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowListener;
use App\Support\Workflow\Inbound\Hooks;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What switched-on workflows wait for. Each trigger of a workflow's
 * published version that can fire by itself is listed here, under what it
 * listens to; the draft never is. Rebuilt as a whole for a workflow whenever
 * it is published or switched on or off, so the list is never older than
 * what is published.
 *
 * @phpstan-import-type Shape from Definition
 */
final class Listeners
{
    /** Trigger types that fire by themselves, and what they are listed as. */
    public const KINDS = [
        'trigger.chat' => 'chat_room',
        'trigger.schedule' => 'schedule',
        'trigger.webhook' => 'webhook',
        'trigger.telegram' => 'telegram',
        'trigger.line' => 'line',
    ];

    public static function rebuild(Workflow $workflow): void
    {
        DB::transaction(function () use ($workflow) {
            WorkflowListener::where('workflow_id', $workflow->id)->delete();

            $version = $workflow->publishedVersion;

            if (! $workflow->enabled || $version === null) {
                return;
            }

            /** @var Shape $definition */
            $definition = $version->definition;

            foreach ($definition['nodes'] as $node) {
                $kind = self::KINDS[$node['type']] ?? null;

                if ($kind === null) {
                    continue;
                }

                WorkflowListener::create([
                    'workflow_id' => $workflow->id,
                    'workflow_version_id' => $version->id,
                    'node_id' => $node['id'],
                    'kind' => $kind,
                    'key' => match ($kind) {
                        'chat_room' => (string) ($node['config']['room'] ?? ''),
                        'webhook' => (string) ($node['config']['token'] ?? ''),
                        'telegram', 'line' => (string) ($node['config']['connection'] ?? ''),
                        default => '',
                    },
                    'settings' => $node['config'],
                ]);
            }
        });

        // A bot's or channel's messages are sent here, once something listens
        DB::afterCommit(fn () => Hooks::register($workflow));
    }

    /**
     * Who listens to this, with their workflow and the version they run.
     *
     * @return Collection<int, WorkflowListener>
     */
    public static function for(string $kind, string $key = ''): Collection
    {
        return WorkflowListener::with(['workflow', 'version'])
            ->where('kind', $kind)
            ->where('key', $key)
            ->get()
            ->filter(fn (WorkflowListener $listener) => $listener->workflow->enabled)
            ->values();
    }

    /**
     * Everyone listening for one kind of thing, whatever to.
     *
     * @return Collection<int, WorkflowListener>
     */
    public static function forKind(string $kind): Collection
    {
        return WorkflowListener::with(['workflow', 'version'])
            ->where('kind', $kind)
            ->get()
            ->filter(fn (WorkflowListener $listener) => $listener->workflow->enabled)
            ->values();
    }
}
