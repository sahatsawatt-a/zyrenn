// How a board is drawn: dark with the app, where only the board itself
// changes; shapes with nothing behind their words and edges of any weight;
// and a formula keeping its place while the board moves under it.
import { SHOTS, runBoard, steady, until } from './harness.mjs';

const INK = '#0f172a';

await runBoard('/demo/konva', async (b) => {
    const { page, check, soon, drawAt, screenOf, fields, at, camera } = b;
    const { inspectorTitle } = b;
    const test = (name) => page.locator(`[data-test="${name}"]`);

    // What the canvas draws a board item with, by where it is on the board
    const drawn = (box) =>
        page.evaluate(({ x, y }) => {
            const group = window.Konva.stages[0]
                .find('Group')
                .find(
                    (one) =>
                        one.id() &&
                        Math.abs(one.x() - x) < 1 &&
                        Math.abs(one.y() - y) < 1,
                );
            const shape = group.findOne('Rect') ?? group.findOne('Text');
            const words = group.findOne('Text');

            return {
                fill: shape.fill(),
                stroke: shape.stroke(),
                width: shape.strokeWidth(),
                words: words?.fill(),
            };
        }, box);
    const choose = async (box) => {
        const middle = await screenOf(box.x + box.w / 2, box.y + box.h / 2);
        await page.mouse.click(middle.x, middle.y);
        await until(
            async () => !/nothing selected/i.test(await inspectorTitle()),
        );
    };

    // ------------------------------------------- Nothing behind, any edge
    const shape = await drawAt('rect', 100, -750, 200, 140);
    await page.keyboard.press('Escape');
    await choose(shape);
    await test('fill-chip').click();
    await test('palette-none').click();
    await page.keyboard.press('Escape');
    await test('outline-width').fill('6');
    await page.keyboard.press('Escape');
    let hollow;
    await soon(
        'a shape can have no fill, and an edge as heavy as wanted',
        async () => {
            hollow = await drawn(shape);

            return hollow.fill === 'transparent' && hollow.width === 6;
        },
        () => JSON.stringify(hollow),
    );
    await choose(shape);
    check(
        'it is still picked up through its empty middle',
        (await fields()).w === shape.w,
    );
    await test('outline-width').fill('0');
    await page.keyboard.press('Escape');
    await soon(
        'at 0 it has no edge at all',
        async () => (await drawn(shape)).width === 0,
    );

    // ------------------------------------------- Dark, with the app
    const onBoard = await drawAt('text', 400, -750, 260, 60);
    await page.keyboard.press('Escape');
    await page.keyboard.press('Escape');
    // The demo's first frame is white, with a title written on it
    const onFrame = await page.evaluate(() => {
        const stage = window.Konva.stages[0];
        const frame = stage
            .find('Group')
            .find((group) => group.findOne('Rect')?.shadowBlur() === 18);
        const inside = stage.find('Group').find((group) => {
            const text = group.findOne('Text');

            return (
                group.id() &&
                group !== frame &&
                group.getChildren().length === 1 &&
                text &&
                group.x() > frame.x() &&
                group.x() < frame.x() + frame.width() &&
                group.y() > frame.y() &&
                group.y() < frame.y() + frame.height()
            );
        });

        return {
            frame: frame.findOne('Rect').fill(),
            words: inside?.findOne('Text').fill(),
        };
    });
    const paper = () =>
        page
            .locator('[data-zoom]')
            .evaluate((canvas) => getComputedStyle(canvas).backgroundColor);
    const light = await paper();

    await page.evaluate(() => document.documentElement.classList.add('dark'));
    await soon(
        'in the dark the board itself goes dark',
        async () => (await paper()) !== light,
        async () => `${light} → ${await paper()}`,
    );
    await soon(
        'words written straight on it turn light',
        async () => (await drawn(onBoard)).fill !== INK,
        async () => (await drawn(onBoard)).fill,
    );
    await page.screenshot({ path: `${SHOTS}/board-dark.png` });

    const afterwards = await page.evaluate(() => {
        const stage = window.Konva.stages[0];
        const frame = stage
            .find('Group')
            .find((group) => group.findOne('Rect')?.shadowBlur() === 18);

        return { frame: frame.findOne('Rect').fill() };
    });
    check(
        'a frame keeps the colour it was given',
        afterwards.frame === onFrame.frame,
        `${onFrame.frame} → ${afterwards.frame}`,
    );

    await page.evaluate(() =>
        document.documentElement.classList.remove('dark'),
    );
    await soon(
        'and back in the light, the words are ink again',
        async () => (await drawn(onBoard)).fill === INK,
        async () => (await drawn(onBoard)).fill,
    );

    // ------------------------------------------- A formula keeps its place
    await drawAt('math', 100, -500, 260, 90);
    await page.keyboard.press('Escape');
    await page.keyboard.press('Escape');
    // How far the formula's HTML is from the box the canvas has for it
    const adrift = () =>
        page.evaluate(() => {
            const stage = window.Konva.stages[0];
            const math = document.querySelector('[data-test^="math-"]');
            const node = stage
                .findOne(`#${math.getAttribute('data-test').slice(5)}`)
                .getAbsolutePosition();
            const canvas = stage.container().getBoundingClientRect();
            const box = math.getBoundingClientRect();

            return Math.hypot(
                box.left - canvas.left - node.x,
                box.top - canvas.top - node.y,
            );
        });
    const worstOver = async (frames) => {
        let worst = 0;

        // Looked at again and again while the board moves: that it never
        // comes adrift is the thing being shown
        for (let frame = 0; frame < frames; frame++) {
            await page.waitForTimeout(35);
            worst = Math.max(worst, await adrift());
        }

        return worst;
    };

    await page.keyboard.down('Space');
    const hold = at(0.5, 0.6);
    await page.mouse.move(hold.x, hold.y);
    await page.mouse.down();
    await page.mouse.move(hold.x + 180, hold.y + 120, { steps: 8 });
    await page.evaluate(
        () =>
            new Promise((resolve) =>
                requestAnimationFrame(() => requestAnimationFrame(resolve)),
            ),
    );
    const dragged = await adrift();
    await page.mouse.up();
    await page.keyboard.up('Space');
    check(
        'a formula stays on its box while the board is dragged',
        dragged < 1.5,
        `${dragged.toFixed(1)}px adrift`,
    );

    await test('present').click();
    const gliding = await worstOver(12);
    await steady(camera);
    await page.keyboard.press('ArrowRight');
    const next = await worstOver(12);
    check(
        'and while the board glides from slide to slide',
        gliding < 1.5 && next < 1.5,
        `${gliding.toFixed(1)}px, then ${next.toFixed(1)}px`,
    );
    await page.keyboard.press('Escape');
});
