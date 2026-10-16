<?php

namespace App\Mcp\Tools\Notes;

use App\Mcp\Tools\NoteTool;
use App\Models\Note\Note;
use App\Support\Markdown\TiptapMarkdown;
use App\Support\Note\PageLinks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
Create a note. Content is Markdown: headings, lists, "- [ ]" to-dos, > quotes, GFM tables, ```code,
$…$ inline math and $$ … $$ math blocks (LaTeX), ":::callout 💡 … :::" callouts, ":::reveal Answer …
:::" for something kept out of sight until it is clicked, and "<!-- pagebreak -->" on a line of its
own to start a new page in the PDF. Images go in as ![alt](url) on their own line -- upload the picture
with upload-file (or request-upload) first and use the "markdown" line it returns.

Links: [[Title]] links to another page of the same owner by its title -- no address needed;
[[Title|shown words]] shows other words, and [[board:Title]], [[table:Title]] or [[trip:Title]] link a
page that is not a note.

Fences a note draws, each a ``` block of lines:
- ```mermaid: a diagram.
- ```circuit: its parts, one to a line as "NAME: [what] [value]" ("R1: 10k", "D1: led red", "Q1: npn
  BC547", "V1: 9V"), then its wires as ends joined by "--": a pin ("Q1.b"), a two-pin part run through
  from its first pin to its second, or a net ("gnd", "3V3"): "V1 -- R1 -- D1 -- V1", "U1: esp32",
  "U1.GPIO4 -- S1.DATA"; a chip of your own lists its pins: "U2: chip 74HC595 [SER, CLK | QA, QB]". A
  line "values" works it out at DC and writes each wire's voltage and each part's current, marking a
  part driven past its rating ("R1: 100 1W", "D1: led red 30mA"; else 0.25 W and 20 mA); "values
  hidden" keeps them back until the reader clicks Values.
- ```plot: "t = 0 to 5" (along the bottom), "R = 1 from 0.2 to 5" (a slider), "C = 1" (fixed), a curve
  per line as "V(t) = 5 * (1 - exp(-t / (R * C)))", and "title: ", "x: Time (s)", "y: Volts, 0 to 6".
  Or a table's columns: "table: Its title", "x: A column", "y: Another, And another".
- ```timing: a line per signal as "NAME: steps", a character a step: 0 low, 1 high, "." the same, p a
  clock pulse (n one that starts low), x unknown, z let go, = a value named in order after "|"
  ("MOSI: x=.=.x | D7 | D6"); "title: ..." and "mark: 2 start, 10 stop" on lines of their own.
- ```trip: "ref: <a trip's ref_id>" and "day: all" (the whole trip) or "day: <a day's id>".
- ```map: "name: ", "address: ", "lat: ", "lng: " (and "zoom: " 1 to 20, or "saved: <a saved place's
  ref_id>" to show that place as it is now).
- ```link: "url: " and "title: ", shown as a card with its page's title and picture.
TEXT)]
class CreateNote extends NoteTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(255)->description('The note title.')->required(),
            'markdown' => $schema->string()->description('The note body as Markdown. To show a picture, put the "markdown" line from upload-file on its own line.'),
            'is_wide' => $schema->boolean()->description('Use the full-width page layout.'),
            'folder' => $this->folderArgument($schema, 'Folder to create the note in; folders that don\'t exist yet are created.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $user = $this->targetUser($request);
        $owner = $this->targetOwner($request, changes: true);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'markdown' => ['nullable', 'string', 'max:500000'],
            'is_wide' => ['nullable', 'boolean'],
            'folder' => ['nullable', 'string', 'max:1000'],
        ]);

        $note = (new Note([
            'title' => $validated['title'],
            'content' => isset($validated['markdown']) ? TiptapMarkdown::toDoc($validated['markdown']) : null,
            'is_wide' => $validated['is_wide'] ?? false,
        ]))->ownedBy($owner, $user);
        $note->folder_id = $this->ensureFolderAt($owner, $validated['folder'] ?? '', $user)?->id;
        $note->save();

        return Response::structured($this->answer($note->refresh(), PageLinks::report($note->content)));
    }
}
