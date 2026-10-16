<?php

namespace App\Mcp\Servers;

/**
 * What both MCP servers tell a client about Zyrenn and its tools: the
 * personal one (UserServer: as the token's owner) and the admin one
 * (GlobalServer: as any user, by user_id). One text, with the few passages
 * that differ filled in for each.
 */
final class Instructions
{
    /**
     * @param  list<string>|null  $parts  the parts the key in use may see (Scopes): the
     *                                    others are left out; null for all of them
     */
    public static function text(bool $global, ?array $parts = null): string
    {
        $text = strtr(self::TEXT, [
            '{who}' => $global
                ? <<<'TEXT'
This server can act as any user: call
list-users first, then pass that user's id as "user_id" to every other tool.
TEXT
                : <<<'TEXT'
Every tool here acts as the user who owns
the access token, on their own content -- or, given a "project" argument, on a project they are in.
TEXT,
            '{projects}' => $global
                ? <<<'TEXT'
list-projects shows the ones a user is in, with their role in each,
and create-project starts one. Pass a project's ref_id or name as "project" to any other tool to work
in it as that user; leave it out for the user's own content. A viewer can only read a project; its
owners and editors can change it.
TEXT
                : <<<'TEXT'
list-projects shows the user's, with their role in each, and
create-project starts one. Pass a project's ref_id or name as "project" to any other tool to work in
it; leave it out for the user's own. A viewer can only read a project; its owners and editors can
change what is in it.
TEXT,
            '{whose}' => $global
                ? <<<'TEXT'
-- each user's private ones,
TEXT
                : <<<'TEXT'
-- the user's private ones,
TEXT,
            '{drive}' => $global
                ? <<<'TEXT'
(the same user_id, and for a project's note or board the same "project")
TEXT
                : <<<'TEXT'
(for a project's note or board, that project's Drive, or its other members can't see it)
TEXT,
        ]);

        return $parts === null ? $text : self::only($text, $parts);
    }

    /** Each part's line, by the words it starts with. */
    private const LINES = [
        'notes' => 'Notes', 'boards' => 'Boards', 'tables' => 'Tables', 'trips' => 'Trips',
        'places' => 'Saved places', 'workflows' => 'Workflows', 'checks' => 'Browser checks',
        'tickets' => 'Tickets', 'product' => 'Product', 'chats' => 'Chats with customers', 'drive' => 'Drive',
    ];

    /**
     * The text with each part's line -- "- Notes: ...", and the lines under
     * it -- left out unless it is among `$parts`.
     *
     * @param  list<string>  $parts
     */
    private static function only(string $text, array $parts): string
    {
        $hidden = array_values(array_diff_key(self::LINES, array_flip($parts)));
        $kept = [];
        $skipping = false;

        foreach (explode("\n", $text) as $line) {
            if (str_starts_with($line, '- ')) {
                $skipping = collect($hidden)->contains(fn (string $words) => str_starts_with($line, "- {$words}"));
            } elseif (! str_starts_with($line, '  ')) {
                $skipping = false;
            }

            if (! $skipping) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }

    /**
     * Kept short -- a client may keep only the first 4,096 characters -- as
     * an index: what each part is and its tools, and how to spend few words
     * on them. How each thing is written is in its own tools' descriptions,
     * read only when they are used.
     */
    private const TEXT = <<<'TEXT'
Zyrenn holds notes, whiteboard boards, tables, trips, saved map places, workflows, browser checks and
a file Drive. {who}

Projects: shared spaces whose notes, boards, tables and Drive belong to the project rather than to
whoever made them. {projects}

Read only what you need and send only what changes: get-note reads a note's blocks or one section,
get-board a frame or an outline, get-table a page of rows, get-trip one day; edit-note, update-board,
update-table and update-trip change parts by id. How each thing is written is in its tools' own
descriptions.

- Notes: Markdown, in folders by path ("KT Plan/Lakeshore"); [[Title]] links a page by its title, and
  create-note lists the fences a note draws (diagrams, circuits, graphs, signals, trips, maps).
  get-note, create-note, update-note, edit-note, list-notes, list-folders.
- Boards: items each of a "kind" -- shapes, sticky notes, text, frames (slides), pictures, videos,
  library shapes, circuit symbols -- laid out for you without x and y; an "arrow" joins items by id.
  list-boards, get-board ("image": true to see it), create-board, update-board, list-board-folders;
  the user's own shapes (save-my-shape keeps items as one, place-my-shape puts it on a board, list-my-shapes).
- Tables: columns of one kind each, rows keyed by column label. list-tables, get-table, create-table,
  update-table, list-table-folders.
- Trips: days of stops, hotels, flights; every place needs "lat" and "lng" from search-places.
  list-trips, get-trip, create-trip, update-trip, list-trip-folders.
- Saved places: map pins in lists. list-places, save-place, delete-place.
- Workflows: steps that run by themselves; list-workflow-steps says what steps there are.
  create-workflow, update-workflow (the draft, by step key), publish-workflow, then switch-workflow to
  turn it on; run-workflow, get-workflow-run. Steps that send messages or change tables do so for real.
- Browser checks: steps a real browser does on a public site, going by what a person reads there.
  Asked to test a site: open-browser-page, do-browser-step with "keep" until the check is whole,
  close-browser-page, then run-browser-suite with "wait". look-at-page lists what a page offers.
- Tickets (always "project"): bugs and features by number. list-tickets, get-ticket, create-ticket,
  update-ticket -- "in_progress" while one is being done, "done" when it is; "on_hold" (waits on something) and "dropped" take a "reason".
- Product (always "project"): components (CMP-n) with capabilities (CAP-n), requests (RQ-n, word for
  word) and requirements (REQ-n). get-product, get-product-part (read its "impact" before changing a
  shared part), update-product, list-product-requests, save-product-request, list-requirements,
  save-requirement.
- Chats with customers (always "project"): its inbox on LINE, Telegram, WhatsApp, Instagram.
  list-chats, get-chat, say-in-chat (to the customer, unless a "note"), update-chat.
- Drive: files {whose} or a project's. list-drive, get-file, upload-file, request-upload, update-file.

A picture or video for a note or a board goes into the Drive first {drive}: a file on your disk by
request-upload and the curl it gives, one on the web by upload-file's "source_url". Then put the
answer's "markdown" line in the note, or its "url" as the "src" of an "image" or "video" item.
TEXT;
}
