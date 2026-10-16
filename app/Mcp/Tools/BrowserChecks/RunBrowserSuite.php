<?php

namespace App\Mcp\Tools\BrowserChecks;

use App\Jobs\RunBrowserChecks;
use App\Mcp\Tools\BrowserCheckTool;
use App\Support\Browser\BrowserCheck;
use App\Support\Browser\SuiteRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
Run a suite of browser checks in the real browser: "test" for a quick test (a picture where one fails),
or "demo" for a demo video -- the pointer glides and types, a film of each check kept in the owner's
Drive, and each step's "note" (if it has one) captioned while it is done. A demo goes at the "steady"
pace unless "speed" says otherwise: "watch" is a little quicker, "slow" slower still. "check" runs
only the one of that name. With "wait" (seconds, up to 120) it answers once the run has ended or the
time is up, with how it went; otherwise at once, and get-browser-run follows it.
TEXT)]
class RunBrowserSuite extends BrowserCheckTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'suite' => $schema->string()->max(200)->description('The suite\'s ref_id or name.')->required(),
            'check' => $schema->string()->max(200)->description('Only the check of this name.'),
            'as' => $schema->string()->enum(['test', 'demo'])->default('test')->description('A quick test, or a filmed demo video.'),
            'one_film' => $schema->boolean()->description('For "demo": every check one after another in one browser -- what one leaves, the next finds -- filmed as one video, with each check\'s name shown as it starts. Stops at the first check that fails.'),
            'speed' => $schema->string()->enum(['watch', 'steady', 'slow'])->description('For "demo": how fast it goes. "steady" when left out.'),
            'cut_waits' => $schema->boolean()->description('For "demo": cut the waiting on the site (a page loading, words coming) out of the film, with a note saying how long was skipped. True when left out; false films it all as it happened.'),
            'try' => $schema->boolean()->description('Only a try, while a check is being written: a quick test, followed as any other, then kept nowhere -- not in the suite\'s runs or health, no tickets, nothing in the Drive -- and taken away an hour after it ends. Never filmed.'),
            'wait' => $schema->integer()->min(0)->max(120)->default(0)->description('Seconds to wait for it to end.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $owner = $this->targetOwner($request, changes: true);
        $suite = $this->suiteCalled($owner, trim((string) $request->get('suite')));
        $only = trim((string) $request->get('check', ''));
        $checks = array_values(array_filter($suite->checks, fn (array $check) => $only === '' || $check['title'] === $only));

        if ($checks === []) {
            return Response::error($only === '' ? 'The suite has no checks yet.' : "The suite has no check called \"{$only}\".");
        }

        foreach ($checks as $check) {
            if (($missing = BrowserCheck::unready($check)) !== null) {
                return Response::error("Before it can run -- {$missing}.");
            }
        }

        $demo = $request->get('as') === 'demo';
        $speed = $request->get('speed') ?? 'steady';
        $try = (bool) $request->get('try', false);

        if ($try && $demo) {
            return Response::error('A try is never filmed: leave out "as": "demo", or "try".');
        }

        if (! in_array($speed, ['watch', 'steady', 'slow'], true)) {
            return Response::error('"speed" is one of watch, steady or slow.');
        }
        $run = SuiteRun::start($suite, $this->targetUser($request), SuiteRun::settings(
            $demo ? $speed : 'quick',
            captions: $demo,
            record: $demo,
            checks: $only === '' ? [] : array_column($checks, 'id'),
            oneFilm: $demo && (bool) $request->get('one_film', false),
            cutWaits: (bool) $request->get('cut_waits', true),
        ), trial: $try);
        RunBrowserChecks::dispatch($run->id);

        $until = microtime(true) + min(120, max(0, (int) $request->get('wait', 0)));

        while (microtime(true) < $until && ! $run->refresh()->ended()) {
            usleep(500_000);
        }

        return Response::structured($this->runDescribed($run->refresh()));
    }
}
