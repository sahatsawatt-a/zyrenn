// Moving and resizing: the ruler that lines things up, and a connector that
// keeps hold of a shape as it grows.
import { SHOTS, runBoard } from './harness.mjs';

await runBoard('/demo/konva', async (b) => {
    const { page, check, draw, join, screenOf, fields, link, painted } = b;

    // --- two boxes, one deliberately out of line with the other
    const anchor = await draw('rect', 0.14, 0.62, 0.1, 0.12);
    const wanderer = await draw('rect', 0.44, 0.7, 0.1, 0.12);
    check(
        'they start out of line',
        Math.abs(wanderer.y - anchor.y) > 2,
        `${anchor.y} vs ${wanderer.y}`,
    );

    // drag the second until it is nearly level with the first
    const grab = await screenOf(
        wanderer.x + wanderer.w / 2,
        wanderer.y + wanderer.h / 2,
    );
    const level = await screenOf(
        wanderer.x + wanderer.w / 2,
        anchor.y + wanderer.h / 2 + 4,
    );
    await page.mouse.move(grab.x, grab.y);
    await page.mouse.down();
    await page.mouse.move(level.x, level.y, { steps: 14 });
    await page.waitForTimeout(250);
    const guides = await page.evaluate(
        () =>
            window.Konva.stages[0]
                .find('Line')
                .filter(
                    (line) =>
                        (line.dash() ?? []).length === 2 &&
                        line.stroke() === '#ec4899',
                ).length,
    );
    check(
        'the board holds up a ruler while it is dragged',
        guides > 0,
        `${guides} guides`,
    );
    await page.screenshot({ path: `${SHOTS}/dragging-guides.png` });
    await page.mouse.up();
    await page.waitForTimeout(350);
    const moved = await fields();
    check(
        'and it lands in line with its neighbour',
        Math.abs(moved.y - anchor.y) < 2,
        `${moved.y} vs ${anchor.y}`,
    );

    // --- a connector keeps hold of a shape that is resized
    await join(anchor, moved);
    check('the two are joined', /→/.test(await link()), await link());

    const middle = await screenOf(moved.x + moved.w / 2, moved.y + moved.h / 2);
    await page.mouse.click(middle.x, middle.y);
    await page.waitForTimeout(300);
    const before = await fields();
    const handle = await screenOf(before.x + before.w, before.y + before.h / 2);
    const drawn = await painted();
    await page.mouse.move(handle.x, handle.y);
    await page.mouse.down();
    await page.mouse.move(handle.x + 90, handle.y, { steps: 10 });
    await page.mouse.up();
    await page.waitForTimeout(400);
    const after = await fields();
    check(
        'a handle resizes the shape',
        after.w > before.w + 40,
        `${before.w} → ${after.w}`,
    );
    check('and the line is redrawn with it', (await painted()) !== drawn);

    await page.keyboard.press('Control+z');
    await page.waitForTimeout(400);
    check(
        'undo puts the size back',
        Math.abs((await fields()).w - before.w) < 2,
        `${after.w} → ${(await fields()).w}`,
    );

    // --- and the line still joins the two shapes afterwards
    await page
        .locator('[data-test="layers"] .layers-row')
        .filter({ hasText: /Connector/i })
        .first()
        .click();
    await page.waitForTimeout(300);
    check(
        'the connector still joins both shapes',
        /→/.test(await link()),
        await link(),
    );
});
