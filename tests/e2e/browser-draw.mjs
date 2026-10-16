// Steps that hold the pointer, run for real in the server's browser: a
// stroke drawn through points, one pressed, moved and let go with Escape
// pressed while it is held (taken back), two strokes in one path, a drag
// holding Shift with a pen, with the right button, after a double-click and
// with a finger, a path in the pad's own box, and a card dropped on a column.
// The page is a pad of its own (a data: URL) that says what it was given, so
// nothing reaches the internet; each "see" checks what it says.
import { runBoard, tinker, until } from './harness.mjs';

const RUN = Date.now();
const email = process.env.E2E_EMAIL ?? 'test@example.com';
const user = `App\\Models\\User::where('email', '${email}')->first()`;

// A pad keeping each stroke of more than a few points, and what drew it; a
// stroke under way when Escape is pressed is taken back. A card dropped on
// "Done" says so
const pad = encodeURIComponent(
    `<body style="font:16px system-ui;margin:20px">
<div id=pad style="width:600px;height:240px;border:2px solid #333;touch-action:none;position:relative"><svg id=ink width=600 height=240 style="position:absolute;left:0;top:0;pointer-events:none"></svg></div>
<div style="display:flex;gap:20px;margin-top:20px"><div id=card style="width:100px;height:60px;background:#fd0;touch-action:none">Card</div><div id=done style="width:160px;height:100px;border:2px dashed #999">Done</div></div>
<p id=out>Nothing yet</p>
<script>
let kept = 0, cancelled = 0, now = null, last = '', drop = '', carrying = false;
const say = () => out.textContent = kept + ' kept, ' + cancelled + ' cancelled' + (last ? ', last ' + last : '') + (drop ? ', ' + drop : '');
pad.addEventListener('pointerdown', (e) => { now = { pts: [[e.offsetX, e.offsetY]], type: e.pointerType, right: e.button === 2, shift: e.shiftKey, double: false }; pad.setPointerCapture(e.pointerId); });
pad.addEventListener('mousedown', (e) => { if (now && e.detail > 1) now.double = true; });
pad.addEventListener('pointermove', (e) => { if (now) now.pts.push([e.offsetX, e.offsetY]); });
pad.addEventListener('contextmenu', (e) => e.preventDefault());
addEventListener('keydown', (e) => { if (e.key === 'Escape' && now) { now = null; cancelled++; say(); } });
pad.addEventListener('pointerup', () => {
  if (!now) return;
  if (now.pts.length > 3) {
    kept++;
    last = [now.type, now.right ? 'right' : 'left', now.shift ? 'shift' : '', now.double ? 'double' : ''].filter(Boolean).join(' ');
    const line = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
    line.setAttribute('points', now.pts.join(' '));
    line.setAttribute('fill', 'none');
    line.setAttribute('stroke', '#c00');
    ink.append(line);
  }
  now = null;
  say();
});
card.addEventListener('pointerdown', () => { carrying = true; });
addEventListener('pointerup', (e) => { if (carrying && document.elementFromPoint(e.clientX, e.clientY)?.id === 'done') drop = 'dropped on Done'; carrying = false; say(); });
</script></body>`,
).replace(/'/g, '%27'); // (in a PHP string below)

const on = { target: 'the pad', selector: '#pad' };
const STEPS = [
    { do: 'open', url: `data:text/html,${pad}` },
    // Through points, smoothly
    {
        do: 'drag',
        ...on,
        at: '0.05,0.5',
        motion: 'curve',
        points: '0.2,0.2 0.35,0.8 0.5,0.3',
    },
    { do: 'see', target: '1 kept, 0 cancelled, last mouse left' },
    // Pressed, moved, Escape while held, moved on, let go: taken back
    { do: 'down', ...on, at: '0.6,0.2' },
    { do: 'move', ...on, points: '0.7,0.4 0.8,0.6' },
    { do: 'press', key: 'Escape' },
    { do: 'move', by: '40,0' },
    { do: 'up' },
    { do: 'see', target: '1 kept, 1 cancelled' },
    // Two strokes in one path: each M lifts and presses again
    {
        do: 'drag',
        ...on,
        at: '0.1,0.2',
        motion: 'path',
        path: 'M 0 0 l 80 0 M 0 40 l 80 0',
    },
    { do: 'see', target: '3 kept, 1 cancelled' },
    // Holding Shift, with a pen
    {
        do: 'drag',
        ...on,
        at: '0.4,0.5',
        by: '100,0',
        keys: 'Shift',
        pointer: 'pen',
    },
    { do: 'see', target: 'last pen left shift' },
    { do: 'drag', ...on, at: '0.4,0.7', by: '100,0', button: 'right' },
    { do: 'see', target: 'last mouse right' },
    { do: 'drag', ...on, at: '0.4,0.9', by: '100,0', clicks: 2 },
    { do: 'see', target: 'last mouse left double' },
    { do: 'drag', ...on, at: '0.8,0.3', by: '0,80', pointer: 'touch' },
    { do: 'see', target: 'last touch left' },
    // A path in the pad's own box: a corner to corner, wherever it is
    {
        do: 'drag',
        ...on,
        motion: 'path',
        units: 'box',
        path: 'M 0.9 0.1 L 0.95 0.9',
    },
    { do: 'see', target: '8 kept, 1 cancelled' },
    { do: 'drag', target: 'Card', onto: 'Done' },
    { do: 'see', target: 'dropped on Done' },
];

await runBoard(
    '/checks',
    async ({ check, afterwards }) => {
        const php = (value) =>
            JSON.stringify(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");

        const ref = tinker(
            `$u = ${user};
             $s = (new App\\Models\\Browser\\BrowserSuite(['title' => 'Draw ${RUN}', 'checks' => []]))->ownedBy($u, $u);
             $s->take(['checks' => [['title' => 'Draw', 'steps' => json_decode('${php(STEPS)}', true)]]])->save();
             echo $s->ref_id;`.replace(/\n\s*/g, ' '),
        );

        afterwards(() =>
            tinker(
                `App\\Models\\Browser\\BrowserSuite::where('ref_id', '${ref}')->first()?->delete(); echo 'ok';`,
            ),
        );

        const run = tinker(
            `$s = App\\Models\\Browser\\BrowserSuite::where('ref_id', '${ref}')->first(); $r = App\\Support\\Browser\\SuiteRun::start($s, $s->creator, App\\Support\\Browser\\SuiteRun::settings('quick')); App\\Jobs\\RunBrowserChecks::dispatch($r->id); echo $r->ref_id;`,
        );
        const state = () =>
            tinker(
                `echo App\\Models\\Browser\\BrowserRun::where('ref_id', '${run}')->value('state');`,
            );
        await until(async () => ['passed', 'failed'].includes(state()), {
            timeout: 90000,
            every: 1000,
        });

        // Which step, if any, did not go as it should
        const failed = tinker(
            `$r = App\\Models\\Browser\\BrowserRun::where('ref_id', '${run}')->first(); foreach ($r->results as $c) foreach ($c['steps'] ?? [] as $i => $st) if (! ($st['ok'] ?? false)) { echo ($i + 1).': '.($st['say'] ?? '').' -- '.($st['error'] ?? ''); break 2; }`,
        );
        const done = Number(
            tinker(
                `echo collect(App\\Models\\Browser\\BrowserRun::where('ref_id', '${run}')->first()->results[0]['steps'] ?? [])->where('ok', true)->count();`,
            ),
        );
        check(
            `every step went through${failed ? ` (failed at ${failed})` : ''}`,
            state() === 'passed' && done === STEPS.length,
            `${done} of ${STEPS.length}`,
        );
    },
    { canvas: false },
);
