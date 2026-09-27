// The two side panels: layers grouped under the frame each thing sits on, and
// an inspector that keeps the fiddly settings folded away.
import { SHOTS, runBoard } from './harness.mjs';

await runBoard('/demo/konva', async (b) => {
    const {
        page,
        check,
        draw,
        join,

        rows,
        layerNames,
        painted,
        inspectorTitle,
        itemCount,
    } = b;

    // --- grouped by frame
    const groups = page.locator('[data-test^="layer-group-"]');
    const headings = async () =>
        (await groups.allInnerTexts()).map((t) =>
            t.replace(/\s+/g, ' ').trim(),
        );
    check(
        'each frame heads a group',
        (await groups.count()) === 2,
        (await headings()).join(' | '),
    );
    check(
        'a heading counts what is on its frame',
        /What we shipped \d/.test((await headings()).join('|')),
        (await headings()).join(' | '),
    );

    await draw('rect', 0.12, 0.74, 0.06, 0.08);
    check(
        'what no frame holds gets its own pile',
        (await headings()).some((h) => h.startsWith('On the board')),
        (await headings()).join(' | '),
    );

    const all = await rows().count();
    await page.locator('[data-test^="fold-"]').first().click();
    await page.waitForTimeout(300);
    check(
        'folding a frame hides what is on it',
        (await rows().count()) < all,
        `${all} → ${await rows().count()} rows`,
    );
    await page.locator('[data-test^="fold-"]').first().click();
    await page.waitForTimeout(300);
    check('and unfolding brings them back', (await rows().count()) === all);

    // --- drawn on a frame, filed under it
    const onFrame = async () =>
        Number((await groups.first().innerText()).match(/(\d+)\s*$/)?.[1]);
    const before = await onFrame();
    const shape = await draw('rect', 0.3, 0.3, 0.08, 0.1);
    check(
        'a new shape is filed under the frame it was drawn on',
        (await onFrame()) === before + 1,
        `${before} → ${await onFrame()}`,
    );
    check(
        'and it is the one selected, not the frame',
        (await inspectorTitle()).toLowerCase() === 'rect',
        await inspectorTitle(),
    );

    // --- a connector goes with the shapes it joins, having no box of its own
    const second = await draw('rect', 0.46, 0.3, 0.08, 0.1);
    const joined = await onFrame();
    await join(shape, second);
    check(
        'a connector is filed with the shapes it joins',
        (await onFrame()) === joined + 1,
        `${joined} → ${await onFrame()}`,
    );

    // --- hiding, locking and reordering from the list
    const loose = () => rows('board');
    await draw('ellipse', 0.14, 0.76, 0.06, 0.08);
    const names = async () =>
        (await layerNames('board')).map((n) => n.split('\n')[0]);
    check(
        'a new shape goes on top of its group',
        (await names())[0].toLowerCase() === 'ellipse',
        (await names()).slice(0, 2).join(' | '),
    );

    // whatever the drawing left selected, the list says what these act on
    await loose().nth(0).click();
    await page.waitForTimeout(250);
    await page.locator('[data-test="backward"]').click();
    await page.waitForTimeout(250);
    check(
        'send backward steps it one place',
        (await names())[1].toLowerCase() === 'ellipse',
        (await names()).slice(0, 3).join(' | '),
    );
    await page.keyboard.press('Control+]');
    await page.waitForTimeout(250);
    check(
        'Ctrl+] brings it forward again',
        (await names())[0].toLowerCase() === 'ellipse',
    );

    await loose().nth(1).click();
    await page.waitForTimeout(250);
    check(
        'clicking a row selects that item',
        (await inspectorTitle()).toLowerCase() === 'rect',
        await inspectorTitle(),
    );

    const lit = await painted();
    await loose().nth(1).locator('button').nth(1).click();
    await page.waitForTimeout(350);
    const hidden = await painted();
    check(
        'hiding a layer takes it off the board',
        hidden < lit,
        `${lit} → ${hidden} px`,
    );
    await loose().nth(1).locator('button').nth(1).click();
    await page.waitForTimeout(350);
    check(
        'showing it brings it back',
        (await painted()) > hidden,
        `${hidden} → ${await painted()} px`,
    );

    // --- the inspector: labels, and what stays folded
    await loose().nth(0).click();
    await page.waitForTimeout(250);
    check(
        'a shape gets the label controls',
        await page.locator('[data-test="align-config"]').isVisible(),
    );
    check(
        'starting out centred',
        await page
            .locator('[data-test="align-center"]')
            .evaluate((el) => el.className.includes('is-on')),
    );
    await page.locator('[data-test="align-left"]').click();
    await page.locator('[data-test="valign-top"]').click();
    await page.waitForTimeout(300);
    const drawn = await page.evaluate(() =>
        window.Konva.stages[0]
            .find('Text')
            .map((t) => `${t.align()}/${t.verticalAlign()}`)
            .join(' '),
    );
    check(
        'and the canvas draws the label that way',
        drawn.includes('left/top'),
        drawn.split(' ').slice(-2).join(' '),
    );
    check(
        'the items count is still right',
        (await itemCount()) > 0,
        `${await itemCount()} items`,
    );
    await page.screenshot({ path: `${SHOTS}/panels.png` });
});
