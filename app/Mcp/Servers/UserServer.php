<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Boards\CreateBoard;
use App\Mcp\Tools\Boards\DeleteBoard;
use App\Mcp\Tools\Boards\GetBoard;
use App\Mcp\Tools\Boards\ListBoardFolders;
use App\Mcp\Tools\Boards\ListBoards;
use App\Mcp\Tools\Boards\UpdateBoard;
use App\Mcp\Tools\Drive\DeleteFile;
use App\Mcp\Tools\Drive\GetFile;
use App\Mcp\Tools\Drive\ListDrive;
use App\Mcp\Tools\Drive\RequestUpload;
use App\Mcp\Tools\Drive\UpdateFile;
use App\Mcp\Tools\Drive\UploadFile;
use App\Mcp\Tools\Notes\CreateNote;
use App\Mcp\Tools\Notes\DeleteNote;
use App\Mcp\Tools\Notes\EditNote;
use App\Mcp\Tools\Notes\GetNote;
use App\Mcp\Tools\Notes\ListFolders;
use App\Mcp\Tools\Notes\ListNotes;
use App\Mcp\Tools\Notes\UpdateNote;
use App\Mcp\Tools\Places\DeletePlace;
use App\Mcp\Tools\Places\ListPlaces;
use App\Mcp\Tools\Places\SavePlace;
use App\Mcp\Tools\Places\SearchPlaces;
use App\Mcp\Tools\Projects\CreateProject;
use App\Mcp\Tools\Projects\ListProjects;
use App\Mcp\Tools\Tables\CreateTable;
use App\Mcp\Tools\Tables\DeleteTable;
use App\Mcp\Tools\Tables\GetTable;
use App\Mcp\Tools\Tables\ListTableFolders;
use App\Mcp\Tools\Tables\ListTables;
use App\Mcp\Tools\Tables\UpdateTable;
use App\Mcp\Tools\Tickets\CreateTicket;
use App\Mcp\Tools\Tickets\GetTicket;
use App\Mcp\Tools\Tickets\ListTickets;
use App\Mcp\Tools\Tickets\UpdateTicket;
use App\Mcp\Tools\Trips\CreateTrip;
use App\Mcp\Tools\Trips\DeleteTrip;
use App\Mcp\Tools\Trips\GetTrip;
use App\Mcp\Tools\Trips\ListTripFolders;
use App\Mcp\Tools\Trips\ListTrips;
use App\Mcp\Tools\Trips\UpdateTrip;
use App\Mcp\Tools\Workflows\CreateWorkflow;
use App\Mcp\Tools\Workflows\DeleteWorkflow;
use App\Mcp\Tools\Workflows\GetWorkflow;
use App\Mcp\Tools\Workflows\GetWorkflowRun;
use App\Mcp\Tools\Workflows\ListWorkflowFolders;
use App\Mcp\Tools\Workflows\ListWorkflowRuns;
use App\Mcp\Tools\Workflows\ListWorkflows;
use App\Mcp\Tools\Workflows\ListWorkflowSteps;
use App\Mcp\Tools\Workflows\PublishWorkflow;
use App\Mcp\Tools\Workflows\RunWorkflow;
use App\Mcp\Tools\Workflows\SwitchWorkflow;
use App\Mcp\Tools\Workflows\UpdateWorkflow;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Zyrenn (personal)')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
Zyrenn holds notes, whiteboard boards, tables, trips, saved map places and a file Drive. Every tool here acts as the user who owns
the access token, on their own content -- or, given a "project" argument, on a project they are in.

Projects: shared spaces with notes, boards, tables and a Drive of their own, which belong to the project
rather than to whoever made them. list-projects shows the user's, with their role in each, and
create-project starts one. Pass a project's ref_id or name as "project" to any other tool to work in
it; leave it out for the user's own. A viewer can only read a project; its owners and editors can
change what is in it.

Tickets: what a project has been asked to fix or to add (list-tickets, get-ticket, create-ticket,
update-ticket), each a "bug" or a "feature" with a number in its project, a title, the part ("unit") it
is about, a body, a priority and a status: open, in_progress, done, or dropped. They always take
"project". Asked to work through a project's tickets: list-tickets, get-ticket for each, set it
"in_progress" while it is being done and "done" when it is.

Notes: bodies are read and written as Markdown (get-note, create-note, update-note, edit-note). Notes
live in folders addressed by path, e.g. "KT Plan/Lakeshore". Every block of a note -- a paragraph,
heading, list, table -- has an id: get-note gives a long note as an outline of them, and reads just
the blocks or section you ask for; edit-note replaces, inserts, moves or deletes blocks by id. For
anything short of rewriting a note, read and change only the part you need. To link to another page,
write [[its title]] -- or [[Title|other words]], or [[board:Title]], [[table:Title]], [[trip:Title]] for
a page that is not a note: the page is found by title among the same owner's, so no address or ref_id
is needed, and get-note lists the notes that link to this one as "linked_from".

Boards: endless whiteboard canvases of shapes, sticky notes, connectors, pictures and 16:9 frames
(list-boards, get-board, create-board, update-board, delete-board). A board is a list of items, each
with a "kind" -- rect, pill, ellipse, triangle, diamond, hexagon, star, sticky, text, frame, image,
video, "math" for a formula written as LaTeX, the flowchart set (cylinder for a database, parallelogram,
document, process for a predefined process -- an ordinary step is a rect -- cloud), "arrow" for a connector and "draw" for freehand ink. Leave an item's x and y out and it is laid out for you. A
connector's "from" and "to" name other items by id and stay pinned to their edges as those shapes are
moved or resized, so a diagram survives being rearranged by hand afterwards. Boards have their own
folder tree, listed by list-board-folders. get-board lists a board's frames, reads one frame with
"frame", and gives a big board in outline; update-board's add_items, update_items and delete_items
change items by id, sending only what changes -- prefer them to rewriting "items" whole.
A label can be light Markdown ("rich": "# " headings, "- " bullets, **bold**), in "sans", "serif" or
"mono", with its own "padding". More shapes come from a library, listed under "kind": more flowchart,
software (a "table" or a "class" holding "rows" -- columns with a key and a type, members with a
visibility -- a "lifeline" for a sequence, an actor), architecture (server, user, queue, bucket) and a
"lane" for swimlanes. A connector's ends can be capped for a class diagram or a data model, hold on
to one row of a table ("pin"), or sit part way down a lifeline ("along"). create-board's "template"
starts a board from a ready-made diagram. A circuit can be drawn too: items of kind "symbol" name a part in "symbol"
(resistor, led, npn, esp32, a "chip:VCC,GND|OUT" of your own, ...) with its designator and value as "text"
("R1\n10 kΩ"), and an arrow whose ends name pins -- {"item": "r1", "pin": "2"} -- is a wire. Easier: send
the circuit as text in create-board's "circuit" (update-board's "add_circuit"), the lines a ```circuit
fence takes, and it is laid out for you. Logic runs on the board as it is drawn: gates (and, or, not,
nand, nor, xor, xnor, and3, or3, nand3, nor3, dff) between "input" switches ("on": true sets one) or
"press" push-buttons and "output" lamps or a "seg7" / "bcd7" display. To see a board or one frame as the app draws it, get-board with
"image": true sends a PNG; "check": true lists labels that run out of their box and items lying over
each other.

Tables: rows and columns, each column of one kind -- varchar, text, integer, numeric, boolean,
select, multi_select, date, email, url, phone, currency, percent, rating, user, location (list-tables,
get-table, create-table, update-table, delete-table). A row is written as an object of values keyed
by column label, e.g. {"Owner": "Ada", "Budget": 300}; a location is {"lat", "lng", "label"}, and a
table with one can be seen as a map; update-table changes or deletes rows by the
"id" get-table shows. Tables have their own folder tree, listed by list-table-folders. get-table reads
a page of rows at a time ("limit", "offset"), and can keep only rows matching a "search" and only the
"columns" you name -- read what you need rather than the whole table.

Trips: travel plans (list-trips, get-trip, create-trip, update-trip, delete-trip) -- a start date,
the flight in and the flight home, the hotels booked with check-in and check-out, and days of stops in
the order visited, with rests. Every place has a "lat" and "lng": search-places finds them. get-trip
gives the outline, with every day's stops by id; with "day" it reads one day in full, with the route it
takes and the rides between its places. update-trip adds, changes, moves and deletes days, stops and
hotels by id, and types in a ride by hand (a metro, a taxi) -- send only what changes. Trips have their
own folder tree, listed by list-trip-folders. To show a trip in a note, put a ```trip fence in its
Markdown holding "ref: <the trip's ref_id>" and "day: all" (the whole trip) or "day: <a day's id>"
(that day's timeline), each on a line of its own. To show one place on a map in a note, put a ```map
fence holding "name: ", "address: ", "lat: " and "lng: " lines (and "zoom: ", 1 to 20, or "saved: <a
saved place's ref_id>" to show that place as it is now); to show a link as a card with its page's
title and picture, a ```link fence holding "url: " and "title: ".
To draw a circuit in a note, put a ```circuit fence holding its parts, one to a line as "NAME: [what]
[value]" ("R1: 10k", "D1: led red", "Q1: npn BC547", "V1: 9V" -- what a part is can be left out when
its name says so), then its wires as ends joined by "--": a pin ("Q1.b"), a two-pin part to run
through from its first pin to its second, or a net's name ("gnd", "3V3", "sda"). For example
"V1 -- R1 -- D1 -- V1", or with a board: "U1: esp32", "S1: dht22", "U1.GPIO4 -- S1.DATA" (a chip of your
own lists its pins: "U2: chip 74HC595 [SER, CLK | QA, QB]"). The note lays it out and draws it. A
line "values" works it out at DC and writes each wire's voltage and each part's current on it, marking
a part driven past its rating ("R1: 100 1W", "D1: led red 30mA"; else 0.25 W and 20 mA); "values
hidden" keeps them back until the reader clicks Values -- for a guess to be made first.
To draw a graph in a note, put a ```plot fence holding: "t = 0 to 5" (what runs along the bottom),
"R = 1 from 0.2 to 5" (a value the reader can slide), "C = 1" (one that stays), a curve per line as
"V(t) = 5 * (1 - exp(-t / (R * C)))", and "title: ", "x: Time (s)", "y: Volts, 0 to 6" as wanted. Or
draw a table's columns: "table: Its title", "x: A column", "y: Another, And another" (numbers only).
To keep an answer out of sight until it is clicked: ":::reveal Answer" on a line, then any blocks,
then ":::".
To draw signals over time -- a clock, a bus, a chip-select -- put a ```timing fence holding a line
for each as "NAME: steps", a character to a step: 0 low, 1 high, "." more of the same, p a clock
pulse (n one that starts low), x unknown, z let go of, = a value named in order after a "|"
("MOSI: x=.=.x | D7 | D6"); "title: ..." and "mark: 2 start, 10 stop" are lines of their own.

Saved places: pins kept on the map, each in a list such as "Want to go" (list-places, save-place,
delete-place).

Workflows: steps that run on their own (list-workflows, list-workflow-folders, get-workflow,
list-workflow-steps, create-workflow, update-workflow, publish-workflow, switch-workflow, run-workflow,
list-workflow-runs, get-workflow-run, delete-workflow). list-workflow-steps says what kinds of step
there are, what each is set up with, and what settings may point at (connections, rooms, tables, by
ref_id). create-workflow makes one -- empty, from a template, or built at once; update-workflow
changes its draft by step key: add (placed and joined after another), change (settings merged),
remove, join, unjoin. Steps read what came before in templates: {{ trigger.text }}, {{ input.x }},
{{ node("key").field }}. publish-workflow publishes the draft, switch-workflow switches it on so its
triggers (a schedule, a chat, a webhook, a bot) start runs by themselves; run-workflow runs it from its
"Run by hand" trigger with data of your own; get-workflow-run reads how each step went. Steps that send
messages or change tables do so for real when run.

Drive: files (list-drive, get-file, upload-file, request-upload, update-file, delete-file) -- the user's private ones,
or a project's, which its members can all open. Files are served from a URL like /drive/files/k3x9m2p7qa.

To put a picture in a note or on a board, the picture must be in the Drive first -- neither can carry
image bytes of its own. For a project's note or board, upload it to that project's Drive (the same
"project"), or its other members won't be able to see it:

  1. Upload it -- the bytes never go through a tool call:
     - a file on your own disk: request-upload, then run the "curl" command it gives with the file's path;
     - a file on the public web: upload-file with its URL in "source_url".
  2. For a note: take the "markdown" line from the response, e.g. ![chart.png](/drive/files/k3x9m2p7qa),
     and put it in the "markdown" you pass to create-note or update-note.
  3. For a board: take the "url" from the response and pass it as an item's "src" with kind "image".

An image already on the public web can be used instead -- ![alt](https://...) in a note, or that URL
as "src" on a board -- but it is not stored with the account and breaks if that site goes away.

A video (MP4 or WebM) goes the same way and plays where it is put: upload it, then put the "markdown"
line from the response -- <video src="/drive/files/k3x9m2p7qa" title="demo.mp4"></video> -- on a line
of its own in a note, or pass the "url" as "src" with kind "video" on a board.
TEXT)]
class UserServer extends Server
{
    protected function boot(): void
    {
        $this->tools = [
            new ListProjects,
            new CreateProject,
            new ListTickets,
            new GetTicket,
            new CreateTicket,
            new UpdateTicket,
            new ListNotes,
            new ListFolders,
            new GetNote,
            new CreateNote,
            new UpdateNote,
            new EditNote,
            new DeleteNote,
            new ListBoards,
            new ListBoardFolders,
            new GetBoard,
            new CreateBoard,
            new UpdateBoard,
            new DeleteBoard,
            new ListTables,
            new ListTableFolders,
            new GetTable,
            new CreateTable,
            new UpdateTable,
            new DeleteTable,
            new ListTrips,
            new ListTripFolders,
            new GetTrip,
            new CreateTrip,
            new UpdateTrip,
            new DeleteTrip,
            new ListWorkflows,
            new GetWorkflow,
            new ListWorkflowFolders,
            new ListWorkflowSteps,
            new CreateWorkflow,
            new UpdateWorkflow,
            new PublishWorkflow,
            new DeleteWorkflow,
            new RunWorkflow,
            new SwitchWorkflow,
            new ListWorkflowRuns,
            new GetWorkflowRun,
            new SearchPlaces,
            new ListPlaces,
            new SavePlace,
            new DeletePlace,
            new ListDrive,
            new GetFile,
            new UploadFile,
            new RequestUpload,
            new UpdateFile,
            new DeleteFile,
        ];
    }
}
