// A board under a pen and under fingers, and on a screen the size of a
// phone: strokes drawn whole, one after another; one finger sliding the
// board and two pinching it; the panels out of the canvas's way.
import { SHOTS, runBoard, steady, until } from './harness.mjs';

await runBoard(
    '/demo/konva',
    async ({ page, check, soon, at, camera, inspectorTitle }) => {
        const cdp = await page.context().newCDPSession(page);
        await cdp.send('Emulation.setTouchEmulationEnabled', {
            enabled: true,
            maxTouchPoints: 5,
        });

        // Fingers on the glass, each where it is; none, to lift them all
        const touch = (type, points = []) =>
            cdp.send('Input.dispatchTouchEvent', {
                type,
                touchPoints: points.map((point, id) => ({ ...point, id })),
            });
        const along = (from, by, step) => ({
            x: from.x + by.x * step,
            y: from.y + by.y * step,
        });
        const swipe = async (from, by, steps = 10) => {
            await touch('touchStart', [from]);

            for (let step = 1; step <= steps; step++) {
                await touch('touchMove', [along(from, by, step)]);
            }

            await touch('touchEnd');
        };
        const pen = async (from, by, steps = 20) => {
            const event = (type, point, held) =>
                cdp.send('Input.dispatchMouseEvent', {
                    type,
                    ...point,
                    button: 'left',
                    buttons: held ? 1 : 0,
                    clickCount: 1,
                    pointerType: 'pen',
                });

            await event('mousePressed', from, true);

            for (let step = 1; step <= steps; step++) {
                await event('mouseMoved', along(from, by, step), true);
            }

            await event('mouseReleased', along(from, by, steps), false);
        };
        // The ink on the board: how many points each stroke has
        const strokes = () =>
            page.evaluate(() =>
                window.Konva.stages[0]
                    .find('Line')
                    .filter((line) => line.tension() === 0.4)
                    .map((line) => line.points().length / 2),
            );
        const test = (name) => page.locator(`[data-test="${name}"]`);

        // ------------------------------------------- A phone: room to draw
        const canvas = await page.locator('[data-zoom]').boundingBox();
        const tools = await test('shape-library').boundingBox();
        check(
            'on a phone the panels start put away',
            !(await test('inspector').isVisible()) &&
                !(await test('layers').isVisible()),
        );
        check(
            'and the tools fit across it',
            tools.x >= canvas.x &&
                tools.x + tools.width <= canvas.x + canvas.width,
            `${Math.round(tools.x)}–${Math.round(tools.x + tools.width)} of ${canvas.width}`,
        );
        await page.screenshot({ path: `${SHOTS}/touch-phone.png` });

        // ------------------------------------------- Fingers move the board
        const before = await camera();
        await swipe(at(0.5, 0.12), { x: 6, y: 4 });
        let slid;
        await soon(
            'one finger on the bare board slides it',
            async () => {
                slid = await camera();

                return (
                    Math.abs(slid.x - before.x - 60) < 2 &&
                    Math.abs(slid.y - before.y - 40) < 2
                );
            },
            () => `${slid.x - before.x}, ${slid.y - before.y}`,
        );

        const left = at(0.35, 0.5);
        const right = at(0.65, 0.5);
        await touch('touchStart', [left]);
        await touch('touchStart', [left, right]);

        for (let step = 1; step <= 8; step++) {
            await touch('touchMove', [
                along(left, { x: -5, y: 0 }, step),
                along(right, { x: 5, y: 0 }, step),
            ]);
        }

        await touch('touchEnd');
        let pinched;
        await soon(
            'two fingers pinch it, wherever the first came down',
            async () => {
                pinched = await camera();

                return pinched.scale > slid.scale * 1.4;
            },
            () => `${slid.scale.toFixed(2)} → ${pinched.scale.toFixed(2)}`,
        );
        // Back to everything, and still, before a tap that goes by where
        // things are
        await test('zoom').click();
        await until(async () => (await camera()).scale < pinched.scale);
        await steady(camera);

        // A tap chooses what is under it, as a click does
        const middle = await page.evaluate(() => {
            const box = window.Konva.stages[0]
                .find('Group')
                .find((group) => group.id() && group.width() > 100)
                .getClientRect();

            return { x: box.x + box.width / 2, y: box.y + box.height / 2 };
        });
        await touch('touchStart', [
            { x: canvas.x + middle.x, y: canvas.y + middle.y },
        ]);
        await touch('touchEnd');
        await test('panel-open').click();
        await soon(
            'a tap chooses what it lands on',
            async () => !/nothing selected/i.test(await inspectorTitle()),
            inspectorTitle,
        );
        const sheet = await test('inspector').boundingBox();
        check(
            'its settings come up across the bottom, clear of the tools',
            sheet.width > canvas.width * 0.9 &&
                sheet.y + sheet.height <= tools.y,
            JSON.stringify(sheet),
        );
        await page.screenshot({ path: `${SHOTS}/touch-sheet.png` });
        await test('inspector-close').click();
        await page.keyboard.press('Escape');

        // ------------------------------------------- Drawing
        await test('tool-draw').click();
        const inked = async () => (await strokes()).join();

        await swipe(at(0.3, 0.14), { x: 5, y: 2 }, 20);
        await soon(
            'a finger with the pen tool draws the whole stroke',
            async () => (await inked()) === '21',
            inked,
        );
        await swipe(at(0.3, 0.2), { x: 5, y: 2 }, 20);
        await soon(
            'and the next, the pen still in hand',
            async () => (await inked()) === '21,21',
            inked,
        );

        await pen(at(0.3, 0.26), { x: 5, y: 2 });
        await pen(at(0.3, 0.3), { x: 5, y: 2 });
        await soon(
            'a stylus writes stroke after stroke',
            async () => (await strokes()).length === 4,
            inked,
        );

        // A second finger down is a pinch, not a stroke
        await touch('touchStart', [left]);
        await touch('touchMove', [along(left, { x: 4, y: 4 }, 1)]);
        await touch('touchStart', [along(left, { x: 4, y: 4 }, 1), right]);
        await touch('touchMove', [
            along(left, { x: 4, y: 4 }, 2),
            along(right, { x: 4, y: 4 }, 1),
        ]);
        await touch('touchEnd');
        // Long enough for a stroke to have been kept, were it going to be
        await page.waitForTimeout(150);
        check(
            'a stroke begun by one finger is dropped when a second comes down',
            (await strokes()).length === 4,
            (await strokes()).join(),
        );
        await page.screenshot({ path: `${SHOTS}/touch-ink.png` });
    },
    { width: 390, height: 844 },
);
