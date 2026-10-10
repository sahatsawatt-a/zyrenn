<?php

namespace App\Mcp\Tools;

use App\Models\Owner;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowFolder;
use App\Models\Workflow\WorkflowNodeRun;
use App\Models\Workflow\WorkflowRun;
use App\Support\Workflow\Definition;
use App\Support\Workflow\DefinitionInvalid;
use App\Support\Workflow\DraftEdits;
use App\Support\Workflow\References;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;

/**
 * Base for the workflow tools shared by both MCP servers: finding, listing
 * and folders are FiledTool's; workflows add their runs -- how each went,
 * step by step.
 *
 * @extends FiledTool<WorkflowFolder, Workflow>
 */
abstract class WorkflowTool extends FiledTool
{
    /** The most of one step's output shown in a run, unless that step is asked for. */
    protected const OUTPUT_BYTES = 2000;

    protected function noun(): string
    {
        return 'workflow';
    }

    /**
     * @return HasMany<WorkflowFolder, covariant Model&Owner>
     */
    protected function folders(Owner $owner): HasMany
    {
        return $owner->workflowFolders();
    }

    /**
     * @return HasMany<Workflow, covariant Model&Owner>
     */
    protected function things(Owner $owner): HasMany
    {
        return $owner->workflows();
    }

    protected function searchIn(): array
    {
        return ['title' => 'title'];
    }

    /**
     * @param  Workflow  $thing
     */
    protected function details(Model $thing): array
    {
        return [
            'steps' => count($thing->definition['nodes'] ?? []),
            'published' => $thing->publishedVersion?->number,
            'enabled' => (bool) $thing->enabled,
        ];
    }

    /**
     * One of the owner's runs, by ref_id.
     */
    protected function findRun(Owner $owner, string $refId): ?WorkflowRun
    {
        return WorkflowRun::where('ref_id', $refId)
            ->whereIn('workflow_id', $owner->workflows()->select('id'))
            ->first();
    }

    /**
     * A run as a client reads it: where it and each step stand, what each
     * gave out (cut short unless $full names that step), and its log.
     *
     * @return array<string, mixed>
     */
    protected function report(WorkflowRun $run, ?string $full = null): array
    {
        return [
            'ref_id' => $run->ref_id,
            'workflow' => $run->workflow->ref_id,
            'status' => $run->status->value,
            'trigger' => $run->trigger,
            'reason' => $run->reason,
            'error' => $run->error,
            'version' => $run->version->kind === 'test' ? 'draft' : $run->version->number,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'steps' => $run->steps()->orderBy('id')->get()->map(fn (WorkflowNodeRun $step) => array_filter([
                'key' => $step->node_key,
                'type' => $step->node_type,
                'status' => $step->status->value,
                'way' => $step->handle,
                'waiting_for' => $step->waiting_for,
                'output' => $step->node_key === $full ? $step->output : self::cut($step->output),
                'error' => $step->error['message'] ?? null,
            ], fn ($value) => $value !== null))->values()->all(),
            'log' => $run->logs()->orderBy('id')->limit(50)->pluck('message')->all(),
        ];
    }

    /**
     * Output short enough to read at a glance; long, it says how to get it all.
     */
    private static function cut(mixed $output): mixed
    {
        $json = json_encode($output);

        return $json !== false && strlen($json) > self::OUTPUT_BYTES
            ? ['cut_short' => mb_strcut($json, 0, self::OUTPUT_BYTES).'…', 'note' => 'Pass "step" with this step\'s key for all of it.']
            : $output;
    }

    /**
     * A workflow as a client reads it: its steps by key with their settings,
     * how they are joined, what stands in the way, its latest runs, and the
     * revision a change may name so it is never made over someone else's.
     *
     * @return array<string, mixed>
     */
    protected function described(Workflow $workflow): array
    {
        $draft = $workflow->draft();
        $keys = array_column($draft['nodes'], 'key', 'id');

        return $this->answer($workflow, [
            'revision' => $workflow->revision,
            'steps' => array_map(fn (array $node) => [
                'key' => $node['key'],
                'type' => $node['type'],
                'label' => $node['label'],
                'settings' => $node['config'],
                'on_error' => $node['onError'],
            ], $draft['nodes']),
            'joins' => array_map(fn (array $edge) => [
                'from' => $keys[$edge['source']] ?? $edge['source'],
                'way' => $edge['sourceHandle'],
                'to' => $keys[$edge['target']] ?? $edge['target'],
            ], $draft['edges']),
            'problems' => array_map(
                fn (array $problem) => isset($problem['node']) && isset($keys[$problem['node']])
                    ? "{$keys[$problem['node']]}: {$problem['message']}"
                    : $problem['message'],
                References::allProblems($workflow),
            ),
            'unpublished_changes' => $workflow->published_version_id !== null && $workflow->hasUnpublishedChanges(),
            'runs' => $workflow->runs()->latest('id')->limit(5)->get()->map(fn (WorkflowRun $run) => [
                'ref_id' => $run->ref_id,
                'status' => $run->status->value,
                'trigger' => $run->trigger,
                'error' => $run->error,
                'at' => $run->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * The changes create-workflow and update-workflow take, by step key.
     *
     * @return array<string, Type>
     */
    protected function editArguments(JsonSchema $schema): array
    {
        $settings = $schema->object()->description('Settings by name, as list-workflow-steps describes the step\'s kind. Templates write {{ formulas }}: {{ trigger.text }}, {{ node("key").field }}; a formula setting is written without them. Connections, rooms, tables and workflows are given by ref_id (list-workflow-steps "choices").');

        return [
            'add' => $schema->array()->max(100)->items($schema->object([
                'type' => $schema->string()->description('The kind of step, e.g. "trigger.telegram", "ai.chat" (list-workflow-steps).')->required(),
                'key' => $schema->string()->max(40)->description('What formulas call it, node("key"): lowercase letters, digits and _. Made from its name when left out.'),
                'label' => $schema->string()->max(80)->description('Its name on the canvas.'),
                'settings' => $settings,
                'after' => $schema->string()->max(40)->description('The key of the step it follows: it is placed beside it and joined from it.'),
                'way' => $schema->string()->max(40)->description('Which way out of "after" leads to it, for a step with several (If: true/false; Switch: a way\'s name or otherwise; Approval: approved/rejected).'),
                'on_error' => $schema->string()->enum(['stop', 'continue'])->description('When it fails: stop the run (the default), or carry on, passing what went wrong.'),
            ]))->description('Steps to add, set up with their kind\'s defaults and these settings.'),
            'change' => $schema->array()->max(100)->items($schema->object([
                'key' => $schema->string()->max(40)->required(),
                'new_key' => $schema->string()->max(40)->description('A new key; node("old") in other steps\' settings follows it.'),
                'label' => $schema->string()->max(80),
                'settings' => $settings->description('Only the settings to change; null takes one away.'),
                'on_error' => $schema->string()->enum(['stop', 'continue']),
            ]))->description('Steps to change, by key.'),
            'remove' => $schema->array()->max(100)->items($schema->string()->max(40))->description('Keys of steps to take away, with their joins.'),
            'join' => $schema->array()->max(200)->items($schema->object([
                'from' => $schema->string()->max(40)->required(),
                'way' => $schema->string()->max(40)->description('Which way out, for a step with several.'),
                'to' => $schema->string()->max(40)->required(),
            ]))->description('Joins to make: "to" runs after "from" goes that way.'),
            'unjoin' => $schema->array()->max(200)->items($schema->object([
                'from' => $schema->string()->max(40)->required(),
                'way' => $schema->string()->max(40),
                'to' => $schema->string()->max(40)->required(),
            ]))->description('Joins to undo.'),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    protected function editRules(): array
    {
        return [
            'add' => ['nullable', 'array', 'max:100'],
            'add.*' => ['array'],
            'change' => ['nullable', 'array', 'max:100'],
            'change.*' => ['array'],
            'remove' => ['nullable', 'array', 'max:100'],
            'remove.*' => ['string', 'max:40'],
            'join' => ['nullable', 'array', 'max:200'],
            'join.*' => ['array'],
            'unjoin' => ['nullable', 'array', 'max:200'],
            'unjoin.*' => ['array'],
        ];
    }

    /**
     * The draft with the changes asked for, checked as any save is.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException saying what can't be done
     */
    protected function editedDraft(Workflow $workflow, array $validated): array
    {
        /** @var array{remove?: list<string>, add?: list<array<string, mixed>>, change?: list<array<string, mixed>>, unjoin?: list<array<string, mixed>>, join?: list<array<string, mixed>>} $edits */
        $edits = array_intersect_key($validated, array_flip(['remove', 'add', 'change', 'unjoin', 'join']));

        try {
            return Definition::normalize(DraftEdits::apply($workflow->draft(), $edits));
        } catch (DefinitionInvalid $e) {
            throw new InvalidArgumentException(implode(' ', $e->errors));
        }
    }
}
