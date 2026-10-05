<?php

namespace App\Mcp\Tools\Notes;

use App\Mcp\Tools\NoteTool;
use App\Models\Note\Note;
use App\Support\Formula\NoteValues;
use App\Support\Live\Collab;
use App\Support\NoteBlockProblem;
use App\Support\NoteBlocks;
use App\Support\TiptapMarkdown;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TEXT'
Get a note. Each of its blocks -- a paragraph, heading, list, table, code block, picture -- has an id.

Without "section" or "blocks": its outline, each block's id, kind and first words, and when the note
is short, all of it as Markdown too. With "section" (a heading's text) or "blocks" (ids from the
outline): just those blocks as Markdown, each under its id; a list also gives each item's id.

To change part of a note, read the blocks you need and use edit-note: it sends only what changes.

A live value is written {{ formula }} -- {{ trip("Shanghai").total_cost }}, {{ sum(table("Budget").thb) }},
{{ text(trip("Shanghai").day(5).date, "D j M") }} -- and shown as what it comes to now, among the same
owner's trips and tables. "values" gives what each in the note comes to, or why it can't be worked out.
TEXT)]
class GetNote extends NoteTool
{
    /** A note this long or shorter, as Markdown, comes whole with its outline. */
    private const WHOLE_UP_TO = 6000;

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $this->refIdArgument($schema),
            'section' => $schema->string()->max(255)->description('A heading\'s text: that heading and what follows it, up to the next heading as big.'),
            'blocks' => $schema->array()->max(100)->items($schema->string()->max(32))->description('Block ids from the outline, to read just those.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $owner = $this->targetOwner($request);

        $validated = $request->validate([
            'ref_id' => ['required', 'string', 'max:16'],
            'section' => ['nullable', 'string', 'max:255'],
            'blocks' => ['nullable', 'array', 'max:100'],
            'blocks.*' => ['string', 'max:32'],
        ]);

        /** @var Note|null $note */
        $note = $this->find($owner, $validated['ref_id']);

        if (! $note) {
            return $this->notFound($validated['ref_id']);
        }

        // Open and being written in: read what everyone has typed, not what was kept a moment ago
        Collab::flush($note);
        $note->refresh();

        try {
            if (filled($validated['section'] ?? null)) {
                return Response::structured([...$this->summary($note), 'blocks' => NoteBlocks::present(NoteBlocks::section($note->content, $validated['section'])), ...$this->values($note)]);
            }

            if (filled($validated['blocks'] ?? null)) {
                return Response::structured([...$this->summary($note), 'blocks' => NoteBlocks::present(NoteBlocks::find($note->content, $validated['blocks'])), ...$this->values($note)]);
            }
        } catch (NoteBlockProblem $problem) {
            return Response::error($problem->getMessage());
        }

        $markdown = TiptapMarkdown::toMarkdown($note->content);

        return Response::structured([
            ...$this->summary($note),
            'outline' => NoteBlocks::outline($note->content),
            ...(mb_strlen($markdown) <= self::WHOLE_UP_TO
                ? ['markdown' => $markdown]
                : ['more' => 'The note is long, so only its outline is here. Read parts of it with "section" or "blocks".']),
            ...$this->values($note),
        ]);
    }

    /**
     * What each live value in the note comes to now, by its formula: the
     * text a reader sees, or {error}.
     *
     * @return array{values?: array<string, string|array{error: string}>}
     */
    private function values(Note $note): array
    {
        $expressions = NoteValues::expressions($note->content);

        if ($expressions === []) {
            return [];
        }

        return ['values' => array_map(
            fn (array $answer) => isset($answer['error']) ? ['error' => $answer['error']] : (string) ($answer['text'] ?? ''),
            NoteValues::of($note, $expressions),
        )];
    }
}
