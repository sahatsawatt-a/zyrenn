// Pencil ink: a stylus drawing heavier where it presses harder -- kept, and
// drawn so after a reload -- and ink an assistant drew over MCP (along path
// data, heavy, faint, unsteady) shown as a hand's, and taken by the eraser.
import { boardKept, open, reopen, runBoard, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const SAVED = { timeout: 15000 };
const WHO = process.env.E2E_EMAIL ?? 'test@example.com';
const SHOTS = process.env.E2E_SHOTS ?? '/tmp/zyrenn-e2e';

await runBoard(
    '/boards',
    async (b) => {
        const { page, check, soon, afterwards, screenOf } = b;
        const base = new URL(page.url()).origin;
        const user = `App\\Models\\User::where('email', '${WHO}')->first()`;

        // Drawn over MCP: a circle, a sketchy wave, a heavy faint highlight
        const ref = tinker(
            `App\\Mcp\\Servers\\UserServer::actingAs(${user})->tool(App\\Mcp\\Tools\\Boards\\CreateBoard::class, ['title' => 'Ink ${RUN}', 'items' => [
                ['id' => 'ring', 'kind' => 'draw', 'd' => 'M150 100 A50 50 0 1 1 149.9 100', 'stroke' => '#2563eb'],
                ['id' => 'wave', 'kind' => 'draw', 'd' => 'M300 150 Q325 120 350 150 T400 150 T450 150', 'rough' => 1.5],
                ['id' => 'mark', 'kind' => 'draw', 'd' => 'M100 300 L450 300', 'lineWidth' => 14, 'opacity' => 0.35, 'stroke' => '#facc15']]]);
             echo ${user}->boards()->where('title', 'Ink ${RUN}')->value('ref_id');`.replace(
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

        const node = (id) =>
            page.evaluate((id) => {
                const found = window.Konva.stages[0].findOne(`#${id}`);
                const box = found?.getClientRect({
                    relativeTo: found.getStage(),
                });

                return found
                    ? {
                          width: box.width,
                          height: box.height,
                          opacity: found.opacity(),
                      }
                    : null;
            }, id);

        await soon(
            'a circle drawn over MCP is drawn round',
            async () => {
                const ring = await node('ring');

                return (
                    ring &&
                    Math.abs(ring.width - ring.height) < 6 &&
                    ring.width > 90
                );
            },
            () => node('ring'),
        );
        check(
            'a faint highlight is faint',
            (await node('mark'))?.opacity === 0.35,
        );

        // ------------------------------------------------- a stylus pressing
        await page.keyboard.press('d');
        const canvas = page.locator('.konvajs-content canvas').last();
        const box = await canvas.boundingBox();
        const at = async (x, y) => {
            const point = await screenOf(x, y);

            return { clientX: point.x, clientY: point.y };
        };
        const pen = (type, where, pressure) =>
            canvas.dispatchEvent(type, {
                ...where,
                pointerType: 'pen',
                pointerId: 7,
                isPrimary: true,
                pressure,
                buttons: type === 'pointerup' ? 0 : 1,
                bubbles: true,
            });

        // Light, then hard, then light again, along y 220
        await pen('pointerdown', await at(100, 220), 0.1);

        for (const [x, pressure] of [
            [150, 0.2],
            [200, 0.5],
            [250, 0.9],
            [300, 0.9],
            [350, 0.5],
            [400, 0.15],
        ]) {
            await pen('pointermove', await at(x, 220), pressure);
        }

        await pen('pointerup', await at(400, 220), 0);
        check('the board was there to draw on', box !== null);

        const pressedStroke = async () =>
            Object.values(await boardKept(page, ref)).find(
                (item) => item.kind === 'draw' && item.pressures?.length,
            );
        await soon(
            'a stylus stroke keeps how hard each point was pressed',
            async () => {
                const stroke = await pressedStroke();

                return (
                    stroke &&
                    stroke.pressures.length === stroke.points.length / 2 &&
                    Math.max(...stroke.pressures) >= 0.85 &&
                    Math.min(...stroke.pressures) <= 0.15
                );
            },
            pressedStroke,
            SAVED,
        );

        // Heavier in the middle than at its ends, as it was pressed
        const widthAcross = (x) =>
            page.evaluate(
                ([x, y]) => {
                    // The ink as drawn: coloured pixels across it, on its layer
                    const stage = window.Konva.stages[0];
                    const ink = stage
                        .find('Shape')
                        .find(
                            (shape) =>
                                shape.getClassName() === 'Shape' &&
                                shape.getParent()?.id()?.startsWith('i'),
                        );
                    const layer = ink.getLayer();
                    const ratio = layer.getCanvas().getPixelRatio();
                    const transform = stage.getAbsoluteTransform();
                    const top = transform.point({ x, y: y - 20 });
                    const bottom = transform.point({ x, y: y + 20 });
                    const pixels = layer
                        .getNativeCanvasElement()
                        .getContext('2d')
                        .getImageData(
                            Math.round(top.x * ratio),
                            Math.round(top.y * ratio),
                            1,
                            Math.round((bottom.y - top.y) * ratio),
                        ).data;
                    let inked = 0;

                    for (let at = 3; at < pixels.length; at += 4) {
                        if (pixels[at] > 128) inked++;
                    }

                    return inked / ratio;
                },
                [x, 220],
            );
        await page.screenshot({ path: `${SHOTS}/board-ink.png` });

        const ends = await widthAcross(125);
        const middle = await widthAcross(275);
        check(
            'drawn heavier where pressed harder',
            middle > ends,
            `${ends} at the light end, ${middle} in the middle`,
        );

        // After a reload, still pressed
        await reopen(page);
        await b.ready();
        check(
            'and drawn so after a reload',
            (await widthAcross(275)) > (await widthAcross(125)),
        );

        // ------------------------------------------------- the eraser takes ink from MCP
        await page.keyboard.press('x');
        const top = await screenOf(430, 120);
        const bottom = await screenOf(430, 320);
        await page.mouse.move(top.x, top.y);
        await page.mouse.down();
        await page.mouse.move(bottom.x, bottom.y, { steps: 10 });
        await page.mouse.up();
        await soon(
            'the eraser takes ink drawn over MCP',
            async () => {
                const ids = Object.keys(await boardKept(page, ref));

                return (
                    !ids.includes('wave') &&
                    !ids.includes('mark') &&
                    ids.includes('ring')
                );
            },
            async () => Object.keys(await boardKept(page, ref)).join(','),
            SAVED,
        );
    },
    { canvas: false },
);
