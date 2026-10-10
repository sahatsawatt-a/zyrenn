<?php

namespace App\Mcp\Tools\Workflows;

use App\Mcp\Tools\WorkflowTool;
use App\Support\Workflow\Choices;
use App\Support\Workflow\NodeTypes;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TEXT'
The kinds of step a workflow can have, to build one with create-workflow and update-workflow. Without
"type": every kind -- its type, name, group, whether it starts a run, its ways out, and what it does.
With "type": that kind in full -- each setting's name, kind (text, template with {{ formulas }},
formula without them, choice with its options, connection of the services listed, room, table,
column, fields as a list of {name, value}, number, cron, model, timezone, workflow), whether it is
needed, its default, and when it applies.

Also "choices": what settings may point at, by ref_id -- the owner's connections (name and service,
never a secret), chat rooms, tables with their columns, and the workflows a "For each" may run.
A step reads what came before as trigger.…, input.… and node("key").… in its templates and formulas.
TEXT)]
class ListWorkflowSteps extends WorkflowTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return ['type' => $schema->string()->max(60)->description('One kind, in full, e.g. "chat.send".')];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $owner = $this->targetOwner($request);
        $validated = $request->validate(['type' => ['nullable', 'string', 'max:60']]);
        $choices = collect(Choices::for($owner))->except(['hooks', 'timezone'])->all();

        if (isset($validated['type'])) {
            $type = NodeTypes::get($validated['type']);

            if ($type === null) {
                return Response::error("There is no kind of step \"{$validated['type']}\". Leave out \"type\" for them all.");
            }

            return Response::structured([
                ...collect($type)->only(['type', 'label', 'group', 'description', 'trigger', 'outputs'])->all(),
                'ways_out' => $type['type'] === 'logic.switch' ? 'One for each way it names, then "otherwise".' : $type['outputs'],
                'settings' => array_map(fn (array $setting) => array_filter([
                    'name' => $setting['name'],
                    'label' => $setting['label'],
                    'kind' => $setting['type'],
                    'required' => $setting['required'],
                    'default' => $setting['default'] ?? null,
                    'options' => $setting['options'] ?? null,
                    'services' => $setting['services'] ?? null,
                    'when' => $setting['when'] ?? null,
                    'help' => $setting['help'] ?? null,
                    'linked' => ($setting['linked'] ?? false) ? 'Also "*linked": the chat the run started from\'s own (room: "*linked:telegram" / "*linked:line").' : null,
                ], fn ($value) => $value !== null), $type['config']),
                'choices' => $choices,
            ]);
        }

        return Response::structured([
            'kinds' => array_values(array_map(fn (array $type) => [
                'type' => $type['type'],
                'label' => $type['label'],
                'group' => $type['group'],
                'starts_a_run' => $type['trigger'],
                'ways_out' => $type['type'] === 'logic.switch' ? ['…its ways', 'otherwise'] : $type['outputs'],
                'description' => $type['description'],
            ], NodeTypes::all())),
            'choices' => $choices,
        ]);
    }
}
