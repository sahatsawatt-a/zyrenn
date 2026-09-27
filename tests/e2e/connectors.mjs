// Connectors: drawn between two shapes, following them about, pinned to a face
// when dropped on one, and every setting the inspector offers.
import { SHOTS, runBoard } from './harness.mjs';

await runBoard('/demo/konva', async (b) => {
    const {
        page,
        check,
        draw,
        join,
        screenOf,
        itemCount,
        inspectorTitle,
        link,
        painted,
        pinnedSide,
        layerNames,
        rows,
    } = b;

    const source = await draw('rect', 0.12, 0.62, 0.1, 0.12);
    const target = await draw('ellipse', 0.55, 0.62, 0.1, 0.12);
    const before = await itemCount();

    await join(source, target);
    check(
        'a connector is created',
        (await itemCount()) === before + 1,
        `${before} → ${await itemCount()}`,
    );
    check(
        'and left selected',
        (await inspectorTitle()).toLowerCase() === 'arrow',
        await inspectorTitle(),
    );
    check(
        'the inspector says what it joins',
        /→/.test(await link()),
        await link(),
    );

    // --- dropped on the shape, an end stays free to turn
    check(
        'an end dropped on a shape is left free',
        (await pinnedSide('from')) === 'auto',
        await pinnedSide('from'),
    );

    // --- it follows what it is pinned to
    const drawnAt = await painted();
    const grab = await screenOf(
        source.x + source.w / 2,
        source.y + source.h / 2,
    );
    await page.mouse.move(grab.x, grab.y);
    await page.mouse.down();
    await page.mouse.move(grab.x, grab.y - 150, { steps: 12 });
    await page.mouse.up();
    await page.waitForTimeout(400);
    check(
        'the line follows the shape it is pinned to',
        (await painted()) !== drawnAt,
    );

    // --- and goes when that shape does
    const joined = await itemCount();
    await page.keyboard.press('Delete');
    await page.waitForTimeout(350);
    check(
        'deleting a shape removes its connector',
        (await itemCount()) === joined - 2,
        `${joined} → ${await itemCount()}`,
    );

    // --- dropped on a dot, an end keeps that face
    const a = await draw('rect', 0.12, 0.62, 0.1, 0.12);
    const c = await draw('ellipse', 0.55, 0.62, 0.1, 0.12);
    await join(a, c, {
        from: await screenOf(a.x + a.w / 2, a.y + a.h),
        to: await screenOf(c.x + c.w / 2, c.y + c.h),
    });
    check(
        'dropping on a dot pins the start to that face',
        (await pinnedSide('from')) === 'bottom',
        await pinnedSide('from'),
    );
    check(
        'and pins the end to the dot it landed on',
        (await pinnedSide('to')) === 'bottom',
        await pinnedSide('to'),
    );

    // the elbow has to turn past both stubs, or a tail hangs under the shape
    const path = await page.evaluate(() => {
        const lines = window.Konva.stages[0]
            .find('Line')
            .filter((line) => line.points().length >= 8);
        return lines.length ? lines[lines.length - 1].points() : null;
    });
    check(
        'the drawn path came back',
        Array.isArray(path),
        `${path?.length} numbers`,
    );

    if (path) {
        const downs = path.filter((_, index) => index % 2 === 1);
        const lowest = Math.max(...downs);
        check(
            'nothing hangs below the run underneath',
            downs.filter((d) => Math.abs(d - lowest) < 0.5).length >= 2,
            `lowest ${lowest.toFixed(1)}`,
        );
    }

    await page.screenshot({ path: `${SHOTS}/connectors-pinned.png` });

    // --- and the inspector can pin or free an end after the fact
    await page
        .locator('[data-test="side-from"]')
        .selectOption({ label: 'Auto' });
    await page.waitForTimeout(300);
    check(
        'the inspector can set an end back to auto',
        (await pinnedSide('from')) === 'auto',
        await pinnedSide('from'),
    );

    // --- every other setting
    for (const routing of ['straight', 'curved', 'elbow']) {
        await page.locator(`[data-test="routing-${routing}"]`).click();
        await page.waitForTimeout(180);
        check(
            `${routing} routing applies`,
            await page
                .locator(`[data-test="routing-${routing}"]`)
                .evaluate((el) => el.className.includes('is-on')),
        );
    }

    await page.locator('[data-test="style-dashed"]').click();
    await page.locator('[data-test="line-width"]').fill('6');
    await page.waitForTimeout(250);
    check(
        'dashed and thickness stick',
        await page
            .locator('[data-test="style-dashed"]')
            .evaluate((el) => el.className.includes('is-on')),
    );

    check(
        'the heads stay folded away until wanted',
        !(await page.locator('[data-test="head-end-arrow"]').isVisible()),
    );
    await page.locator('[data-test="ends-config"] summary').click();
    await page.waitForTimeout(250);

    for (const head of ['open', 'circle', 'diamond', 'bar', 'none', 'arrow']) {
        await page.locator(`[data-test="head-end-${head}"]`).click();
        await page.waitForTimeout(140);
        check(
            `end head: ${head}`,
            await page
                .locator(`[data-test="head-end-${head}"]`)
                .evaluate((el) => el.className.includes('is-on')),
        );
    }

    // --- a connector carries a label like anything else
    const middle = await screenOf((a.x + a.w / 2 + c.x) / 2, a.y + a.h / 2);
    await page.mouse.dblclick(middle.x, middle.y);
    await page.waitForTimeout(350);

    if (await page.locator('[data-test="text-editor"]').count()) {
        await page.keyboard.type('sends to');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(300);
    }

    check(
        'a connector can be labelled',
        (await layerNames()).some((name) => name.includes('sends to')),
        (await rows().count()) + ' rows',
    );
});
