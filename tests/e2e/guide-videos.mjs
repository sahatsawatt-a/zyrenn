// The videos in the guide (public/images/guide/tour-*.webm): each "Show me"
// tour (resources/js/features/browser/tours.ts) played at the pace it is
// watched at, with its cursor and captions, and recorded -- so a topic shows
// how a thing is done even to someone not ready to have it done for them:
//
//     HEADED=0 node tests/e2e/guide-videos.mjs           # every tour
//     HEADED=0 node tests/e2e/guide-videos.mjs shapes    # only these
//
// Like the pictures (guide-shots.mjs), they are of guide@example.com, an
// account kept for this alone, and what a tour makes is taken away after.
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

process.env.E2E_EMAIL = 'guide@example.com';
const { open, runBoard, tinker } = await import('./harness.mjs');

const OUT = join(
    dirname(fileURLToPath(import.meta.url)),
    '../../public/images/guide',
);
mkdirSync(OUT, { recursive: true });

// Each tour and the topic it is on, read from the tours themselves
const TOURS = [
    ...readFileSync(
        join(
            dirname(fileURLToPath(import.meta.url)),
            '../../resources/js/features/browser/tours.ts',
        ),
        'utf8',
    ).matchAll(/id: '([^']+)',\s*topic: '([^']+)'/g),
].map(([, id, topic]) => ({ id, topic }));
const only = process.argv.slice(2);

const user = `App\\Models\\User::where('email', 'guide@example.com')->first()`;
const emptied = `$u = ${user}; foreach (['notes', 'boards', 'tables', 'workflows'] as $kind) { $u->{$kind}()->get()->each(fn ($one) => $one->delete()); }`;

tinker(
    `App\\Models\\User::firstOrCreate(['email' => 'guide@example.com'], ['name' => 'Alex Morgan', 'password' => Illuminate\\Support\\Facades\\Hash::make('password'), 'email_verified_at' => now()]); ${emptied}`,
);

// The size a video is watched at in a topic: wide enough for the sidebar
const SIZE = { width: 1120, height: 700 };

await runBoard(
    '/dashboard',
    async ({ browser, page, check, soon, afterwards }) => {
        afterwards(() => tinker(emptied));
        const base = new URL(page.url()).origin;
        const storageState = await page.context().storageState();
        const frames = join(tmpdir(), `guide-videos-${Date.now()}`);

        for (const { id, topic } of TOURS) {
            if (only.length && !only.includes(id)) {
                continue;
            }

            // A context of its own for each, so each is a video of its own
            const began = Date.now();
            const context = await browser.newContext({
                storageState,
                viewport: SIZE,
                recordVideo: { dir: frames, size: SIZE },
            });
            const watched = await context.newPage();
            const test = (name) => watched.locator(`[data-test="${name}"]`);

            await open(watched, `${base}/guide/${topic}`);
            const play = test(`tour-play-${id}`);
            await play.waitFor();
            // Where the video starts: the topic up a moment before the tour
            // begins, not the page coming up
            const from = Math.max(0, (Date.now() - began) / 1000 - 1);
            await play.click();
            await soon(
                `the "${id}" tour plays to its end, recorded`,
                async () => test('tour-done').isVisible(),
                async () =>
                    (await test('tour-problem').count())
                        ? test('tour-problem').textContent()
                        : watched.url(),
                { timeout: 120000, every: 250 },
            );
            const finished = await test('tour-done').isVisible();
            const video = watched.video();

            if (!finished) {
                // Not kept: a video of a tour that stopped short teaches nothing
                await watched.screenshot({
                    path: join(tmpdir(), `guide-video-${id}.png`),
                });
                await context.close();
                continue;
            }

            await test('tour-stop').click();
            await test('tour-done').waitFor({ state: 'detached' });
            await context.close();
            const whole = join(frames, `${id}-whole.webm`);
            await video.saveAs(whole);
            // From `from` on, as VP8 again: quick to make, small to send
            execFileSync(
                'ffmpeg',
                `-v error -y -ss ${from.toFixed(2)} -i ${whole} -c:v libvpx -b:v 700k -crf 12 -an -deadline realtime -cpu-used 6 -threads 4 ${join(OUT, `tour-${id}.webm`)}`.split(
                    ' ',
                ),
            );
            check(`tour-${id}.webm is kept`, true);
        }

        rmSync(frames, { recursive: true, force: true });
    },
    { width: SIZE.width, height: SIZE.height, canvas: false },
);
