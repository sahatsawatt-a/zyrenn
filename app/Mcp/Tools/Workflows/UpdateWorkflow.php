<?php

namespace App\Mcp\Tools\Workflows;

use App\Mcp\Tools\WorkflowTool;
use App\Models\Workflow\Workflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
Change a workflow's draft by step key -- send only what changes, in one call: "remove" steps (with
their joins), "change" steps (settings merged, a key renamed everywhere node("key") names it), "add"
steps (placed and joined after "after" when given), "unjoin" and "join" -- done in that order, so a
step added may follow one just renamed and be joined at once. Also "title" and "folder". Pass the
"revision" get-workflow gave, so a change is never made over someone else's: when it has moved on,
nothing is changed. Any one thing that can't be done changes nothing at all.

Anyone with it open in the editor sees the change. What is switched on runs what is published, so
publish-workflow when done. Answers as get-workflow does, with anything still standing in the way.
TEXT)]
class UpdateWorkflow extends WorkflowTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $this->refIdArgument($schema),
            'revision' => $schema->integer()->min(0)->description('The revision get-workflow gave.'),
            'title' => $schema->string()->max(255),
            'folder' => $this->folderArgument($schema, 'Move it to this folder ("" for the top); folders that don\'t exist yet are made.'),
            ...$this->editArguments($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);
        $owner = $this->targetOwner($request, changes: true);
        $validated = $request->validate([
            'ref_id' => ['required', 'string', 'max:16'],
            'revision' => ['nullable', 'integer', 'min:0'],
            'title' => ['nullable', 'string', 'max:255'],
            'folder' => ['nullable', 'string', 'max:1000'],
            ...$this->editRules(),
        ]);

        /** @var Workflow|null $workflow */
        $workflow = $this->find($owner, $validated['ref_id']);

        if (! $workflow) {
            return $this->notFound($validated['ref_id']);
        }

        if (isset($validated['revision']) && $validated['revision'] !== $workflow->revision) {
            return Response::error("It has changed since (now revision {$workflow->revision}): get-workflow again, then make the change on what is there now.");
        }

        try {
            $workflow->definition = $this->editedDraft($workflow, $validated);
        } catch (InvalidArgumentException $problem) {
            return Response::error($problem->getMessage());
        }

        if (isset($validated['title'])) {
            $workflow->title = $validated['title'];
        }

        if (array_key_exists('folder', $validated)) {
            $workflow->folder_id = $this->ensureFolderAt($owner, $validated['folder'] ?? '', $user)?->id;
        }

        $workflow->save();

        return Response::structured($this->described($workflow->refresh()));
    }
}
