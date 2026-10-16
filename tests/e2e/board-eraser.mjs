// The eraser: a drag across the board takes away the pencil strokes it
// passes over -- faded as it goes, gone together when it is let go, back in
// one undo -- and never a shape, a vector-pen path or a stroke it missed.
import { boardKept, open, runBoard, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const SAVED = { timeout: 15000 };
const WHO = process.env.E2E_EMAIL ?? 'test@example.com';

await runBoard(
    '/boards',
    async (b) => {
        const { page, check, soon, afterwards, screenOf } = b;
        const base = new URL(page.url()).origin;
        const user = `App\\Models\\User::where('email', '${WHO}')->first()`;

        // A box and a vector-pen line, made behind the scenes; the pencil
        // strokes are drawn by hand below -- only a hand draws ink
        const ref = tinker(
            `App\\Mcp\\Servers\\UserServer::actingAs(${user})->tool(App\\Mcp\\Tools\\Boards\\CreateBoard::class, ['title' => 'Eraser ${RUN}', 'items' => [
                ['id' => 'box', 'kind' => 'rect', 'x' => 220, 'y' => 60, 'width' => 60, 'height' => 180],
                ['id' => 'pen', 'kind' => 'path', 'd' => 'M180 60 L184 460']]]);
             echo ${user}->boards()->where('title', 'Eraser ${RUN}')->value('ref_id');`.replace(
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

        const drag = async (from, to, steps = 8) => {
            const a = await screenOf(from.x, from.y);
            const z = await screenOf(to.x, to.y);
            await page.mouse.move(a.x, a.y);
            await page.mouse.down();
            await page.mouse.move(z.x, z.y, { steps });
        };

        // Three strokes, left to right, at y 100, 200 and 400
        await page.keyboard.press('d');

        for (const y of [100, 200, 400]) {
            await drag({ x: 100, y }, { x: 400, y });
            await page.mouse.up();
        }

        const strokes = async () =>
            Object.values(await boardKept(page, ref))
                .filter((item) => item.kind === 'draw')
                .sort((a, b) => a.y - b.y)
                .map((item) => item.id);
        await soon(
            'three strokes drawn by hand',
            async () => (await strokes()).length === 3,
            strokes,
            SAVED,
        );
        const [one, two, far] = await strokes();
        const named = { [one]: 'one', [two]: 'two', [far]: 'far' };
        const kept = async () =>
            Object.keys(await boardKept(page, ref))
                .map((id) => named[id] ?? id)
                .sort((a, b) => a.localeCompare(b));
        const opacityOf = (id) =>
            page.evaluate(
                (id) => window.Konva.stages[0].findOne(`#${id}`)?.opacity(),
                id,
            );

        // Chosen by its key, it says so on the board
        await page.keyboard.press('x');
        await soon(
            'X chooses the eraser',
            async () =>
                (await page
                    .locator('[data-tool]')
                    .first()
                    .getAttribute('data-tool')) === 'eraser',
        );

        // Down from above the first stroke to below the second, through the
        // box and the pen line
        await drag({ x: 250, y: 70 }, { x: 250, y: 240 }, 10);
        await soon(
            'the strokes it has passed over fade while it goes on',
            async () =>
                (await opacityOf(one)) < 0.5 &&
                (await opacityOf(two)) < 0.5 &&
                (await opacityOf(far)) === 1 &&
                (await opacityOf('box')) === 1,
        );
        await page.mouse.up();

        await soon(
            'let go, the two strokes are gone -- the box, the pen line and the one it missed stay',
            async () =>
                JSON.stringify(await kept()) ===
                JSON.stringify(['box', 'far', 'pen']),
            kept,
            SAVED,
        );

        // One undo brings both back
        await page.keyboard.press('Control+z');
        await soon(
            'one undo brings both back',
            async () =>
                JSON.stringify(await kept()) ===
                JSON.stringify(['box', 'far', 'one', 'pen', 'two']),
            kept,
            SAVED,
        );

        // Escape part way: nothing is taken
        await drag({ x: 250, y: 380 }, { x: 250, y: 420 }, 6);
        await soon(
            'the third fades under it',
            async () => (await opacityOf(far)) < 0.5,
        );
        await page.keyboard.press('Escape');
        await page.mouse.up();
        await soon(
            'Escape part way takes nothing',
            async () => (await opacityOf(far)) === 1,
        );
        check('and the board keeps all five', (await kept()).length === 5);

        // A pencil stroke let go of with Escape part way is never put down
        await page.keyboard.press('d');
        await drag({ x: 100, y: 500 }, { x: 250, y: 500 }, 6);
        await page.keyboard.press('Escape');
        // (still held, as a hand goes on moving after Escape)
        const on = await screenOf(330, 500);
        await page.mouse.move(on.x, on.y, { steps: 4 });
        await page.mouse.up();
        await soon(
            'Escape mid-stroke draws nothing',
            async () => (await kept()).length === 5,
            kept,
        );
    },
    { canvas: false },
);
