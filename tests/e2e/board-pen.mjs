// The vector pen: a path drawn a point at a time -- a click for a corner,
// a drag for a curve, back at the first point to close it -- kept as SVG
// path data; its points moved once it is double-clicked; and a fade put on
// it from the panel.
import { boardKept, open, runBoard, tinker } from './harness.mjs';

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

        // A board with a frame to draw on, made behind the scenes
        const ref = tinker(
            `App\\Mcp\\Servers\\UserServer::actingAs(${user})->tool(App\\Mcp\\Tools\\Boards\\CreateBoard::class, ['title' => 'Pen ${RUN}', 'items' => [
                ['id' => 'f', 'kind' => 'frame', 'text' => 'Art', 'x' => 0, 'y' => 0, 'width' => 800, 'height' => 450]]]);
             echo ${user}->boards()->where('title', 'Pen ${RUN}')->value('ref_id');`.replace(
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

        const path = async () =>
            Object.values(await boardKept(page, ref)).find(
                (item) => item.kind === 'path',
            );
        const at = async (x, y) => screenOf(x, y);

        // ------------------------------------------------------------ drawing
        await page.keyboard.press('l');

        // A corner, a curve pulled out of the second point, a corner, and
        // back to the first point to close it
        const first = await at(100, 100);
        await page.mouse.click(first.x, first.y);

        const second = await at(300, 100);
        const pulled = await at(380, 40);
        await page.mouse.move(second.x, second.y);
        await page.mouse.down();
        await page.mouse.move(pulled.x, pulled.y, { steps: 6 });
        await page.mouse.up();

        const third = await at(300, 300);
        await page.mouse.click(third.x, third.y);
        await page.mouse.click(first.x, first.y);

        await soon(
            'clicks and a drag make a closed path, kept as path data with a curve in it',
            async () => {
                const drawn = await path();

                return (
                    !!drawn &&
                    drawn.d.includes('C') &&
                    drawn.d.endsWith('Z') &&
                    drawn.fill !== 'transparent'
                );
            },
            JSON.stringify(await path()),
            SAVED,
        );
        const before = await path();
        check(
            'it sits where it was drawn',
            Math.abs(before.x - 100) < 8 &&
                before.y > 30 &&
                before.y < 101 &&
                before.height > 190,
            `${before.x},${before.y} ${before.width}x${before.height}`,
        );

        // ------------------------------------------------------------ editing
        const inside = await at(220, 200);
        await page.mouse.dblclick(inside.x, inside.y);
        await soon(
            'a double-click on it shows its points to move',
            async () =>
                (await page.locator('[data-pen]').getAttribute('data-pen')) ===
                'editing',
        );

        // Its third point dragged further out: the path grows to follow it
        const from = await at(300, 300);
        const to = await at(360, 380);
        await page.mouse.move(from.x, from.y);
        await page.mouse.down();
        await page.mouse.move(to.x, to.y, { steps: 6 });
        await page.mouse.up();
        await page.keyboard.press('Escape');

        await soon(
            'a point dragged once it is double-clicked moves, and the path with it',
            async () => {
                const moved = await path();

                return (
                    moved &&
                    moved.d !== before.d &&
                    moved.height > before.height + 50
                );
            },
            JSON.stringify(await path()),
            SAVED,
        );

        // ------------------------------------------------------------- a fade
        await page.mouse.click(inside.x, inside.y);
        await page.locator('[data-test="look-gradient"]').click();
        await soon(
            'a fade put on from the panel is kept on the path',
            async () => !!(await path())?.gradient,
            '',
            SAVED,
        );

        // Out from its middle, through three colours, and glowing
        await page.locator('[data-test="look-radial"]').click();
        await page.locator('[data-test="look-add-stop"]').click();
        await page.locator('[data-test="look-effects"]').click();
        await page.locator('[data-test="look-glow"]').click();
        await soon(
            'a radial fade through three colours and a glow are kept',
            async () => {
                const art = await path();

                return (
                    art?.gradientType === 'radial' &&
                    art.gradientStops?.length === 3 &&
                    !!art.glow
                );
            },
            JSON.stringify(await path()),
            SAVED,
        );
        if (process.env.PEN_SHOT) {
            await page.screenshot({ path: process.env.PEN_SHOT });
        }
    },
    { canvas: false },
);
