<?php

namespace App\Support\Workflow\Steps;

use Illuminate\Http\Client\Response;
use stdClass;

/**
 * PromptX's calls that change the steps inside a flow, one at a time, each
 * a kind of step of its own: changing a step's settings, adding one,
 * deleting some, moving one, and setting what starts the flow. Each lands
 * on the flow's draft; what runs stays the published version until it is
 * published again.
 *
 * The draft is read as PromptX sends it, empty objects and empty lists
 * each kept as the one it is, and what a step is given goes as written:
 * PromptX keeps each value just so, so a newline is a newline and a
 * {{step_1.body}} is PromptX's own. Each change is read back, and what
 * PromptX kept is what the step gives out.
 */
final class PromptxEdit implements Step
{
    /**
     * What starts a flow, by the words a step's settings use: the piece,
     * its trigger and its name -- at the version a new one is pinned to,
     * unless the flow's trigger is already that piece.
     */
    private const TRIGGERS = [
        'webhook' => ['@activepieces/piece-webhook', 'catch_webhook', 'Catch Webhook', '0.1.32'],
        'schedule' => ['@activepieces/piece-schedule', 'cron_expression', 'Cron Expression', '0.1.17'],
    ];

    /** The links from a step to those after and inside it: never part of the step itself. */
    private const LINKS = ['nextAction', 'firstLoopAction', 'onSuccessAction', 'onFailureAction', 'children'];

    public function run(StepContext $step): StepResult
    {
        $account = PromptxApi::account($step);
        $in = PromptxApi::project($step, $account);

        return new StepResult(match ($step->node['type']) {
            'promptx.step_update' => self::update($step, $in),
            'promptx.step_add' => self::add($step, $in),
            'promptx.step_delete' => self::remove($step, $in),
            'promptx.step_move' => self::move($step, $in),
            'promptx.trigger_update' => self::trigger($step, $in),

            default => throw new StepFailed("This app doesn't know the PromptX step \"{$step->node['type']}\"."),
        });
    }

    /**
     * Changes one step: the input values given, each in place of the one
     * of its name, anything else in its settings, and what it is called.
     *
     * @param  array{token: string, project: string}  $in
     * @return array<string, mixed>
     */
    private static function update(StepContext $step, array $in): array
    {
        $id = PromptxCall::id($step, 'flow', 'flow');
        $name = self::name($step, 'step');

        if ($name === 'trigger') {
            throw new StepFailed('The trigger is changed by "PromptX: set the trigger".');
        }

        $node = self::find(self::draft($in, $id), $name);
        $action = self::action($node);

        foreach (get_object_vars(self::object($step, 'input')) as $field => $value) {
            $action->settings->input ??= new stdClass;
            $action->settings->input->{$field} = $value;
        }

        foreach (get_object_vars(self::object($step, 'settings')) as $field => $value) {
            $action->settings->{$field} = $value;
        }

        $label = trim($step->text('label'));

        if ($label !== '') {
            $action->displayName = $label;
        }

        self::change($in, $id, 'UPDATE_ACTION', $action);

        return ['flow' => $id, 'step' => self::described(self::find(self::draft($in, $id), $name))];
    }

    /**
     * Adds a step after another, or inside a loop or a branch: written as
     * PromptX writes one (a type and its settings), or as its piece, action
     * and input alone. A step left nameless is given the next free name.
     *
     * @param  array{token: string, project: string}  $in
     * @return array<string, mixed>
     */
    private static function add(StepContext $step, array $in): array
    {
        $id = PromptxCall::id($step, 'flow', 'flow');
        $after = self::name($step, 'after', 'trigger');
        $where = (string) ($step->setting('where') ?: 'AFTER');
        $named = self::byName(self::draft($in, $id));

        if (! isset($named[$after])) {
            throw new StepFailed("The flow has no step \"{$after}\" to put it after: it has ".implode(', ', array_keys($named)).'.');
        }

        $given = self::object($step, 'step');

        // Its piece, action and input alone: the settings PromptX keeps them in
        if (! isset($given->settings) && isset($given->pieceName)) {
            $given->settings = new stdClass;

            foreach (['pieceName', 'pieceVersion', 'actionName', 'input'] as $part) {
                if (isset($given->{$part})) {
                    $given->settings->{$part} = $given->{$part};
                    unset($given->{$part});
                }
            }
        }

        $name = (string) ($given->name ?? '');

        if ($name === '' || isset($named[$name])) {
            for ($n = 1; isset($named["step_{$n}"]); $n++);
            $name = "step_{$n}";
        }

        $given->name = $name;
        $given->displayName ??= 'Step';
        $given->type ??= 'PIECE';
        $given->valid ??= true;
        $given->settings ??= new stdClass;

        PromptxApi::send($step, $id, fn () => self::post($in, $id, 'ADD_ACTION', (object) [
            'parentStep' => $after,
            'stepLocationRelativeToParent' => $where,
            'action' => self::action($given),
        ]));

        return ['flow' => $id, 'step' => self::described(self::find(self::draft($in, $id), $name))];
    }

    /**
     * Deletes steps by name. One the flow does not have is said so, rather
     * than failing a run tried again.
     *
     * @param  array{token: string, project: string}  $in
     * @return array{flow: string, deleted: list<string>, missing: list<string>}
     */
    private static function remove(StepContext $step, array $in): array
    {
        $id = PromptxCall::id($step, 'flow', 'flow');
        $asked = array_values(array_unique(array_filter(preg_split('/[\s,]+/', $step->text('steps')) ?: [])));

        if ($asked === [] || in_array('trigger', $asked, true)) {
            throw new StepFailed($asked === [] ? 'Name the steps to delete: step_2, step_3.' : 'A flow always has its trigger: it cannot be deleted.');
        }

        $named = self::byName(self::draft($in, $id));
        $there = array_values(array_filter($asked, fn (string $name) => isset($named[$name])));

        if ($there !== []) {
            self::change($in, $id, 'DELETE_ACTION', (object) ['names' => $there]);
        }

        return ['flow' => $id, 'deleted' => $there, 'missing' => array_values(array_diff($asked, $there))];
    }

    /**
     * Moves a step after another, or into or out of a loop or a branch.
     *
     * @param  array{token: string, project: string}  $in
     * @return array{flow: string, steps: list<array<string, mixed>>}
     */
    private static function move(StepContext $step, array $in): array
    {
        $id = PromptxCall::id($step, 'flow', 'flow');
        $name = self::name($step, 'step');
        $after = self::name($step, 'after', 'trigger');
        $named = self::byName(self::draft($in, $id));

        foreach ([$name, $after] as $one) {
            if (! isset($named[$one])) {
                throw new StepFailed("The flow has no step \"{$one}\": it has ".implode(', ', array_keys($named)).'.');
            }
        }

        self::change($in, $id, 'MOVE_ACTION', (object) [
            'name' => $name,
            'newParentStep' => $after,
            'stepLocationRelativeToNewParent' => (string) ($step->setting('where') ?: 'AFTER'),
        ]);

        return ['flow' => $id, 'steps' => PromptxCall::outline(self::draft($in, $id)->version->trigger ?? null)];
    }

    /**
     * Sets what starts the flow -- a call to its webhook, a timetable, or a
     * trigger written out -- keeping every step after it.
     *
     * The whole draft goes back with the new trigger in it, the steps
     * hung on it as they were: a trigger set on its own leaves the steps
     * behind it out.
     *
     * @param  array{token: string, project: string}  $in
     * @return array<string, mixed>
     */
    private static function trigger(StepContext $step, array $in): array
    {
        $id = PromptxCall::id($step, 'flow', 'flow');
        $draft = self::draft($in, $id);
        $version = $draft->version ?? new stdClass;
        $was = $version->trigger ?? null;
        $kind = (string) ($step->setting('kind') ?: 'webhook');

        if ($kind === 'custom') {
            $given = self::object($step, 'trigger');
            $piece = (string) ($given->pieceName ?? '') ?: throw new StepFailed('The trigger has no pieceName.');
            [$name, $label, $pin] = [(string) ($given->triggerName ?? ''), (string) ($given->displayName ?? 'Trigger'), (string) ($given->pieceVersion ?? '')];
            $input = $given->input ?? new stdClass;
        } else {
            [$piece, $name, $label, $pin] = self::TRIGGERS[$kind] ?? throw new StepFailed('Say what starts the flow: a webhook, a timetable, or a trigger written out.');
            $input = $kind === 'webhook'
                // Without these, a call to the webhook is answered and starts nothing
                ? (object) ['authType' => 'none', 'authFields' => new stdClass]
                : (object) array_filter(['cronExpression' => trim($step->text('cron')), 'timezone' => trim((string) $step->setting('timezone'))]);
        }

        if (! $input instanceof stdClass) {
            throw new StepFailed('The trigger\'s input should be a JSON object.');
        }

        $trigger = (object) [
            'name' => 'trigger',
            'displayName' => $label,
            'type' => 'PIECE_TRIGGER',
            'valid' => $kind !== 'schedule' || isset($input->cronExpression),
            'settings' => (object) [
                'pieceName' => $piece,
                // Kept, where the trigger is already this piece
                'pieceVersion' => ($was->settings->pieceName ?? null) === $piece ? ($was->settings->pieceVersion ?? $pin) : $pin,
                'triggerName' => $name,
                'input' => $input,
                'inputUiInfo' => new stdClass,
                'propertySettings' => new stdClass,
            ],
        ];

        if (isset($was->nextAction)) {
            $trigger->nextAction = $was->nextAction;
        }

        self::change($in, $id, 'IMPORT_FLOW', (object) [
            'displayName' => $version->displayName ?? 'Untitled',
            'trigger' => $trigger,
            'valid' => (bool) ($version->valid ?? true),
            'schemaVersion' => $version->schemaVersion ?? null,
        ]);

        $now = self::draft($in, $id)->version->trigger ?? null;

        return [
            'flow' => $id,
            'trigger' => self::described($now instanceof stdClass ? $now : $trigger),
            // A flow with steps after its trigger has them still
            'kept_steps' => isset($was->nextAction) ? isset($now->nextAction) : null,
            ...$piece === self::TRIGGERS['webhook'][0] ? [
                'webhook' => PromptxApi::FLOWS.'/webhooks/'.$id,
                'webhook_sync' => PromptxApi::FLOWS.'/webhooks/'.$id.'/sync',
            ] : [],
        ];
    }

    /**
     * A flow as PromptX sends it, each empty as the one it is.
     *
     * @param  array{token: string, project: string}  $in
     */
    private static function draft(array $in, string $id): stdClass
    {
        $draft = json_decode(PromptxApi::call(fn () => PromptxApi::as($in['token'])->get(PromptxApi::FLOWS.'/flows/'.$id))->body());

        return $draft instanceof stdClass ? $draft : throw new StepFailed('PromptX sent back something that is not a flow.');
    }

    /**
     * The draft's steps by name, its trigger first.
     *
     * @return array<string, stdClass>
     */
    private static function byName(stdClass $draft): array
    {
        $named = [];

        foreach (PromptxCall::nodes($draft->version->trigger ?? null) as $one) {
            $named[(string) ($one->name ?? '')] = $one;
        }

        return $named;
    }

    private static function find(stdClass $draft, string $name): stdClass
    {
        $named = self::byName($draft);

        return $named[$name] ?? throw new StepFailed("The flow has no step \"{$name}\": it has ".implode(', ', array_keys($named)).'.');
    }

    /**
     * A step as PromptX takes it to add or change one: itself without the
     * steps after and inside it, with the settings PromptX asks for that a
     * read leaves out, and without the sample a test of it kept.
     */
    private static function action(stdClass $node): stdClass
    {
        $action = json_decode((string) json_encode($node));

        foreach (self::LINKS as $link) {
            unset($action->{$link});
        }

        $action->settings ??= new stdClass;
        $action->settings->inputUiInfo = new stdClass;
        unset($action->settings->sampleData);

        if (in_array($action->type ?? 'PIECE', ['PIECE', 'CODE'], true)) {
            $action->settings->input ??= new stdClass;
            $action->settings->errorHandlingOptions ??= (object) [
                'continueOnFailure' => (object) ['value' => false],
                'retryOnFailure' => (object) ['value' => false],
            ];
        }

        if (($action->type ?? 'PIECE') === 'PIECE') {
            $action->settings->propertySettings ??= new stdClass;
        }

        return $action;
    }

    /**
     * A step as the steps give it out: what it is, and its input and
     * settings just as PromptX keeps them.
     *
     * @return array<string, mixed>
     */
    private static function described(stdClass $node): array
    {
        $settings = json_decode((string) json_encode($node->settings ?? new stdClass));
        unset($settings->sampleData, $settings->inputUiInfo);

        return [
            'name' => $node->name ?? null,
            'label' => $node->displayName ?? null,
            'type' => $node->type ?? null,
            'piece' => $node->settings->pieceName ?? null,
            'action' => $node->settings->actionName ?? $node->settings->triggerName ?? null,
            'valid' => $node->valid ?? null,
            'input' => json_decode((string) json_encode($node->settings->input ?? new stdClass), true),
            // As PromptX keeps them: each empty the one it is
            'settings_json' => (string) json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * A change to a flow's draft, made and made again if need be: each sets
     * the draft to what it says.
     *
     * @param  array{token: string, project: string}  $in
     */
    private static function change(array $in, string $id, string $type, stdClass $request): void
    {
        PromptxApi::call(fn () => self::post($in, $id, $type, $request));
    }

    /**
     * @param  array{token: string, project: string}  $in
     */
    private static function post(array $in, string $id, string $type, stdClass $request): Response
    {
        return PromptxApi::as($in['token'])
            ->withBody((string) json_encode(['type' => $type, 'request' => $request], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->post(PromptxApi::FLOWS.'/flows/'.$id);
    }

    /**
     * A JSON setting as it was written, each empty the one it is: an
     * object, or an empty one when left empty.
     *
     * @throws StepFailed
     */
    private static function object(StepContext $step, string $setting): stdClass
    {
        $written = trim((string) $step->setting($setting));
        $value = $written === '' ? new stdClass : json_decode($written);

        return $value instanceof stdClass ? $value : throw new StepFailed("\"{$setting}\" should be a JSON object, like {\"text\": \"Hello\"}.");
    }

    /**
     * A step's name a setting gives.
     *
     * @throws StepFailed
     */
    private static function name(StepContext $step, string $setting, ?string $otherwise = null): string
    {
        $name = trim($step->text($setting)) ?: (string) $otherwise;

        return preg_match('/^[A-Za-z0-9_]+$/', $name) === 1 ? $name : throw new StepFailed($name === '' ? 'Name the step: step_2, as "PromptX: read a flow" lists them.' : "\"{$name}\" is not a step's name, like step_2.");
    }
}
