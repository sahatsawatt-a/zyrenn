// Moving and resizing: what lands on a frame staying on top of it, the ruler
// that lines things up, and a connector that keeps hold of a shape as it grows.
import { SHOTS, runBoard } from './harness.mjs';

await runBoard('/demo/konva', async (b) => {
    const { page, check, draw, join, screenOf, fields, link, painted, drawAt } =
        b;

    // --- nothing ends up underneath the frame it sits on. A frame is an
    // opaque card, so the paint order is the only thing keeping it visible.
    const idAt = (box) =>
        page.evaluate(
            ({ x, y }) =>
                window.Konva.stages[0]
                    .find('Group')
                    .find(
                        (group) =>
                            group.id() &&
                            Math.abs(group.x() - x) < 1 &&
                            Math.abs(group.y() - y) < 1,
                    )
                    ?.id(),
            box,
        );
    const z = (id) =>
        page.evaluate(
            (id) => window.Konva.stages[0].findOne(`#${id}`).zIndex(),
            id,
        );
    const dragTo = async (box, boardX, boardY) => {
        const from = await screenOf(box.x + box.w / 2, box.y + box.h / 2);
        const to = await screenOf(boardX, boardY);
        await page.mouse.move(from.x, from.y);
        await page.mouse.down();
        await page.mouse.move(to.x, to.y, { steps: 14 });
        await page.mouse.up();
        await page.waitForTimeout(350);
        await page.keyboard.press('Escape');
    };

    // A shape made first, and a frame made after it, above the demo's
    // frames, clear of the panel
    const older = await drawAt('rect', 100, -750, 150, 110);
    const olderId = await idAt(older);
    const late = await drawAt('frame', 400, -650, 700, 400);
    const lateId = await idAt(late);
    const resident = await drawAt('rect', 450, -600, 150, 110);
    const residentId = await idAt(resident);
    check(
        'the older shape starts under the newer frame',
        (await z(olderId)) < (await z(lateId)),
        `${await z(olderId)} vs ${await z(lateId)}`,
    );

    await dragTo(older, 900, -400);
    await page.screenshot({ path: `${SHOTS}/dragging-onto-frame.png` });
    check(
        'dragged onto the frame, it comes up over it',
        (await z(olderId)) > (await z(lateId)),
        `${await z(olderId)} vs frame ${await z(lateId)}`,
    );
    check(
        'and over what was already on it',
        (await z(olderId)) > (await z(residentId)),
        `${await z(olderId)} vs ${await z(residentId)}`,
    );

    // Sent to the back, it stays on its frame -- at the bottom of what is there
    const residentAt = await screenOf(
        resident.x + resident.w / 2,
        resident.y + resident.h / 2,
    );
    await page.mouse.click(residentAt.x, residentAt.y);
    await page.waitForTimeout(250);
    await page.locator('[data-test="to-back"]').click();
    await page.waitForTimeout(250);
    check(
        'a shape sent to the back stays over its frame',
        (await z(residentId)) === (await z(lateId)) + 1,
        `${await z(residentId)} vs frame ${await z(lateId)}`,
    );
    await page.keyboard.press('Escape');

    // A frame drawn over a shape already there leaves it showing
    const lonely = await drawAt('rect', 1400, -600, 150, 110);
    const lonelyId = await idAt(lonely);
    const cover = await drawAt('frame', 1300, -700, 700, 400);
    const coverId = await idAt(cover);
    await page.screenshot({ path: `${SHOTS}/dragging-frame-over.png` });
    check(
        'a frame drawn over a shape does not hide it',
        (await z(lonelyId)) > (await z(coverId)),
        `${await z(lonelyId)} vs frame ${await z(coverId)}`,
    );

    // --- two boxes, one deliberately out of line with the other, over on
    // the left where the layers float -- so they are put away for this
    await page.locator('[data-test="layers-close"]').click();
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
    await page.locator('[data-test="layers-open"]').click();
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
