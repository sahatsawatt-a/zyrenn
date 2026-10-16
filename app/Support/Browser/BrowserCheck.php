<?php

namespace App\Support\Browser;

use App\Models\Drive\DriveFile;
use App\Models\Drive\DriveFolder;
use App\Models\Owner;
use App\Models\User;
use App\Support\Chrome\Chrome;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * A browser check: steps done one after the other in a real Chrome -- open a
 * page, click, type, press a key, expect to see something, take a picture --
 * by the printer's browser (docker/chrome/check.mjs), on public sites only.
 * Quick, for testing a site; or slowed, with a pointer, captions and a film,
 * for showing it.
 */
class BrowserCheck
{
    public const KINDS = ['open', 'click', 'hover', 'type', 'choose', 'upload', 'press', 'back', 'scroll', 'see', 'gone', 'address', 'shot', 'down', 'move', 'up', 'drag'];

    /**
     * What a press, move or drag may say beyond its thing, kept only when
     * given: where in the thing (`at`), what it is dropped `onto`, how it
     * moves (`motion`, `points`, `path`, `units`, `by`, `duration`), and how
     * it is held (`keys`, `button`, `clicks`, `pointer`).
     */
    private const HOLDING = [
        'down' => ['at', 'keys', 'button', 'clicks', 'pointer'],
        'move' => ['at', 'motion', 'points', 'path', 'units', 'by', 'duration'],
        'drag' => ['at', 'onto', 'motion', 'points', 'path', 'units', 'by', 'duration', 'keys', 'button', 'clicks', 'pointer'],
    ];

    /** A point: "0.2,0.3" -- fractions of a thing's box, or pixels. */
    private const POINT = '/^\s*-?\d*\.?\d+\s*,\s*-?\d*\.?\d+\s*$/';

    /** The biggest file a step adds to a page, in bytes. */
    public const MOST_FILE = 10 * 1024 * 1024;

    /** The screens a page is drawn on: a desktop's window, a tablet, a phone. */
    public const SIZES = ['desktop', 'tablet', 'phone'];

    /**
     * How fast a run goes: the pointer's glide to what it presses, each key
     * typed, and the pause after a step, in ms. "watch" is the pace settled
     * for watching the app's own browser suites; "steady" is a demo video's.
     */
    public const SPEEDS = [
        'quick' => ['glide' => 0, 'typing' => 0, 'pause' => 0],
        'watch' => ['glide' => 350, 'typing' => 45, 'pause' => 800],
        // A demo video's: a little slower than watching, to follow without pausing
        'steady' => ['glide' => 480, 'typing' => 65, 'pause' => 1150],
        'slow' => ['glide' => 600, 'typing' => 90, 'pause' => 1500],
    ];

    /**
     * The rules a list of steps keeps to, under `$prefix` (e.g. "steps"). A
     * suite is kept as it is written, half-written steps and all; only steps
     * about to run must be `complete`.
     *
     * @return array<string, mixed>
     */
    public static function stepRules(string $prefix = 'steps', bool $complete = true): array
    {
        $needed = fn (string $kinds) => $complete ? ["required_if:{$prefix}.*.do,{$kinds}"] : [];

        return [
            $prefix => ['required', 'array', 'min:1', 'max:30'],
            "{$prefix}.*.do" => ['required', Rule::in(self::KINDS)],
            "{$prefix}.*.id" => ['nullable', 'string', 'regex:'.self::STEP_ID],
            "{$prefix}.*.url" => [...$needed('open'), 'nullable', 'string', 'max:4000', ...($complete ? ['regex:#^(https?://|data:text/html)#i'] : [])],
            "{$prefix}.*.target" => [...$needed('click,hover,type,choose,upload,see,gone,down,drag'), 'nullable', 'string', 'max:200'],
            // Where in the thing it is pressed, or a move ends: fractions of its box, "0.5,0.5" its middle
            "{$prefix}.*.at" => ['nullable', 'string', 'regex:'.self::POINT],
            // A thing a drag is dropped on, by what it says
            "{$prefix}.*.onto" => ['nullable', 'string', 'max:200'],
            // Straight lines (through any points), a smooth curve through them, or SVG path data
            "{$prefix}.*.motion" => ['nullable', Rule::in(['straight', 'curve', 'path'])],
            // "0.2,0.3 0.4,0.5 500ms 0.6,0.3": fractions of the thing's box, or pixels from where it is with no thing; "500ms" pauses
            "{$prefix}.*.points" => ['nullable', 'string', 'max:2000', 'regex:/^[\d\s.,\-ms]*$/i'],
            // SVG path data (M L H V C S Q T A Z), 0,0 where it is pressed; each new M a new stroke; "500ms" between pieces pauses
            "{$prefix}.*.path" => ['nullable', 'string', 'max:4000', 'regex:/^[MLHVCSQTAZ\d\s.,eE+\-]*$/i'],
            // The path in pixels from where it is pressed, or in the thing's box (0,0 its top left, 1,1 its bottom right)
            "{$prefix}.*.units" => ['nullable', Rule::in(['px', 'box'])],
            // Moved this many pixels on: "120,-40"
            "{$prefix}.*.by" => ['nullable', 'string', 'regex:'.self::POINT],
            // How long the move takes, in seconds: a film's, at a person's pace
            "{$prefix}.*.duration" => ['nullable', 'numeric', 'between:0,20'],
            // Keys held while it is: "Shift", "Alt+Shift"
            "{$prefix}.*.keys" => ['nullable', 'string', 'max:40', 'regex:/^\s*((shift|alt|option|ctrl|control|meta|cmd|command)[\s+,]*)*$/i'],
            "{$prefix}.*.button" => ['nullable', Rule::in(['left', 'right', 'middle'])],
            // 2 for a double-click, held on the second
            "{$prefix}.*.clicks" => ['nullable', 'integer', 'between:1,2'],
            // A mouse, a pen or a finger
            "{$prefix}.*.pointer" => ['nullable', Rule::in(['mouse', 'pen', 'touch'])],
            // What is typed, the option chosen from a list, the file's name, or what the address has in it
            "{$prefix}.*.text" => [...$needed('choose,address'), 'nullable', 'string', 'max:2000'],
            // The Drive file a step adds to the page, by its ref_id
            "{$prefix}.*.file" => [...$needed('upload'), 'nullable', 'string', 'max:40'],
            // The frame of the page it is in -- a card form, an embedded sign-up
            "{$prefix}.*.frame" => ['nullable', 'string', 'max:1000'],
            "{$prefix}.*.key" => [...$needed('press'), 'nullable', 'string', 'regex:/^[A-Za-z0-9+]{1,30}$/'],
            // Where a thing with no words was picked from on the page, to find it by
            "{$prefix}.*.selector" => ['nullable', 'string', 'max:1000'],
            // Which of those sharing its name it is: the 3rd "Add to cart"
            "{$prefix}.*.nth" => ['nullable', 'integer', 'between:1,50'],
            // What its writer tells whoever watches the step: a demo video's caption
            "{$prefix}.*.note" => ['nullable', 'string', 'max:600'],
            // How long a demo video stays on it, in seconds: in place of its note's hold or its pause
            "{$prefix}.*.wait" => ['nullable', 'numeric', 'between:0,30'],
            // How long it waits for the site before it fails, in seconds: in place of its suite's step limit
            "{$prefix}.*.timeout" => ['nullable', 'integer', 'between:1,'.Limits::CEILING['step']],
        ];
    }

    /**
     * What is missing from a check before it can run, in words, or null when
     * nothing is: "Signing up, step 2: say what to click".
     *
     * @param  array{title: string, steps: list<array<string, mixed>>}  $check
     */
    public static function unready(array $check): ?string
    {
        foreach ($check['steps'] as $at => $step) {
            $kept = self::only($step);
            $missing = match (true) {
                $kept['do'] === 'open' && ! preg_match('#^(https?://[^/\s]+|data:text/html)#i', $kept['url']) => 'give the whole address of the page, starting https://',
                in_array($kept['do'], ['click', 'hover', 'see', 'gone'], true) && trim($kept['target']) === '' => match ($kept['do']) {
                    'click' => 'say what to click',
                    'hover' => 'say what to point at',
                    'see' => 'say what should be on the page',
                    default => 'say what should be gone from the page',
                },
                $kept['do'] === 'upload' && trim($kept['target']) === '' => 'say which file field to add it to',
                $kept['do'] === 'upload' && trim($kept['file']) === '' => 'choose the file to add from the Drive',
                $kept['do'] === 'address' && trim($kept['text']) === '' => 'say what the address should have in it',
                $kept['do'] === 'type' && trim($kept['target']) === '' => 'say which field to type into',
                $kept['do'] === 'choose' && trim($kept['target']) === '' => 'say which list to choose from',
                $kept['do'] === 'choose' && trim($kept['text']) === '' => 'say what to choose',
                $kept['do'] === 'down' && trim($kept['target']) === '' => 'say what to press down on',
                $kept['do'] === 'drag' && trim($kept['target']) === '' => 'say what to drag',
                in_array($kept['do'], ['move', 'drag'], true) && ($kept['motion'] ?? '') === 'path' && trim($kept['path'] ?? '') === '' => 'give the path to follow',
                $kept['do'] === 'move' && ($kept['motion'] ?? '') !== 'path' && trim($kept['target']) === '' && ! isset($kept['by']) && ! isset($kept['points']) => 'say where to move to',
                $kept['do'] === 'drag' && ($kept['motion'] ?? '') !== 'path' && ! isset($kept['onto']) && ! isset($kept['by']) && ! isset($kept['points']) => 'say where to drag it to',
                default => null,
            };

            if ($missing !== null) {
                return (trim($check['title']) ?: 'Untitled check').', step '.($at + 1).": {$missing}";
            }
        }

        return null;
    }

    /**
     * The rules for how a run goes: a speed, or one's own pace.
     *
     * @return array<string, mixed>
     */
    public static function settingRules(): array
    {
        return [
            'speed' => ['required', Rule::in([...array_keys(self::SPEEDS), 'custom'])],
            'pace.glide' => ['required_if:speed,custom', 'integer', 'between:0,2000'],
            'pace.typing' => ['required_if:speed,custom', 'integer', 'between:0,400'],
            'pace.pause' => ['required_if:speed,custom', 'integer', 'between:0,5000'],
            'captions' => ['boolean'],
            'record' => ['boolean'],
            'keep' => ['boolean'],
            // Every check in one browser, filmed as one
            'one_film' => ['boolean'],
        ];
    }

    /**
     * A step in a few words: what a caption says and a run lists.
     *
     * @param  array<string, mixed>  $step
     */
    public static function say(array $step): string
    {
        $target = (string) ($step['target'] ?? '');
        // One of several sharing a name: which
        $which = isset($step['nth']) ? 'the '.self::ordinal((int) $step['nth']).' ' : '';

        return match ($step['do']) {
            'open' => 'Open '.self::address((string) ($step['url'] ?? '')),
            'click' => "Click {$which}\"{$target}\"",
            'hover' => "Point at {$which}\"{$target}\"",
            'upload' => 'Add "'.Str::limit((string) ($step['text'] ?? 'a file'), 40)."\" to \"{$target}\"",
            'back' => 'Go back',
            'scroll' => $target === '' ? 'Scroll down' : "Scroll to {$which}\"{$target}\"",
            'gone' => "Expect not to see \"{$target}\"",
            'address' => 'Expect the address to have "'.Str::limit((string) ($step['text'] ?? ''), 60).'"',
            'type' => 'Type "'.Str::limit((string) ($step['text'] ?? ''), 40)."\" into \"{$target}\"",
            'choose' => 'Choose "'.Str::limit((string) ($step['text'] ?? ''), 40)."\" in \"{$target}\"",
            'press' => 'Press '.($step['key'] ?? 'Enter'),
            'see' => "Expect to see {$which}\"{$target}\"",
            'down' => (($step['clicks'] ?? '') === '2' ? 'Double-click and hold' : 'Press down on')." {$which}\"{$target}\"".self::holding($step),
            'move' => match (true) {
                ($step['motion'] ?? '') === 'path' => 'Move along a path'.($target === '' ? '' : " on \"{$target}\""),
                isset($step['by']) => 'Move by '.self::pixels((string) $step['by']),
                $target === '' => 'Move through '.self::count((string) ($step['points'] ?? ''), 'point'),
                isset($step['points']) => "Move across {$which}\"{$target}\"",
                default => "Move to {$which}\"{$target}\"",
            },
            'up' => 'Let go',
            'drag' => ucfirst((($step['clicks'] ?? '') === '2' ? 'Double-click and ' : '').match (true) {
                ($step['motion'] ?? '') === 'path' => "draw a path on {$which}\"{$target}\"",
                isset($step['onto']) => "drag {$which}\"{$target}\" onto \"{$step['onto']}\"",
                isset($step['by']) => "drag {$which}\"{$target}\" by ".self::pixels((string) $step['by']),
                default => "draw across {$which}\"{$target}\"",
            }).self::holding($step),
            default => 'Take a picture',
        };
    }

    /**
     * " holding Shift", " with the right button" -- or nothing.
     *
     * @param  array<string, mixed>  $step
     */
    private static function holding(array $step): string
    {
        $how = array_filter([
            isset($step['keys']) ? 'holding '.$step['keys'] : null,
            in_array($step['button'] ?? null, ['right', 'middle'], true) ? "with the {$step['button']} button" : null,
            in_array($step['pointer'] ?? null, ['pen', 'touch'], true) ? ($step['pointer'] === 'pen' ? 'with a pen' : 'with a finger') : null,
        ]);

        return $how === [] ? '' : ' '.implode(', ', $how);
    }

    /** "120,-40" -> "120, -40 px". */
    private static function pixels(string $by): string
    {
        return preg_replace('/\s*,\s*/', ', ', trim($by)).' px';
    }

    /** "3 points", "1 point" -- the pairs in a list of them. */
    private static function count(string $points, string $one): string
    {
        $n = preg_match_all('/-?[\d.]+\s*,\s*-?[\d.]+/', $points);

        return "{$n} {$one}".($n === 1 ? '' : 's');
    }

    /**
     * Runs one check's steps and answers with what each did: ok, took (ms),
     * error, a picture where one was asked for or the step failed (a data
     * URL), and the film of it, when asked for.
     *
     * A page not on the public internet is refused by the browser itself,
     * at whichever step reaches for it -- an address typed in, a redirect, or
     * something the page loads -- so it is not looked up here as well.
     *
     * @param  list<array<string, mixed>>  $steps
     * @param  array{glide: int, typing: int, pause: int}  $pace
     * @param  array<string, string>  $values  what each {{ name }} in a step stands for
     * @param  string|null  $token  what the browser knows it by, to stop it part way (stop())
     * @param  array{state?: array<string, mixed>|null, keep?: bool}  $signIn  signed in already: the
     *                                                                         browser state a sign-in left (`state`), and/or keep the
     *                                                                         state this one leaves (`keep`), answered as `state` when every
     *                                                                         step went through -- never kept anywhere but the run in hand
     * @param  array{step: int, check: int}  $limits  how long each step and the whole check may wait, in seconds
     * @param  bool  $cutWaits  filmed: the waiting on the site cut out of the film
     * @return array{steps: list<array<string, mixed>>, url: string, title: string, refused: list<string>, video: string|null, halted: bool, state: array<string, mixed>|null, log: list<array<string, mixed>>}
     */
    public static function run(array $steps, array $pace = self::SPEEDS['quick'], bool $captions = false, bool $record = false, array $values = [], string $size = 'desktop', ?string $token = null, array $signIn = [], array $limits = Limits::DEFAULT, bool $cutWaits = true): array
    {
        $steps = array_map(fn (array $step) => self::filled(self::tidy($step), $values), $steps);

        $response = Chrome::post('/check', ['steps' => $steps, 'pace' => $pace, 'captions' => $captions, 'record' => $record, 'size' => $size, 'token' => $token,
            'state' => $signIn['state'] ?? null, 'keepState' => $signIn['keep'] ?? false, 'limits' => self::inMs($limits), 'cutWaits' => $cutWaits],
            self::allowance($steps, $pace, $captions, $limits) + ($record ? self::CONVERTING : 0));

        /** @var array{steps: list<array<string, mixed>>, url: string, title: string, refused: list<string>, video?: string|null, halted?: bool, state?: array<string, mixed>|null} $answer */
        $answer = $response->json();

        return $answer + ['video' => null, 'halted' => false, 'state' => null, 'log' => []];
    }

    /**
     * Asks the browser to stop the check or film it knows by `$token` after
     * the step it is doing: it closes the page and answers with what was
     * done so far, `halted`. Answers whether it was running.
     */
    public static function stop(string $token): bool
    {
        return (bool) Chrome::post('/check/stop', ['token' => $token], 10)->json('running');
    }

    /**
     * Runs several checks one after another in one browser, as one film --
     * what one leaves, the next finds -- each check's name shown across the
     * page as it starts (with captions). Stops at the first step that fails.
     * Answers with what each check's steps did, in `chapters`.
     *
     * @param  list<array{title: string, steps: list<array<string, mixed>>, as?: string|null}>  $checks  each signed in `as` one of `$states`, or signed out
     * @param  array{glide: int, typing: int, pause: int}  $pace
     * @param  array<string, string>  $values
     * @param  string|null  $token  what the browser knows it by, to stop it part way (stop())
     * @param  array<string, array<string, mixed>>  $states  the browser state each sign-in left, by its name
     * @param  array{step: int, check: int}  $limits  how long each step and the whole film may wait, in seconds
     * @param  bool  $cutWaits  filmed: the waiting on the site cut out of the film
     * @return array{steps: list<array<string, mixed>>, chapters: list<list<array<string, mixed>>>, url: string, title: string, refused: list<string>, video: string|null, halted: bool, log: list<array<string, mixed>>}
     */
    public static function film(array $checks, array $pace, bool $captions, array $values = [], string $size = 'desktop', ?string $token = null, array $states = [], array $limits = Limits::DEFAULT, bool $cutWaits = true): array
    {
        $chapters = array_map(fn (array $check) => [
            'title' => $check['title'],
            'steps' => array_map(fn (array $step) => self::filled(self::tidy($step), $values), $check['steps']),
            'as' => $check['as'] ?? null,
        ], $checks);
        $all = array_merge(...array_column($chapters, 'steps'));
        // Each check's name is shown for a few seconds before it
        $titles = $captions && count($chapters) > 1 ? count($chapters) * 3 : 0;

        $response = Chrome::post('/check', ['chapters' => $chapters, 'pace' => $pace, 'captions' => $captions, 'record' => true, 'size' => $size, 'token' => $token, 'states' => (object) $states, 'limits' => self::inMs($limits), 'cutWaits' => $cutWaits],
            self::allowance($all, $pace, $captions, $limits) + $titles + self::CONVERTING);

        /** @var array{steps: list<array<string, mixed>>, chapters: list<list<array<string, mixed>>>, starts?: list<int>, url: string, title: string, refused: list<string>, video?: string|null, halted?: bool} $answer */
        $answer = $response->json();

        return $answer + ['video' => null, 'halted' => false, 'log' => []];
    }

    /**
     * Limits in seconds, as the browser takes them: in ms.
     *
     * @param  array{step: int, check: int}  $limits
     * @return array{step: int, check: int}
     */
    private static function inMs(array $limits): array
    {
        return ['step' => $limits['step'] * 1000, 'check' => $limits['check'] * 1000];
    }

    /** Seconds more for a filmed run: its film is made into MP4 after it ends. */
    private const CONVERTING = 90;

    /**
     * As long as the browser may take over `steps`, in seconds: its own
     * allowance, and the slowing on top -- each glide, key and pause, and
     * each note held or wait asked for.
     *
     * @param  list<array<string, mixed>>  $steps
     * @param  array{glide: int, typing: int, pause: int}  $pace
     * @param  array{step: int, check: int}  $limits
     */
    private static function allowance(array $steps, array $pace, bool $captions, array $limits = Limits::DEFAULT): int
    {
        $typed = array_sum(array_map(fn (array $step) => mb_strlen($step['text'] ?? ''), $steps));
        $held = $captions ? array_sum(array_map(fn (array $step) => match (true) {
            isset($step['wait']) => (int) ((float) $step['wait'] * 1000),
            isset($step['note']) => self::noteHold($step['note']),
            default => 0,
        }, $steps)) : 0;

        // The browser's own allowance, and a little more to answer in
        return $limits['check'] + 30 + (int) ceil((count($steps) * ($pace['glide'] + 150 + $pace['pause']) + $typed * $pace['typing'] + $held) / 1000);
    }

    /**
     * Goes through `steps` as quick as it can and answers with what is on the
     * page they lead to: each field, button, link and piece of writing by the
     * name a step would give it, where it is, and a picture of the page -- for
     * choosing what the next step is about.
     *
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, string>  $values
     * @return array{steps: list<array<string, mixed>>, url: string, title: string, refused: list<string>, things: list<array{kind: string, name: string, count: int, box: array{x: int, y: int, w: int, h: int}}>, below: int, shot: string|null, size: array{width: int, height: int}}
     */
    public static function look(array $steps, array $values = [], string $size = 'desktop'): array
    {
        // Pictures are no help in getting there
        $steps = array_values(array_filter(
            array_map(fn (array $step) => self::filled(self::tidy($step), $values), $steps),
            fn (array $step) => $step['do'] !== 'shot',
        ));

        return Chrome::post('/look', ['steps' => $steps, 'size' => $size])->json();
    }

    /**
     * A page kept open in the browser while a check is built by clicking
     * through it: `steps` done on it first, then each next step done once,
     * there (stepLive), rather than every step again for each new one. It is
     * closed after a few minutes of nothing.
     *
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, string>  $values
     * @return array<string, mixed> its id, what the steps did, and what is on the page
     */
    public static function openLive(array $steps, array $values = [], string $size = 'desktop'): array
    {
        return self::live('/live', [
            'steps' => array_map(fn (array $step) => self::filled(self::tidy($step), $values), $steps),
            'size' => $size,
        ]);
    }

    /**
     * One more step on a live page: what came of it, and what is on the page now.
     *
     * @param  array<string, mixed>  $step
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    public static function stepLive(string $id, array $step, array $values = []): array
    {
        return self::live("/live/{$id}/step", ['step' => self::filled(self::tidy($step), $values)]);
    }

    public static function closeLive(string $id): void
    {
        rescue(fn () => self::live("/live/{$id}/close", []), report: false);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function live(string $path, array $body): array
    {
        return Chrome::post($path, $body)->json();
    }

    /**
     * Puts a picture or a film the browser answered with (a data URL) in the
     * owner's Drive, and answers with where it is.
     *
     * @return array{name: string, url: string, ref_id: string}
     */
    public static function keep(string $dataUrl, string $name, Owner $owner, ?DriveFolder $folder, ?User $by): array
    {
        $bytes = base64_decode(Str::after($dataUrl, ','), true);

        // Named for what it is: a film comes back as MP4, or as the WebM it
        // was recorded as when that could not be made (docker/chrome, asMp4)
        $type = Str::between($dataUrl, 'data:', ';');
        $extension = ['video/mp4' => 'mp4', 'video/webm' => 'webm'][$type] ?? null;

        if ($extension !== null) {
            $name = preg_replace('/\.(mp4|webm)$/i', '', $name).'.'.$extension;
        }

        if ($bytes === false) {
            throw new RuntimeException("{$name} came back unreadable");
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'check');
        file_put_contents($path, $bytes);

        try {
            $file = DriveFile::keep($path, DriveFile::nameOnly($name), $owner, $folder, $by);
        } finally {
            @unlink($path);
        }

        return ['name' => $file->name, 'url' => $file->url(), 'ref_id' => $file->ref_id];
    }

    /**
     * How long a step's note stays on screen, at least (ms): long enough to
     * see and pause the film on, not to read it all -- 3 s to 8 s, the longer
     * the note the longer. The same sum is in docker/chrome/check.mjs.
     */
    public static function noteHold(string $note): int
    {
        return min(8000, max(3000, 1500 + 15 * mb_strlen($note)));
    }

    /** 1st, 2nd, 3rd, 4th, ... 11th, 12th, 13th, ... 21st. */
    private static function ordinal(int $number): string
    {
        $end = in_array($number % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$number % 10] ?? 'th');

        return $number.$end;
    }

    /** A page's address, short: its host and path, or "a page of its own". */
    private static function address(string $url): string
    {
        if (str_starts_with(strtolower($url), 'data:')) {
            return 'a page of its own';
        }

        $parts = parse_url($url);

        return rtrim(($parts['host'] ?? $url).($parts['path'] ?? ''), '/');
    }

    /** What a step's own id may be: a few letters and digits. */
    public const STEP_ID = '/^[A-Za-z0-9]{4,16}$/';

    /**
     * Only what the step's kind uses: a click has no address, a picture no
     * target -- and its note, wait and time limit, whatever its kind.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, string>
     */
    public static function only(array $step): array
    {
        $uses = match ($step['do']) {
            'open' => ['url'],
            'click', 'hover', 'see', 'gone', 'scroll', 'down', 'move', 'drag' => ['target'],
            'type', 'choose' => ['target', 'text'],
            'upload' => ['target', 'text', 'file'],
            'address' => ['text'],
            'press' => ['key'],
            default => [],
        };

        $kept = [
            // Its own lasting id, so its history follows it through edits
            ...(is_string($step['id'] ?? null) && preg_match(self::STEP_ID, $step['id']) ? ['id' => $step['id']] : []),
            'do' => (string) $step['do'],
        ];

        foreach ($uses as $field) {
            $kept[$field] = (string) ($step[$field] ?? '');
        }

        // How it presses, moves and is held: only what was given
        foreach (self::HOLDING[$kept['do']] ?? [] as $field) {
            $given = $step[$field] ?? null;

            if ((is_string($given) && trim($given) !== '') || is_int($given) || is_float($given)) {
                $kept[$field] = trim((string) $given);
            }
        }

        if (in_array($kept['do'], ['click', 'hover', 'type', 'choose', 'upload', 'see', 'gone', 'scroll', 'down', 'move', 'drag'], true) && is_string($step['frame'] ?? null) && $step['frame'] !== '') {
            $kept['frame'] = $step['frame'];
        }

        if (in_array($kept['do'], ['click', 'hover', 'type', 'choose', 'upload', 'see', 'scroll', 'down', 'move', 'drag'], true)) {
            if (is_string($step['selector'] ?? null) && $step['selector'] !== '') {
                $kept['selector'] = $step['selector'];
            }

            // Which of those sharing its name: kept with or without where it was
            if (is_numeric($step['nth'] ?? null) && (int) $step['nth'] > 1) {
                $kept['nth'] = (string) (int) $step['nth'];
            }
        }

        // Any step may be told about
        if (is_string($step['note'] ?? null) && trim($step['note']) !== '') {
            $kept['note'] = trim($step['note']);
        }

        // And how long a demo stays on it, to a tenth of a second
        if (is_numeric($step['wait'] ?? null)) {
            $kept['wait'] = (string) round(min(30, max(0, (float) $step['wait'])), 1);
        }

        // And how long it waits for the site, never past the most an admin allows
        if (is_numeric($step['timeout'] ?? null) && (int) $step['timeout'] > 0) {
            $kept['timeout'] = (string) min((int) $step['timeout'], Limits::most()['step']);
        }

        return $kept;
    }

    /**
     * A step with each {{ name }} in what it opens, types or goes by put in
     * as the value it stands for -- only on its way to the browser, so what
     * a step says (a caption, a run's list) never shows a hidden value.
     *
     * @param  array<string, string>  $step
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    public static function filled(array $step, array $values): array
    {
        // A file to add goes as itself: kept under "file:<ref_id>" (SuiteFiles)
        if (($step['do'] ?? null) === 'upload' && is_string($step['file'] ?? null)) {
            $file = json_decode($values["file:{$step['file']}"] ?? 'null', true);
            $step['file'] = is_array($file) ? $file : null;
        }

        if ($values === []) {
            return $step;
        }

        foreach (['url', 'target', 'text', 'onto'] as $field) {
            if (isset($step[$field])) {
                $step[$field] = (string) preg_replace_callback(
                    '/\{\{\s*([A-Za-z][\w-]*)\s*\}\}/',
                    fn (array $found) => $values[$found[1]] ?? $found[0],
                    $step[$field],
                );
            }
        }

        return $step;
    }

    /**
     * What the browser is sent: what the step uses, and what it says.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, string>
     */
    private static function tidy(array $step): array
    {
        // Its id is the suite's to follow it by: the browser has no use for it
        $kept = array_diff_key(self::only($step), ['id' => true]);

        return $kept + ['say' => self::say($kept)];
    }
}
