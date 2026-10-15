<?php

namespace App\Support\Workflow\Steps;

use Illuminate\Support\Carbon;
use stdClass;

/**
 * PromptX's calls, each a kind of step of its own (ServiceKinds names
 * them; this runs them): making a flow, reading one, importing a
 * definition into one and publishing it, and reading its runs.
 *
 * Making a flow leaves something new, so it is recorded and never simply
 * made twice. An import puts the same draft each time, a publish publishes
 * the draft there is, and the rest only ask: those can be made again.
 */
final class PromptxCall implements Step
{
    /** The most of a step's input or output a run gives out, in bytes of JSON. */
    private const MAX_PART = 4000;

    /** The most of a run's steps given out: one that failed always is. */
    private const MAX_STEPS = 100;

    /**
     * Lists in a router's settings: the only settings an empty one of is a
     * list rather than an empty object, where the draft does not say.
     */
    private const LISTS = ['branches', 'conditions'];

    public function run(StepContext $step): StepResult
    {
        $account = PromptxApi::account($step);
        $in = PromptxApi::project($step, $account);

        return new StepResult(match ($step->node['type']) {
            'promptx.flow_create' => self::create($step, $in),
            'promptx.flow' => self::read($step, $in),
            'promptx.flow_import' => self::import($step, $in),
            'promptx.flow_publish' => self::publish($step, $in),
            'promptx.runs' => self::runs($step, $in),
            'promptx.run' => self::detail($step, $in),

            default => throw new StepFailed("This app doesn't know the PromptX step \"{$step->node['type']}\"."),
        });
    }

    /**
     * @param  array{token: string, project: string}  $in
     * @return array<string, mixed>
     */
    private static function create(StepContext $step, array $in): array
    {
        $name = trim($step->text('name')) ?: throw new StepFailed('The flow has no name.');

        $made = PromptxApi::send($step, $in['project'], fn () => PromptxApi::as($in['token'])
            ->post(PromptxApi::FLOWS.'/flows', ['displayName' => $name, 'projectId' => $in['project']]));

        return self::flow((array) $made->json());
    }

    /**
     * @param  array{token: string, project: string}  $in
     * @return array<string, mixed>
     */
    private static function read(StepContext $step, array $in): array
    {
        $flow = (array) PromptxApi::call(fn () => PromptxApi::as($in['token'])->get(PromptxApi::FLOWS.'/flows/'.self::id($step, 'flow', 'flow')))->json();
        $version = (array) ($flow['version'] ?? []);

        return [
            ...self::flow($flow),
            'steps' => self::outline($version['trigger'] ?? null),
            'definition' => [
                'displayName' => $version['displayName'] ?? null,
                'trigger' => $version['trigger'] ?? null,
                'valid' => $version['valid'] ?? null,
                'schemaVersion' => $version['schemaVersion'] ?? null,
            ],
        ];
    }

    /**
     * Put a definition in a flow's draft, in place of what is there.
     *
     * A definition comes through a workflow as data, where an empty object
     * and an empty list are one thing, and PromptX takes only the one it
     * means. Each step's settings are given back the kinds of empty the
     * same step had in the draft; a step new to the flow has empty objects,
     * but for a router's lists. One typed as JSON goes as it is.
     *
     * @param  array{token: string, project: string}  $in
     * @return array<string, mixed>
     */
    private static function import(StepContext $step, array $in): array
    {
        $id = self::id($step, 'flow', 'flow');
        $given = $step->template('definition');
        // JSON as typed says which empty it means
        $typed = is_string($given);
        $given = $typed ? json_decode($given) : json_decode((string) json_encode($given));
        $given = $given instanceof stdClass && isset($given->definition) ? $given->definition : $given;

        if (! $given instanceof stdClass || ! isset($given->trigger) || ! $given->trigger instanceof stdClass) {
            throw new StepFailed('That is not a flow\'s definition: one with a trigger, as "PromptX: read a flow" gives it out.');
        }

        $as = PromptxApi::as($in['token']);
        $draft = json_decode(PromptxApi::call(fn () => $as->get(PromptxApi::FLOWS.'/flows/'.$id))->body());
        $was = self::byName($draft->version->trigger ?? null);
        $name = trim($step->text('name'));

        $imported = PromptxApi::call(fn () => $as->withBody((string) json_encode(['type' => 'IMPORT_FLOW', 'request' => [
            'displayName' => $name !== '' ? $name : ($given->displayName ?? $draft->version->displayName ?? 'Untitled'),
            'trigger' => $typed ? $given->trigger : self::restored($given->trigger, $was),
            'valid' => (bool) ($given->valid ?? true),
            'schemaVersion' => $given->schemaVersion ?? $draft->version->schemaVersion ?? null,
        ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->post(PromptxApi::FLOWS.'/flows/'.$id));

        return self::flow((array) $imported->json());
    }

    /**
     * @param  array{token: string, project: string}  $in
     * @return array<string, mixed>
     */
    private static function publish(StepContext $step, array $in): array
    {
        $published = PromptxApi::call(fn () => PromptxApi::as($in['token'])->withBody('{"type":"LOCK_AND_PUBLISH","request":{}}')
            ->post(PromptxApi::FLOWS.'/flows/'.self::id($step, 'flow', 'flow')));

        return self::flow((array) $published->json());
    }

    /**
     * @param  array{token: string, project: string}  $in
     * @return array{runs: list<array<string, mixed>>, count: int}
     */
    private static function runs(StepContext $step, array $in): array
    {
        $flow = trim($step->text('flow'));
        $status = (string) $step->setting('status');
        $runs = PromptxApi::call(fn () => PromptxApi::as($in['token'])->get(PromptxApi::FLOWS.'/flow-runs', [
            'projectId' => $in['project'],
            'limit' => max(1, min(100, (int) ($step->setting('count') ?: 10))),
            ...$flow === '' ? [] : ['flowId' => $flow],
            ...$status === '' || $status === 'any' ? [] : ['status' => $status],
        ]))->json('data');
        $runs = array_map(fn ($run) => self::summary((array) $run), is_array($runs) ? array_values($runs) : []);

        return ['runs' => $runs, 'count' => count($runs)];
    }

    /**
     * One run: how it went, the step it failed at and why, and each step's
     * own -- those in a loop each time round -- with its input and output
     * cut short where long.
     *
     * @param  array{token: string, project: string}  $in
     * @return array<string, mixed>
     */
    private static function detail(StepContext $step, array $in): array
    {
        $run = (array) PromptxApi::call(fn () => PromptxApi::as($in['token'])->get(PromptxApi::FLOWS.'/flow-runs/'.self::id($step, 'run', 'run')))->json();
        $steps = [];
        self::steps(is_array($run['steps'] ?? null) ? $run['steps'] : [], $steps);
        $failed = array_values(array_filter($steps, fn (array $one) => $one['status'] === 'FAILED'));
        $shown = count($steps) > self::MAX_STEPS
            ? array_values(array_filter($steps, fn (array $one, int $at) => $at < self::MAX_STEPS || $one['status'] === 'FAILED', ARRAY_FILTER_USE_BOTH))
            : $steps;

        return [
            ...self::summary($run),
            'error' => $failed[0]['error'] ?? null,
            // PromptX keeps a run's steps for some weeks; an older run has only how it went
            'kept' => ($run['logsFileId'] ?? null) !== null,
            'steps' => $shown,
            'more' => count($steps) - count($shown),
        ];
    }

    /**
     * Each step of a run in the order it ran, a loop's each time round after it.
     *
     * @param  array<array-key, mixed>  $ran
     * @param  list<array<string, mixed>>  $into
     */
    private static function steps(array $ran, array &$into, ?string $loop = null, ?int $round = null): void
    {
        foreach ($ran as $name => $one) {
            if (! is_array($one)) {
                continue;
            }

            $into[] = [
                'name' => (string) $name,
                'type' => $one['type'] ?? null,
                'status' => $one['status'] ?? null,
                'ms' => is_numeric($one['duration'] ?? null) ? (int) round((float) $one['duration']) : null,
                'error' => $one['errorMessage'] ?? null,
                ...$loop === null ? [] : ['loop' => $loop, 'round' => $round],
                'input' => self::cut($one['input'] ?? null),
                'output' => self::cut(($one['type'] ?? null) === 'LOOP_ON_ITEMS' && is_array($one['output'] ?? null) ? array_diff_key($one['output'], ['iterations' => true]) : ($one['output'] ?? null)),
            ];

            if (($one['type'] ?? null) === 'LOOP_ON_ITEMS') {
                foreach ((array) ($one['output']['iterations'] ?? []) as $at => $iteration) {
                    self::steps(is_array($iteration) ? $iteration : [], $into, (string) $name, (int) $at + 1);
                }
            }
        }
    }

    /**
     * A value as it is, or its JSON cut short where it is long.
     */
    private static function cut(mixed $value): mixed
    {
        $json = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return strlen($json) > self::MAX_PART ? mb_strcut($json, 0, self::MAX_PART).'…' : $value;
    }

    /**
     * A flow as the steps give it out.
     *
     * @param  array<string, mixed>  $flow
     * @return array<string, mixed>
     */
    private static function flow(array $flow): array
    {
        $version = (array) ($flow['version'] ?? []);

        return [
            'id' => $flow['id'] ?? null,
            'name' => $version['displayName'] ?? null,
            'status' => $flow['status'] ?? null,
            'project' => $flow['projectId'] ?? null,
            'version' => $version['id'] ?? null,
            'valid' => $version['valid'] ?? null,
            // Published once at least; a draft changed since is published again to run
            'published' => ($flow['publishedVersionId'] ?? null) !== null,
            'draft' => ($version['state'] ?? null) === 'DRAFT',
            'updated' => $flow['updated'] ?? null,
        ];
    }

    /**
     * A run as the steps give it out.
     *
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private static function summary(array $run): array
    {
        $failed = $run['failedStep'] ?? null;
        $at = fn (mixed $time) => is_string($time) && strtotime($time) !== false ? Carbon::parse($time) : null;
        [$started, $finished] = [$at($run['startTime'] ?? null), $at($run['finishTime'] ?? null)];

        return [
            'id' => $run['id'] ?? null,
            'flow' => $run['flowId'] ?? null,
            'flow_name' => $run['flowVersion']['displayName'] ?? null,
            'version' => $run['flowVersionId'] ?? null,
            'status' => $run['status'] ?? null,
            'started' => $run['startTime'] ?? null,
            'finished' => $run['finishTime'] ?? null,
            'seconds' => $started && $finished ? round($started->diffInMilliseconds($finished) / 1000, 1) : null,
            'failed_step' => is_array($failed) && isset($failed['name']) ? ['name' => $failed['name'], 'label' => $failed['displayName'] ?? null] : null,
        ];
    }

    /**
     * A flow's steps in order, from its trigger: each with its name, what it
     * is called, its kind, and the piece and action it runs.
     *
     * @return list<array<string, mixed>>
     */
    private static function outline(mixed $node): array
    {
        $steps = [];

        foreach (self::nodes(json_decode((string) json_encode($node))) as $one) {
            $steps[] = [
                'name' => $one->name ?? null,
                'label' => $one->displayName ?? null,
                'type' => $one->type ?? null,
                'piece' => $one->settings->pieceName ?? null,
                'action' => $one->settings->actionName ?? $one->settings->triggerName ?? null,
                'valid' => $one->valid ?? null,
            ];
        }

        return $steps;
    }

    /**
     * Every step of a definition: the trigger, each after it, those in a
     * loop or a branch, and those in each of a router's ways.
     *
     * @return list<stdClass>
     */
    private static function nodes(mixed $node): array
    {
        if (! $node instanceof stdClass) {
            return [];
        }

        $inside = [];

        foreach (['firstLoopAction', 'onSuccessAction', 'onFailureAction'] as $branch) {
            $inside = [...$inside, ...self::nodes($node->{$branch} ?? null)];
        }

        foreach (is_array($node->children ?? null) ? $node->children : [] as $child) {
            $inside = [...$inside, ...self::nodes($child)];
        }

        return [$node, ...$inside, ...self::nodes($node->nextAction ?? null)];
    }

    /**
     * The steps of a definition by name.
     *
     * @return array<string, stdClass>
     */
    private static function byName(mixed $trigger): array
    {
        $named = [];

        foreach (self::nodes($trigger) as $one) {
            $named[(string) ($one->name ?? '')] = $one;
        }

        return $named;
    }

    /**
     * The definition, its steps' settings with the kinds of empty PromptX takes.
     *
     * @param  array<string, stdClass>  $was  the draft's steps, by name
     */
    private static function restored(stdClass $trigger, array $was): stdClass
    {
        foreach (self::nodes($trigger) as $one) {
            if (isset($one->settings)) {
                $one->settings = self::empties($one->settings, $was[(string) ($one->name ?? '')]->settings ?? null);
            }
        }

        return $trigger;
    }

    /**
     * A value with each empty list in it made the empty the draft had in
     * the same place, or an empty object where the draft has nothing there.
     */
    private static function empties(mixed $value, mixed $draft, ?string $key = null): mixed
    {
        if ($value === []) {
            return is_array($draft) || ($draft === null && in_array($key, self::LISTS, true)) ? [] : new stdClass;
        }

        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $name => $inner) {
                $value->{$name} = self::empties($inner, $draft instanceof stdClass ? ($draft->{$name} ?? null) : null, (string) $name);
            }
        } elseif (is_array($value)) {
            foreach ($value as $at => $inner) {
                $value[$at] = self::empties($inner, is_array($draft) ? ($draft[$at] ?? null) : null, $key);
            }
        }

        return $value;
    }

    /**
     * An id a setting names.
     *
     * @throws StepFailed
     */
    private static function id(StepContext $step, string $setting, string $what): string
    {
        $id = trim($step->text($setting));

        return preg_match('/^[A-Za-z0-9_-]+$/', $id) === 1 ? $id : throw new StepFailed($id === '' ? "There is no {$what}: its id." : "\"{$id}\" is not a PromptX {$what} id.");
    }
}
