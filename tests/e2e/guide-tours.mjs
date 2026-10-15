// The Guide's "Show me": a topic offers its tours, and each plays in the app
// with a cursor -- one that points out the sidebar, one that writes a note,
// one that puts a sticky on a board -- going on as the pages change under it.
import { SHOTS, open, runBoard, tinker } from './harness.mjs';

const email = process.env.E2E_EMAIL ?? 'test@example.com';
const user = `App\\Models\\User::where('email', '${email}')->first()`;

await runBoard(
    '/guide/getting-started',
    async ({ page, check, soon, afterwards }) => {
        const test = (name) => page.locator(`[data-test="${name}"]`);
        const base = new URL(page.url()).origin;

        afterwards(() =>
            tinker(
                `${user}->notes()->where('title', 'A note from the tour')->get()->each->delete(); ${user}->boards()->where('title', 'A board from the tour')->get()->each->delete(); echo 'ok';`,
            ),
        );

        // Tours are paced for someone to follow: quicker here, unless watched
        await page.evaluate(
            (pace) => sessionStorage.setItem('zyrenn:tour-pace', pace),
            process.env.HEADED === '0' ? '0.15' : '1',
        );
        // What a tour that did not get to the end says, or where it got to
        const whereItStopped = async () =>
            (await test('tour-problem').count())
                ? test('tour-problem').textContent()
                : page.url();

        await page.locator('[data-guide-tours]').waitFor();
        check(
            'a topic offers the tours there are for it',
            (await test('tour-play-around').isVisible()) &&
                (await test('tour-play-board').isVisible()) &&
                !(await test('tour-play-note').count()),
        );

        await test('tour-play-around').click();
        await test('tour-card').waitFor();
        await soon('a tour plays with a cursor, step by step', async () =>
            /^[2-9] of/.test((await test('tour-count').textContent()) ?? ''),
        );
        check(
            'the cursor is drawn',
            await page.locator('#e2e-cursor').isVisible(),
        );
        await soon(
            'and comes to an end',
            async () => test('tour-done').isVisible(),
            whereItStopped,
            { timeout: 45000 },
        );
        await test('tour-stop').click();
        await test('tour-card').waitFor({ state: 'detached' });

        await test('tour-play-board').click();
        await soon(
            'the board tour makes a board and puts a sticky on it',
            async () => test('tour-done').isVisible(),
            whereItStopped,
            { timeout: 45000 },
        );
        await page.screenshot({ path: `${SHOTS}/guide-tour-board.png` });
        await soon(
            'the sticky is on the board',
            async () =>
                tinker(
                    `echo collect(${user}->boards()->where('title', 'A board from the tour')->first()?->content['items'] ?? [])->where('kind', 'sticky')->count();`,
                ) === '1',
            '',
            { timeout: 10000 },
        );
        await test('tour-stop').click();

        await open(page, `${base}/guide/notes`);
        await test('tour-play-note').click();
        await soon(
            'from the Notes topic, a tour goes to Notes, makes a note and writes in it',
            async () => test('tour-done').isVisible(),
            whereItStopped,
            { timeout: 45000 },
        );
        check(
            'the note has its title and words',
            (await page.getByLabel('Note title').inputValue()) ===
                'A note from the tour' &&
                /keep themselves as you write/.test(
                    await page.locator('.tiptap').innerText(),
                ),
        );
        await page.screenshot({ path: `${SHOTS}/guide-tour-note.png` });
        await test('tour-stop').click();
    },
    { canvas: false },
);
