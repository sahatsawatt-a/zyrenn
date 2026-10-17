// The right button moves the board: held and dragged with a mouse -- or a
// pen -- the board slides under it with the grab hand showing, and no menu
// opens after, whatever tool is in hand; a right click that does not move
// still opens a frame's menu; and a left drag still chooses what it passes.
import { open, runBoard, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const WHO = process.env.E2E_EMAIL ?? 'test@example.com';

await runBoard(
    '/boards',
    async (b) => {
        const { page, check, soon, afterwards, camera, screenOf, itemCount } =
            b;
        const base = new URL(page.url()).origin;
        const user = `App\\Models\\User::where('email', '${WHO}')->first()`;
        const ref = tinker(
            `App\\Mcp\\Servers\\UserServer::actingAs(${user})->tool(App\\Mcp\\Tools\\Boards\\CreateBoard::class, ['title' => 'Right pan ${RUN}', 'items' => [
                ['id' => 'slide', 'kind' => 'frame', 'text' => 'Slide', 'x' => 0, 'y' => 0, 'width' => 480, 'height' => 270],
                ['id' => 'box', 'kind' => 'rect', 'text' => 'Box', 'x' => 700, 'y' => 40, 'width' => 160, 'height' => 100]]]);
             echo ${user}->boards()->where('title', 'Right pan ${RUN}')->value('ref_id');`.replace(
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

        const test = (name) => page.locator(`[data-test="${name}"]`);
        const surface = page.locator('[data-camera]');
        const menuOpen = async () => (await test('frame-menu').count()) > 0;

        /** A right-button drag with the mouse, from a board point, by pixels. */
        const rightDrag = async (from, by, during = async () => {}) => {
            const start = await screenOf(from.x, from.y);

            await page.mouse.move(start.x, start.y);
            await page.mouse.down({ button: 'right' });
            await page.mouse.move(start.x + by.x / 2, start.y + by.y / 2, {
                steps: 4,
            });
            await during();
            await page.mouse.move(start.x + by.x, start.y + by.y, { steps: 4 });
            await page.mouse.up({ button: 'right' });
        };

        // ------------------------------------------------ on bare board
        const before = await camera();
        const count = await itemCount();
        let grabbing = false;

        await rightDrag({ x: 600, y: 400 }, { x: 160, y: 90 }, async () => {
            grabbing = /cursor-grabbing/.test(
                await surface.getAttribute('class'),
            );
        });
        const after = await camera();
        check(
            'a right drag moves the board as far as the pointer went',
            Math.abs(after.x - before.x - 160) < 2 &&
                Math.abs(after.y - before.y - 90) < 2 &&
                after.scale === before.scale,
            JSON.stringify({ before, after }),
        );
        check('with the grab hand showing while it does', grabbing);
        check(
            'and nothing opens or changes after it',
            !(await menuOpen()) && (await itemCount()) === count,
        );

        // ------------------------------------------------- on a frame
        await rightDrag({ x: 240, y: 135 }, { x: -120, y: 0 });
        check(
            "a right drag that starts on a frame moves the board, and opens no frame's menu",
            !(await menuOpen()),
        );

        const still = await screenOf(240, 135);
        await page.mouse.click(still.x, still.y, { button: 'right' });
        await soon(
            "a right click that doesn't move still opens the frame's menu",
            menuOpen,
        );
        await page.keyboard.press('Escape');
        await soon('(and it closes)', async () => !(await menuOpen()));

        // ---------------------------------- whatever tool is in hand
        // (R is the rectangle, as the shortcuts say)
        await page.keyboard.press('r');
        await soon(
            '(the rectangle tool is in hand)',
            async () => (await surface.getAttribute('data-tool')) === 'rect',
        );
        const withTool = await camera();
        await rightDrag({ x: 600, y: 500 }, { x: 0, y: -100 });
        check(
            'with a drawing tool in hand, a right drag moves the board and draws nothing',
            Math.abs((await camera()).y - withTool.y + 100) < 2 &&
                (await itemCount()) === count,
        );
        await page.keyboard.press('Escape');

        // ------------------------------------------------------- a pen
        const cdp = await page.context().newCDPSession(page);
        const pen = async (type, point, held) =>
            cdp.send('Input.dispatchMouseEvent', {
                type,
                ...point,
                button: 'right',
                buttons: held ? 2 : 0,
                clickCount: 1,
                pointerType: 'pen',
            });
        const byPen = await camera();
        const from = await screenOf(600, 400);

        await pen('mousePressed', from, true);
        for (let step = 1; step <= 6; step++) {
            await pen('mouseMoved', { x: from.x + step * 10, y: from.y }, true);
        }
        await pen('mouseReleased', { x: from.x + 60, y: from.y }, false);
        check(
            "a pen's right button drags the board too",
            Math.abs((await camera()).x - byPen.x - 60) < 2,
            JSON.stringify({ byPen, now: await camera() }),
        );

        // ------------------------------------------- the left button still
        const box = {
            from: await screenOf(660, 10),
            to: await screenOf(900, 180),
        };
        await page.mouse.move(box.from.x, box.from.y);
        await page.mouse.down();
        await page.mouse.move(box.to.x, box.to.y, { steps: 6 });
        await page.mouse.up();
        await soon(
            'and a left drag still chooses what it passes over',
            async () =>
                (await test('inspector-title').innerText())
                    .trim()
                    .toLowerCase() === 'rect',
            () => test('inspector-title').innerText(),
        );
    },
    { canvas: false },
);
