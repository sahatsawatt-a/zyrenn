<?php

namespace App\Mcp\Tools\BrowserChecks;

use App\Mcp\Tools\BrowserCheckTool;
use App\Models\Browser\BrowserSuite;
use App\Support\Browser\BrowserCheck;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
Make a suite of browser checks, or change one ("suite" names it). Each check is a few steps done in a
real browser, in order:
  {"do": "open", "url": "https://..."}            open a page (a check starts with one)
  {"do": "click", "target": "Log in"}              a button, link, tick box or words, by what it says
  {"do": "type", "target": "Email", "text": "..."} into a field, by its label or placeholder
  {"do": "choose", "target": "Sort by", "text": "Price"} an option of a list (look-at-page lists them)
  {"do": "hover", "target": "Products"}           hold the pointer over it: a menu that opens on its own
  {"do": "upload", "target": "Photo", "file": "<Drive ref_id>", "text": "cat.jpg"} add a file from the Drive
  {"do": "press", "key": "Enter"}                  Enter, Tab, Escape, Backspace, ArrowDown, ArrowUp, Space
  {"do": "back"}                                   the browser's back button
  {"do": "scroll", "target": "Results"}           scroll gently to it; no target: a screen further down
  {"do": "see", "target": "Thanks for your order"} words that must be on the page
  {"do": "gone", "target": "Loading…"}             words that must not be (waits for them to go)
  {"do": "address", "text": "/thank-you"}          the page's address must have this in it
  {"do": "shot"}                                   take a picture
A link that opens a new tab is followed in the same one. Something inside a frame of the page (a
card form) takes the "frame" look-at-page gives it; one of several sharing a name takes "nth".
A target is found as a person would look: a button first, then a link, a field, words; exact before
partial. Any step may carry a "note" (up to 600 characters): what a demo video tells whoever watches
it, e.g. "Drop the company file here. Nothing is saved until you press Upload & run." A demo video
captions only the steps with a note. "wait" (seconds, 0 to 30) is how long a demo stays on a step:
in place of its note's time, or its pause. Use look-at-page to see the names on a page before writing steps
for it. "checks" replaces them all, in order. Only public sites can be reached. A password goes in "hidden_values" and is
written {{password}} where a step types it: it is never shown again, in a run or a caption.
A site that needs signing in: give the suite "sign_ins" -- each a name and the check that signs in,
[{"name": "member", "check": "Sign in"}] -- and write that check once. A run signs in once, with
it, and every other check starts already signed in (cookies and the site's local storage), so the
checks need no sign-in steps of their own. A check starts as the first sign-in unless
save-browser-check says "as" another or "fresh" (signed out). The signed-in state lasts the run
alone and is never shown. A sign-in that fails is said once, and the checks needing it are not run.
TEXT)]
class SaveBrowserSuite extends BrowserCheckTool
{
    protected function arguments(JsonSchema $schema): array
    {
        $step = $schema->object([
            'do' => $schema->string()->enum(BrowserCheck::KINDS)->required(),
            'url' => $schema->string()->max(4000),
            'target' => $schema->string()->max(200),
            'text' => $schema->string()->max(2000),
            'key' => $schema->string()->max(30),
            'selector' => $schema->string()->max(1000),
            'nth' => $schema->integer()->min(1)->max(50),
            'frame' => $schema->string()->max(1000),
            'file' => $schema->string()->max(40),
            'note' => $schema->string()->max(600),
            'wait' => $schema->number()->min(0)->max(30),
        ]);

        return [
            'suite' => $schema->string()->max(200)->description('The suite to change, by ref_id or name. Leave out to make a new one.'),
            'title' => $schema->string()->max(200)->description('Its name, e.g. "Customer shop". Needed for a new one.'),
            'checks' => $schema->array()->items($schema->object([
                'title' => $schema->string()->max(200)->description('What it checks, e.g. "Logging in".')->required(),
                'size' => $schema->string()->enum(BrowserCheck::SIZES)->description('The screen it is done on, as a site lays itself out there: "desktop" (the default), "tablet" or "phone".'),
                'steps' => $schema->array()->items($step)->required(),
            ]))->description('Every check, in order: replaces the ones it had.'),
            'runs_by_itself' => $schema->object([
                'every' => $schema->string()->enum(BrowserSuite::EVERY)->required(),
                'at' => $schema->string()->description('"HH:MM", e.g. "09:00"; for every hour, its minutes.'),
                'zone' => $schema->string()->description('A time zone, e.g. "Asia/Bangkok".'),
            ])->description('Run it as a quick test on a timetable: off, hour, day or weekdays.'),
            'files_tickets' => $schema->boolean()->description('In a project: file a bug ticket when a check fails (once while it is open).'),
            'hidden_values' => $schema->array()->items($schema->object([
                'name' => $schema->string()->max(40)->required(),
                'value' => $schema->string()->max(500)->description('Left out or empty, it keeps the value it had.'),
            ]))->description('Values a step types without showing them, as {{name}}: replaces the list it had -- a name left out is gone, with its value.'),
            'sign_ins' => $schema->array()->items($schema->object([
                'name' => $schema->string()->max(40)->description('Who it signs in as, a short word: "member", "admin".')->required(),
                'check' => $schema->string()->max(200)->description('The check of this suite that signs in, by its name.')->required(),
            ]))->description('Sign in once a run, as each of these, and start every other check signed in -- as the first unless a check says otherwise. Replaces the list it had; [] for none.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $owner = $this->targetOwner($request, changes: true);
        $named = trim((string) $request->get('suite', ''));
        $suite = $named === ''
            ? (new BrowserSuite(['checks' => []]))->ownedBy($owner, $this->targetUser($request))
            : $this->suiteCalled($owner, $named);

        if ($named === '' && trim((string) $request->get('title', '')) === '') {
            return Response::error('A new suite needs a "title".');
        }

        $timetable = $request->get('runs_by_itself');
        $given = array_filter([
            'title' => $request->get('title'),
            'checks' => $request->get('checks'),
            'settings' => is_array($timetable) || $request->get('files_tickets') !== null
                ? [
                    'every' => $timetable['every'] ?? $suite->settings['every'] ?? 'off',
                    'at' => $timetable['at'] ?? $suite->settings['at'] ?? '09:00',
                    'zone' => $timetable['zone'] ?? $suite->settings['zone'] ?? null,
                    'tickets' => $request->get('files_tickets') ?? $suite->settings['tickets'] ?? false,
                ]
                : null,
            'values' => $request->get('hidden_values'),
            'sign_ins' => $request->get('sign_ins'),
        ], fn ($value) => $value !== null);

        // A sign-in names a check this suite has, written now or before
        foreach ($given['sign_ins'] ?? [] as $signIn) {
            $titles = array_column($given['checks'] ?? $suite->checks, 'title');

            if (is_array($signIn) && ! in_array($signIn['check'] ?? null, $titles, true)) {
                return Response::error('The sign-in "'.($signIn['name'] ?? '').'" names the check "'.($signIn['check'] ?? '').'", which this suite does not have. Its checks: '.(implode(', ', $titles) ?: 'none').'.');
            }
        }

        validator($given, BrowserSuite::rules(checkIds: false))->validate();
        $suite->take($given)->save();

        return Response::structured($this->suiteDescribed($suite->refresh()));
    }
}
