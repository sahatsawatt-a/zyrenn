// How a board is made to look good: a shape drawn in another design (a
// database as a card with its icon), a ready-made style put on what is
// chosen, a shadow under it, and the whole board restyled at once -- each
// undone in one step.
import { SHOTS, boardKept, open, runBoard, tinker } from './harness.mjs';

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

        // A small system, behind the scenes: the suite is about its looks
        const ref = tinker(
            `App\\Mcp\\Servers\\UserServer::actingAs(${user})->tool(App\\Mcp\\Tools\\Boards\\CreateBoard::class, ['title' => 'Styles ${RUN}', 'items' => [
                ['id' => 'app', 'kind' => 'rect', 'text' => 'App', 'x' => 0, 'y' => 0, 'fill' => '#bbf7d0', 'stroke' => '#16a34a'],
                ['id' => 'db', 'kind' => 'cylinder', 'text' => 'Orders', 'x' => 320, 'y' => 0, 'width' => 160, 'height' => 140],
                ['id' => 'log', 'kind' => 'rect', 'text' => 'Log', 'x' => 0, 'y' => 240],
                ['id' => 'link', 'kind' => 'arrow', 'from' => ['item' => 'app'], 'to' => ['item' => 'db']]]]);
             echo ${user}->boards()->where('title', 'Styles ${RUN}')->value('ref_id');`.replace(
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

        // ---------------------------------------------------------- a design
        await click(400, 70);
        await page.locator('[data-test="design-picker"]').waitFor();
        check(
            'a database offers its designs, the usual one chosen',
            (await page
                .locator('[data-test="design-classic"]')
                .getAttribute('aria-pressed')) === 'true' &&
                (await page
                    .locator('[data-test="design-picker"] button')
                    .count()) === 6,
        );

        await page.locator('[data-test="design-card"]').click();
        await soon(
            'picking one draws it so, and keeps it',
            async () => (await kept('db'))?.variant === 'card',
            '',
            // (what the app keeps comes a moment after the page has it)
            SAVED,
        );

        // ----------------------------------------------------------- a style
        await page.locator('[data-test="style-chip"]').click();
        await page.locator('[data-test="style-solid-indigo"]').click();
        await page.locator('[data-test="style-shadow"]').click();
        await soon(
            'a style puts its fill, line and words on, and a shadow under it',
            async () => {
                const db = await kept('db');

                return (
                    db?.fill === '#4f46e5' &&
                    db?.color === '#ffffff' &&
                    db?.shadow === true
                );
            },
            async () => JSON.stringify(await kept('db')),
            SAVED,
        );
        await page.keyboard.press('Escape');
        await page.screenshot({ path: `${SHOTS}/board-styles-card.png` });

        // ------------------------------------- designs changed over and over
        // A badge's disc must stay under its icon however often the design
        // is changed: white lines of the icon on the solid indigo disc
        await click(400, 70);

        for (const [style, design] of [
            ['soft-rose', 'card'],
            ['light-rose', 'badge'],
            ['bold-indigo', 'icon'],
            ['solid-indigo', 'badge'],
        ]) {
            await page.locator('[data-test="style-chip"]').click();
            await page.locator(`[data-test="style-${style}"]`).click();
            await page.keyboard.press('Escape');
            await page.locator(`[data-test="design-${design}"]`).click();
        }

        await click(280, 190);

        const whiteIn = async () => {
            const middle = await screenOf(400, 140 - 80);
            const shot = await page.screenshot({
                clip: {
                    x: middle.x - 12,
                    y: middle.y - 12,
                    width: 24,
                    height: 24,
                },
            });

            return page.evaluate(async (base64) => {
                const picture = new Image();
                picture.src = `data:image/png;base64,${base64}`;
                await picture.decode();
                const canvas = document.createElement('canvas');
                canvas.width = picture.width;
                canvas.height = picture.height;
                const context = canvas.getContext('2d');
                context.drawImage(picture, 0, 0);
                const { data } = context.getImageData(
                    0,
                    0,
                    canvas.width,
                    canvas.height,
                );
                let white = 0;

                for (let index = 0; index < data.length; index += 4) {
                    white +=
                        data[index] > 220 &&
                        data[index + 1] > 220 &&
                        data[index + 2] > 220
                            ? 1
                            : 0;
                }

                return white;
            }, shot.toString('base64'));
        };

        await soon(
            'after the design is changed over and over, the icon still shows on its disc',
            async () => (await whiteIn()) > 8,
            whiteIn,
        );

        // A fill picked by hand: the words and icon go back to what reads on it
        await click(400, 70);
        await page.locator('[data-test="fill-chip"]').click();
        await page.locator('[data-test="palette-swatch"]').nth(3).click();
        await page.keyboard.press('Escape');
        await soon(
            'a fill picked by hand drops the colour the style gave its words',
            async () => (await kept('db'))?.color === undefined,
            async () => JSON.stringify(await kept('db')),
            SAVED,
        );
        await page.locator('[data-test="design-card"]').click();
        await page.locator('[data-test="style-chip"]').click();
        await page.locator('[data-test="style-solid-indigo"]').click();
        await page.keyboard.press('Escape');
        await click(280, 190);

        // ------------------------------------------------ the board at once
        // (nothing chosen: a click on the bare board)
        await click(280, 190);
        await page.locator('[data-test="restyle-board"]').click();
        await page.locator('[data-test="style-board-soft"]').click();
        await soon(
            'restyling the board puts every shape in the set, colours kept together',
            async () => {
                const [app, db, log, link] = await Promise.all(
                    ['app', 'db', 'log', 'link'].map(kept),
                );

                return (
                    // The indigo database stays indigo, the green app takes
                    // a colour of the set, the blank log and the line its grey
                    db?.fill === '#e0e7ff' &&
                    app?.fill !== '#bbf7d0' &&
                    app?.fill !== db?.fill &&
                    log?.fill === '#f1f5f9' &&
                    link?.stroke === '#64748b'
                );
            },
            async () =>
                JSON.stringify(
                    await Promise.all(['app', 'db', 'log', 'link'].map(kept)),
                ),
            SAVED,
        );
        await page.screenshot({ path: `${SHOTS}/board-styles-soft.png` });

        // ------------------------------------------------------------- undo
        await page.keyboard.press('Escape');
        await page.locator('[data-test="undo"]').click();
        await soon(
            'one undo takes the whole restyle back',
            async () => (await kept('db'))?.fill === '#4f46e5',
            '',
            SAVED,
        );
    },
    { canvas: false },
);
