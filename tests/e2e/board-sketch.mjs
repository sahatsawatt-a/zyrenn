// The hand-drawn look: a frame and everything on it sketched and written by
// hand in one click, drawn the same after a reload (the wobble is seeded by
// each item's id), made rougher, hatched, a Thai label in the Thai hand, and
// the whole board made crisp again -- undone in one step.
import {
    SHOTS,
    boardKept,
    open,
    reopen,
    runBoard,
    steady,
    tinker,
} from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
// How long what the app keeps may take to catch up with the page
const SAVED = { timeout: 15000 };
const WHO = process.env.E2E_EMAIL ?? 'test@example.com';

await runBoard(
    '/boards',
    async (b) => {
        const { page, check, soon, afterwards, screenOf } = b;
        const base = new URL(page.url()).origin;
        const user = `App\\Models\\User::where('email', '${WHO}')->first()`;

        // A login screen on a slide, and a box off it, behind the scenes:
        // the suite is about how they are drawn
        const ref = tinker(
            `App\\Mcp\\Servers\\UserServer::actingAs(${user})->tool(App\\Mcp\\Tools\\Boards\\CreateBoard::class, ['title' => 'Sketch ${RUN}', 'items' => [
                ['id' => 'screen', 'kind' => 'frame', 'text' => 'Login', 'x' => 0, 'y' => 0, 'width' => 960, 'height' => 540],
                ['id' => 'title', 'kind' => 'text', 'text' => 'Welcome back', 'x' => 80, 'y' => 40],
                ['id' => 'box', 'kind' => 'rect', 'text' => 'Sign in', 'x' => 80, 'y' => 140, 'width' => 220, 'height' => 90, 'fill' => '#bfdbfe', 'stroke' => '#1d4ed8'],
                ['id' => 'db', 'kind' => 'cylinder', 'text' => 'Users', 'x' => 560, 'y' => 120, 'width' => 160, 'height' => 150],
                ['id' => 'thai', 'kind' => 'rect', 'text' => 'ปุ่มเข้าสู่ระบบ', 'x' => 80, 'y' => 320, 'width' => 260, 'height' => 90],
                ['id' => 'link', 'kind' => 'arrow', 'from' => ['item' => 'box'], 'to' => ['item' => 'db'], 'text' => 'checks'],
                ['id' => 'off', 'kind' => 'rect', 'text' => 'Elsewhere', 'x' => 1200, 'y' => 0]]]);
             echo ${user}->boards()->where('title', 'Sketch ${RUN}')->value('ref_id');`.replace(
                /\n\s*/g,
                ' ',
            ),
        );

        afterwards(() =>
            tinker(
                `App\\Models\\Board\\Board::where('ref_id', '${ref}')->first()?->delete(); echo 'ok';`,
            ),
        );

        await open(page, `${base}/boards/${ref}`);
        await b.ready();

        const kept = async (id) => (await boardKept(page, ref))[id];
        const click = async (x, y) => {
            const point = await screenOf(x, y);

            await page.mouse.click(point.x, point.y);
        };
        // What the box looks like on screen, its edge and a little round it
        const boxShot = async () => {
            const from = await screenOf(60, 120);
            const to = await screenOf(320, 250);

            return page.screenshot({
                clip: {
                    x: from.x,
                    y: from.y,
                    width: to.x - from.x,
                    height: to.y - from.y,
                },
            });
        };
        const bare = () => click(1100, 700);
        // Each item's roughness, fill and font, short enough to read
        const looks = async () =>
            Object.values(await boardKept(page, ref))
                .map(
                    (item) =>
                        `${item.id}:${item.sketch ?? '-'}/${item.hatch ?? '-'}/${item.fontFamily}`,
                )
                .join(' ');

        await bare();
        const crisp = await boxShot();

        // ----------------------------------------- the slide in one click
        await click(800, 470);
        await page.locator('[data-test="sketch-look"]').click();
        await soon(
            'the sketch look goes over the frame and all that is on it, not off it',
            async () => {
                const items = await boardKept(page, ref);

                return (
                    items.screen?.sketch === 1 &&
                    items.box?.sketch === 1 &&
                    items.db?.sketch === 1 &&
                    items.link?.sketch === 1 &&
                    ['screen', 'title', 'box', 'db', 'thai', 'link'].every(
                        (id) => items[id]?.fontFamily === 'hand',
                    ) &&
                    !items.off?.sketch &&
                    items.off?.fontFamily === 'sans'
                );
            },
            looks,
            SAVED,
        );
        await soon(
            'the handwritten fonts come with the app, the Thai hand too',
            () =>
                page.evaluate(
                    () =>
                        document.fonts.check('16px Caveat', 'Aa') &&
                        document.fonts.check('bold 16px Caveat', 'Aa') &&
                        document.fonts.check('16px Mali', 'ก'),
                ),
        );
        check(
            'and nothing of them is fetched from a font CDN',
            await page.evaluate(() =>
                performance
                    .getEntriesByType('resource')
                    .filter((entry) => /woff2?(\?|$)/.test(entry.name))
                    .every(
                        (entry) =>
                            new URL(entry.name).origin === location.origin,
                    ),
            ),
        );

        await bare();
        // Drawn once the board has settled on its new look
        const little = await steady(async () =>
            (await boxShot()).toString('base64'),
        );
        check(
            'the box is drawn by hand now',
            little !== crisp.toString('base64'),
        );
        await page.screenshot({ path: `${SHOTS}/board-sketch-little.png` });

        // -------------------------------- the same wobble after a reload
        await reopen(page);
        await b.ready();
        await soon(
            'after a reload the wobble is the very same, seeded by the id',
            async () => (await boxShot()).toString('base64') === little,
        );

        // ------------------------------------- rougher, and hatched across
        await click(190, 185);
        await page.locator('[data-test="sketch-2"]').click();
        await page.locator('[data-test="hatch-hachure"]').click();
        await soon(
            '"A lot" and lines across are kept',
            async () => {
                const box = await kept('box');

                return box?.sketch === 2 && box?.hatch === 'hachure';
            },
            looks,
            SAVED,
        );
        await bare();
        const rough = await steady(async () =>
            (await boxShot()).toString('base64'),
        );
        check('and drawn rougher', rough !== little);

        // ----------------------------------------------------- a marker
        await click(190, 185);
        await page.locator('[data-test="prop-font"]').click();
        await page.locator('[data-test="font-marker"]').click();
        await soon(
            'the marker is one of the fonts to pick',
            async () => (await kept('box'))?.fontFamily === 'marker',
            '',
            SAVED,
        );
        await bare();
        await page.screenshot({ path: `${SHOTS}/board-sketch-rough.png` });

        // ------------------------------------------- the board, crisp again
        await page.locator('[data-test="sketch-board"]').click();
        await soon(
            'the whole board is crisp again, in its plain type',
            async () => {
                const items = await boardKept(page, ref);

                return Object.values(items).every(
                    (item) =>
                        !item.sketch &&
                        !['hand', 'marker'].includes(item.fontFamily),
                );
            },
            looks,
            SAVED,
        );
        await soon(
            'and drawn as it was before',
            async () =>
                (await boxShot()).toString('base64') !== rough &&
                (await kept('box'))?.hatch === 'hachure',
        );

        await page.locator('[data-test="undo"]').click();
        await soon(
            'one undo brings the whole sketch back',
            async () => {
                const items = await boardKept(page, ref);

                return (
                    items.box?.sketch === 2 &&
                    items.box?.fontFamily === 'marker' &&
                    items.screen?.sketch === 1
                );
            },
            '',
            SAVED,
        );
    },
    { canvas: false },
);
