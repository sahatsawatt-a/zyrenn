<?php

namespace App\Mcp\Tools\Boards;

use App\Mcp\Tools\BoardTool;
use App\Models\Board\Board;
use App\Support\Board\BoardItemProblem;
use App\Support\Board\BoardItems;
use App\Support\Board\BoardTables;
use App\Support\Board\CircuitLayout;
use App\Support\Board\Deck;
use App\Support\Board\Templates;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
Create a board: an endless canvas of shapes, sticky notes, connectors, pictures and 16:9 frames to
present. "items" is what goes on it, in the order they are drawn, back to front.

Only "kind" is needed for each item. Leave "x" and "y" out and items are laid out in rows for you.
A connector is an item of kind "arrow" whose "from" and "to" name other items by id; it stays pinned
to those shapes' edges however they are moved or resized later.

A flowchart, for example:
[{"id":"a","kind":"process","text":"Order placed"},
 {"id":"b","kind":"diamond","text":"In stock?"},
 {"id":"c","kind":"cylinder","text":"Warehouse"},
 {"kind":"arrow","from":{"item":"a"},"to":{"item":"b"}},
 {"kind":"arrow","from":{"item":"b"},"to":{"item":"c"},"text":"yes"}]

A circuit is items of kind "symbol", each a part with pins, wired by arrows that name the pins they
join. Parts keep to a 20-unit grid so the wires run straight:
[{"id":"v1","kind":"symbol","symbol":"battery","text":"BT1\n9 V","x":0,"y":40},
 {"id":"r1","kind":"symbol","symbol":"resistor","text":"R1\n330 Ω","x":80,"y":0},
 {"id":"d1","kind":"symbol","symbol":"led","text":"D1","x":260,"y":40,"rotation":90},
 {"kind":"arrow","from":{"item":"v1","pin":"+"},"to":{"item":"r1","pin":"1"}},
 {"kind":"arrow","from":{"item":"r1","pin":"2"},"to":{"item":"d1","pin":"a"}},
 {"kind":"arrow","from":{"item":"d1","pin":"k"},"to":{"item":"v1","pin":"-"}}]

Easier: write the circuit as text in "circuit" and it is laid out for you, wires and all -- one line
for each part ("R1: 10k", "D1: led red", "U1: esp32"), then what is joined ("V1 -- R1 -- D1 -- V1",
"U1.GPIO4 -- R1"). A name that is not a part is a net: "5V", "GND", "out". The answer lists what was
put on, each part under its own name ("r1") for changing later.

To start from a ready-made diagram instead of drawing one, name it in "template": the answer lists
its items, each under an id to change with update-board.

A deck of slides: "deck" for its look and what every slide says (a header, a footer, numbers), and
"slides", each laid out from a layout, one after another:
"deck": {"theme": "ivory", "footer": "{title} · {section}", "numbers": true},
"slides": [{"layout": "title", "title": "Q3 in review", "subtitle": "What changed, and why"},
 {"layout": "title-body", "title": "Sign-ups doubled after the redesign", "body": "- 1,204 to 2,431\n- Most from search", "notes": "Pause on the chart"}]
TEXT)]
class CreateBoard extends BoardTool
{
    use MakesDecks;

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(255)->description('The board title.')->required(),
            'items' => $this->itemsArgument($schema, 'What to put on the board, drawn back to front. An empty board is fine too.'),
            'circuit' => $this->circuitArgument($schema, 'A circuit as text, laid out for you and put on the board (under "items", when both are sent).'),
            'template' => $schema->string()->enum(Templates::ids())->description('A diagram to start the board from, with sample words to change: '.Templates::described().'. Sent with "items", those are drawn over it.'),
            'folder' => $this->folderArgument($schema, 'Folder to create the board in; folders that don\'t exist yet are created.'),
            'deck' => $this->deckArgument($schema),
            'slides' => $this->slidesArgument($schema, 'Slides laid out for you, one after another to the right of anything else, in the deck\'s look.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);
        $owner = $this->targetOwner($request, changes: true);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'folder' => ['nullable', 'string', 'max:1000'],
            'circuit' => ['nullable', 'string', 'max:'.self::MAX_CIRCUIT],
            'template' => ['nullable', 'string', Rule::in(Templates::ids())],
            ...$this->itemRules(),
            ...$this->deckRules(),
        ]);

        $deck = is_array($validated['deck'] ?? null) ? Deck::merged(null, $validated['deck']) : null;

        // A template is the first thing drawn; what else was sent goes over it
        $specs = [...Templates::specs($validated['template'] ?? ''), ...$this->specs($validated)];

        try {
            // A circuit written as text is laid out, and goes on with the rest
            if (filled($validated['circuit'] ?? null)) {
                $specs = [...$specs, ...CircuitLayout::specs($validated['circuit'], array_column($specs, 'id'), CircuitLayout::under($specs))];
            }
        } catch (BoardItemProblem $problem) {
            return Response::error($problem->getMessage());
        }

        // Slides after whatever else was drawn, in the deck's look
        if (isset($validated['slides'])) {
            $specs = [...$specs, ...Deck::slideSpecs($this->slides($validated, 'slides'), $specs, $deck)];
        }

        $problem = $this->itemProblem($specs);

        if ($problem !== null) {
            return Response::error($problem);
        }

        try {
            $content = $specs === [] && $deck === null ? null : Deck::content($specs === [] ? [] : BoardItems::fromSpec($specs, [], BoardTables::of($owner)), $deck);
        } catch (BoardItemProblem $problem) {
            return Response::error($problem->getMessage());
        }

        $board = (new Board([
            'title' => $validated['title'],
            'content' => $content,
        ]))->ownedBy($owner, $user);
        $board->folder_id = $this->ensureFolderAt($owner, $validated['folder'] ?? '', $user)?->id;
        $board->save();

        return Response::structured($this->answer($board->refresh(), [
            ...($deck !== null ? ['deck' => $deck] : []),
            ...$this->overflow($board->content['items'] ?? []),
        ]));
    }
}
