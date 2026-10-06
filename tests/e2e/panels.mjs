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
        fields,
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
    // In the first frame's empty right-hand corner, clear of its stickies
    const shape = await b.drawAt('rect', 730, 120, 150, 110);
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
    const second = await b.drawAt('rect', 730, 360, 150, 110);
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

    // --- a frame kept on the board but left out of the PDF
    const firstFrame = groups.first();
    const pdfToggle = firstFrame.locator('[data-test^="pdf-"]');
    await pdfToggle.click();
    await page.waitForTimeout(250);
    check(
        'a frame can be hidden in the PDF from its heading',
        (await pdfToggle.getAttribute('title')) === 'Show in PDF' &&
            (await pdfToggle.evaluate((el) => el.className.includes('is-on'))),
        await pdfToggle.getAttribute('title'),
    );
    await firstFrame.click();
    await page.waitForTimeout(250);
    const pdfCheck = page.locator('[data-test="prop-pdf-hidden"]');
    check(
        'and the inspector shows it, without unselecting the frame',
        (await inspectorTitle()).toLowerCase() === 'frame' &&
            (await pdfCheck.isChecked()),
        await inspectorTitle(),
    );
    await pdfCheck.click();
    await page.waitForTimeout(250);
    check(
        'unticking it there puts the frame back in the PDF',
        (await pdfToggle.getAttribute('title')) === 'Hide in PDF',
        await pdfToggle.getAttribute('title'),
    );
    await loose().nth(0).click();
    await page.waitForTimeout(250);
    check('only a frame has the PDF setting', !(await pdfCheck.isVisible()));

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

    // --- where things are: tools along the bottom middle, layers down the
    // left, settings down the right
    const canvas = b.view.box;
    const boxOf = (test) => page.locator(`[data-test="${test}"]`).boundingBox();
    const tools = await boxOf('shape-library');
    check(
        'the tools sit along the bottom middle',
        Math.abs(tools.x + tools.width / 2 - (canvas.x + canvas.width / 2)) <
            4 && canvas.y + canvas.height - (tools.y + tools.height) < 24,
        `${Math.round(tools.x)},${Math.round(tools.y)} ${Math.round(tools.width)}×${Math.round(tools.height)}`,
    );
    const layersBox = await boxOf('layers');
    const inspectorBox = await boxOf('inspector');
    check(
        'the layers float down the left, the settings down the right',
        layersBox.x - canvas.x < 24 &&
            canvas.x + canvas.width - (inspectorBox.x + inspectorBox.width) <
                24,
        `${Math.round(layersBox.x)} | ${Math.round(inspectorBox.x)}`,
    );
    await page.screenshot({ path: `${SHOTS}/panels.png` });

    // --- each panel goes away from its own corner, and comes back from it
    for (const [panel, close, open] of [
        ['layers', 'layers-close', 'layers-open'],
        ['inspector', 'inspector-close', 'panel-open'],
    ]) {
        await page.locator(`[data-test="${close}"]`).click();
        await page.waitForTimeout(200);
        const icon = await boxOf(open);
        check(
            `${panel}: hidden, it leaves an icon in its top corner`,
            !(await page.locator(`[data-test="${panel}"]`).isVisible()) &&
                icon &&
                icon.y - canvas.y < 24,
        );
        await page.locator(`[data-test="${open}"]`).click();
        await page.waitForTimeout(200);
        check(
            `${panel}: and the icon brings it back`,
            await page.locator(`[data-test="${panel}"]`).isVisible(),
        );
    }

    // --- picking a layer brings it into view
    const aside = await b.camera();
    await page.locator('[data-test="layer-group-board"]').click();
    await page.locator('[data-test^="layer-group-"]').nth(1).click();
    await page.waitForTimeout(700);
    const picked = await fields();
    const middle = await b.screenOf(
        picked.x + picked.w / 2,
        picked.y + picked.h / 2,
    );
    const room = {
        x: canvas.x + layersBox.width + 12,
        width: canvas.width - layersBox.width - inspectorBox.width - 24,
    };
    check(
        'a frame picked from the list is moved to the middle of the view',
        Math.abs(middle.x - (room.x + room.width / 2)) < 40,
        `${Math.round(middle.x)} vs ${Math.round(room.x + room.width / 2)}, camera ${aside.x} → ${(await b.camera()).x}`,
    );
    await page.screenshot({ path: `${SHOTS}/panels-picked.png` });

    // --- the swatches are your own: keep a colour, take one off, reset
    await loose().nth(0).click();
    await page.waitForTimeout(250);
    const swatches = () =>
        page
            .locator('[data-test="colour-picker"]')
            .first()
            .locator('[data-test="palette-swatch"]')
            .count();
    const start = await swatches();
    await page.locator('[data-test="picker-hex"]').first().fill('#123456');
    await page.locator('[data-test="palette-keep"]').first().click();
    await page.waitForTimeout(200);
    check(
        'the plus keeps the colour being used with the swatches',
        (await swatches()) === start + 1,
        `${start} → ${await swatches()}`,
    );
    check(
        'and the line picker has it too',
        (await page
            .locator('[data-test="stroke-picker"]')
            .locator('[data-test="palette-swatch"][title="#123456"]')
            .count()) === 1,
    );
    check(
        'it is remembered',
        (
            await page.evaluate(() => localStorage.getItem('board.palette'))
        ).includes('#123456'),
    );
    await page.locator('[data-test="palette-edit"]').first().click();
    await page
        .locator('[data-test="colour-picker"]')
        .first()
        .locator('[data-test="palette-swatch"]')
        .first()
        .click();
    await page.waitForTimeout(200);
    check(
        'under Edit, a swatch clicked comes off',
        (await swatches()) === start,
        `${start + 1} → ${await swatches()}`,
    );
    await page.screenshot({ path: `${SHOTS}/panels-palette.png` });
    await page.locator('[data-test="palette-reset"]').first().click();
    await page.locator('[data-test="palette-edit"]').first().click();
    await page.waitForTimeout(200);
    check(
        'and Reset puts the board colours back',
        (await swatches()) === start &&
            (await page
                .locator('[data-test="palette-swatch"][title="#ffffff"]')
                .count()) > 0,
        `${await swatches()} swatches`,
    );
});
