<?php

namespace App\Mcp\Tools\Workflows;

use App\Mcp\Tools\WorkflowTool;
use App\Models\Workflow\Workflow;
use App\Support\Audit\Audit;
use App\Support\Workflow\References;
use App\Support\Workflow\Templates;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
Make a workflow: empty, from one of the app's templates ("template", with the "connections" it needs
by service, e.g. {"telegram": "<a bot's ref_id>"}), and/or built at once with "add" and "join" as
update-workflow takes them. It is a draft: publish-workflow publishes it, switch-workflow switches it
on. Answers as get-workflow does, with anything still standing in the way.

Templates: telegram-room, line-room (outside chats in a room of their own, both ways), telegram-ai,
line-ai (an AI answers), telegram-drive, line-drive (a picture or file sent in is kept in the Drive),
line-menu (run by hand: a rich menu made from a picture in the Drive and shown to everyone),
daily-telegram (a table summed up each morning).
TEXT)]
class CreateWorkflow extends WorkflowTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(255)->required(),
            'folder' => $this->folderArgument($schema, 'Folder to make it in; folders that don\'t exist yet are made.'),
            'template' => $schema->string()->enum(array_keys(Templates::all()))->description('Start from this template.'),
            'connections' => $schema->object()->description('For a template: the connection it needs for each service, by ref_id.'),
            ...$this->editArguments($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);
        $owner = $this->targetOwner($request, changes: true);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'folder' => ['nullable', 'string', 'max:1000'],
            'template' => ['nullable', 'string'],
            'connections' => ['nullable', 'array'],
            ...$this->editRules(),
        ]);

        $definition = null;

        if (isset($validated['template'])) {
            $chosen = $validated['connections'] ?? [];
            $template = Templates::all($chosen)[$validated['template']] ?? null;

            if ($template === null) {
                return Response::error("There is no template \"{$validated['template']}\".");
            }

            foreach ($template['needs'] as $service => $label) {
                if (References::connection($owner, (string) ($chosen[$service] ?? ''), [$service]) === null) {
                    return Response::error("The template needs a {$label}: give one of this owner's {$service} connections as connections.{$service}.");
                }
            }

            $definition = $template['definition'];
        }

        $workflow = (new Workflow)->ownedBy($owner, $user);
        $workflow->title = $validated['title'];
        $workflow->definition = $definition;

        try {
            // Built on what it starts with, before anything is kept
            $workflow->definition = $this->editedDraft($workflow, $validated);
        } catch (InvalidArgumentException $problem) {
            return Response::error($problem->getMessage());
        }

        $workflow->folder_id = $this->ensureFolderAt($owner, $validated['folder'] ?? '', $user)?->id;
        $workflow->save();
        Audit::record($workflow, 'workflow.created', $user, ['by' => 'mcp', ...isset($validated['template']) ? ['template' => $validated['template']] : []]);

        return Response::structured($this->described($workflow->refresh()));
    }
}
