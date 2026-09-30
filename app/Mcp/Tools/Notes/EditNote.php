<?php

namespace App\Mcp\Tools\Notes;

use App\Mcp\Tools\NoteTool;
use App\Models\Note\Note;
use App\Support\Live\Collab;
use App\Support\NoteBlockProblem;
use App\Support\NoteBlocks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
Change part of a note by its blocks' ids (from get-note), sending only what changes. Changes are
made in order, and if one can't be made, none are:

- {"op": "replace", "block": id, "markdown": "..."}: the block becomes this; it keeps its id
- {"op": "insert_after" or "insert_before", "block": id, "markdown": "..."}
- {"op": "append" or "prepend", "markdown": "..."}: at the end or start of the note
- {"op": "delete", "block": id}
- {"op": "move", "block": id, "after" or "before": id}

Markdown given in place of, or beside, a list item becomes list items ("- one"). If someone has the
note open, the change reaches them live and whatever they are typing elsewhere in it is kept.
Answers with the ids of the blocks made or changed.
TEXT)]
class EditNote extends NoteTool
{
    private const OPS = ['replace', 'insert_before', 'insert_after', 'append', 'prepend', 'delete', 'move'];

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $this->refIdArgument($schema),
            'changes' => $schema->array()->max(100)->items($schema->object([
                'op' => $schema->string()->enum(self::OPS)->required(),
                'block' => $schema->string()->max(32)->description('The block\'s id.'),
                'markdown' => $schema->string()->description('The new content, as Markdown.'),
                'after' => $schema->string()->max(32)->description('For move: the block it goes after.'),
                'before' => $schema->string()->max(32)->description('For move: the block it goes before.'),
            ]))->description('The changes, made in order.')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);
        $owner = $this->targetOwner($request, changes: true);

        $validated = $request->validate([
            'ref_id' => ['required', 'string', 'max:16'],
            'changes' => ['required', 'array', 'min:1', 'max:100'],
            'changes.*.op' => ['required', Rule::in(self::OPS)],
            'changes.*.block' => ['nullable', 'string', 'max:32'],
            'changes.*.markdown' => ['nullable', 'string', 'max:200000'],
            'changes.*.after' => ['nullable', 'string', 'max:32'],
            'changes.*.before' => ['nullable', 'string', 'max:32'],
        ]);

        /** @var Note|null $note */
        $note = $this->find($owner, $validated['ref_id']);

        if (! $note) {
            return $this->notFound($validated['ref_id']);
        }

        // The ids are checked against the note as everyone has it now
        Collab::flush($note);
        $note->refresh();

        try {
            $done = NoteBlocks::apply($note->content, array_values($validated['changes']));
        } catch (NoteBlockProblem $problem) {
            return Response::error($problem->getMessage());
        }

        // Open somewhere: made in the live copy, block by block, which the app is then handed
        $live = Collab::apply($note, $done['edits'], $user);

        if ($live === null) {
            $note->content = $done['doc'];
            $note->save();
        } else {
            $note->refresh();
        }

        return Response::structured($this->answer($note, array_filter([
            'changed' => $done['changed'],
            // Deleted by someone, live, in the moment between reading and changing
            'not_found' => $live['missing'] ?? null,
        ])));
    }
}
