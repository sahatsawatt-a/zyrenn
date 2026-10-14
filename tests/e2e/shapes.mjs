// The shape library, the kinds of connector and the templates: a shape from a
// folded set is found and drawn as itself, a line is made "is a kind of" or
// "one to many" in one choice, and a template lands as shapes of one's own.
import { SHOTS, runBoard, steady, until } from './harness.mjs';

await runBoard('/demo/konva', async (b) => {
    const { page, check, soon, drawAt, join, itemCount, layerNames } = b;
    const test = (name) => page.locator(`[data-test="${name}"]`);
    const flyout = test('shape-flyout');

    // What the canvas drew for the item whose box starts here, on the board
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
            const words =
                group?.findOne('.rich-label') ?? group?.findOne('Text');

            return group
                ? {
                      paths: group.find('Path').length,
                      wordsAt: words?.y(),
                      height: group.height(),
                  }
                : null;
        }, box);

    // ------------------------------------------------ Found in the flyout
    await test('tool-shapes').click();
    await flyout.waitFor();
    check(
        'more plain and flowchart shapes are a click away',
        (await flyout.locator('[data-test="tool-pentagon"]').count()) === 1 &&
            (await flyout.locator('[data-test="tool-delay"]').count()) === 1,
    );
    check(
        'the shapes of one kind of diagram stay folded until wanted',
        (await flyout.locator('[data-test="tool-class"]').count()) === 0 &&
            (await flyout.locator('[data-test="tool-server"]').count()) === 0,
    );
    await test('shape-search').fill('kafka');
    await soon(
        'a search finds a folded shape by another name for it',
        async () =>
            (await flyout.locator('.library-item').count()) === 1 &&
            (await flyout.locator('[data-test="tool-queue"]').count()) === 1,
    );
    await test('shape-search').fill('');
    await page.keyboard.press('Escape');
    await flyout.waitFor({ state: 'detached' });

    // ----------------------------------------------------- Drawn as itself
    const before = await itemCount();
    const folder = await drawAt('package', 100, -900, 220, 150);
    let seen;
    await soon(
        'a shape from a folded set is drawn as the library draws it',
        async () => {
            seen = await drawn(folder);

            return seen?.paths === 1 && (await itemCount()) === before + 1;
        },
        () => JSON.stringify(seen),
    );
    check(
        'and is named for what it is until it is given words',
        (await layerNames()).some((name) => name === 'Package'),
        (await layerNames()).slice(0, 4).join(' | '),
    );

    const actor = await drawAt('actor', 420, -900, 70, 120);
    await soon(
        'a figure is named under itself',
        async () => {
            seen = await drawn(actor);

            return seen?.paths === 1 && seen.wordsAt >= seen.height;
        },
        () => JSON.stringify(seen),
    );

    const end = await drawAt('end_state', 600, -900, 40, 40);
    await soon(
        'an end state has its dot filled in',
        async () => (await drawn(end))?.paths === 2,
    );

    // ------------------------------------------------- The kind of a line
    const from = await drawAt('rect', 100, -600, 160, 90);
    const to = await drawAt('rect', 520, -600, 160, 90);
    await join(from, to);
    await test('connector-type').waitFor();

    // The caps the selected line is drawn with, a list of pieces for each end
    const caps = () =>
        page.evaluate(() => {
            const line = window.Konva.stages[0]
                .find('Group')
                .filter((group) => group.id() && group.findOne('Line'))
                .find((group) => group.find('Group').length > 0);

            // (in no order: a cap added later is drawn after the other)
            return (line?.find('Group') ?? [])
                .map((cap) =>
                    cap
                        .getChildren()
                        .map((piece) => piece.getClassName())
                        .join('+'),
                )
                .sort();
        });

    const choose = async (type) => {
        await test('connector-type').click();
        await test(`connector-type-${type}`).click();
        await test('connector-types').waitFor({ state: 'detached' });
    };

    await choose('inheritance');
    await soon(
        '"is a kind of" is a line with one hollow arrow',
        async () =>
            /is a kind of/i.test(await test('connector-type').innerText()) &&
            (await caps()).join() === 'Line',
        async () => JSON.stringify(await caps()),
    );

    await choose('one-to-any');
    await soon(
        '"one to zero or many" is two bars at one end, a crow\'s foot and a ring at the other',
        async () => (await caps()).join() === 'Line+Circle,Line+Line',
        async () => JSON.stringify(await caps()),
    );

    await test('ends-config').locator('summary').click();
    await test('head-start-diamond_open').click();
    await soon(
        'an end set by hand makes it a line of no named kind',
        async () => /custom/i.test(await test('connector-type').innerText()),
        async () => test('connector-type').innerText(),
    );
    await page.keyboard.press('Escape');

    // -------------------------------------------------------- A template
    const had = await itemCount();
    await test('board-templates').click();
    await test('template-dialog').waitFor();
    check(
        'templates are offered as pictures of themselves',
        (await page.locator('.template-card').count()) >= 8 &&
            (await page.locator('.template-card canvas').count()) >= 8,
        `${await page.locator('.template-card').count()} cards`,
    );
    await test('template-data-model').click();
    await test('template-dialog').waitFor({ state: 'detached' });
    await soon(
        "a template lands as shapes and connectors of the board's own",
        async () => (await itemCount()) === had + 7,
        async () => `${had} → ${await itemCount()}`,
    );
    await soon(
        'with its shapes left selected, to be moved as one',
        async () => /4 items/i.test(await b.inspectorTitle()),
        () => b.inspectorTitle(),
    );

    // The view comes to rest on it before the picture is taken
    await steady(() =>
        page.locator('[data-camera]').first().getAttribute('data-camera'),
    );
    await until(async () => (await b.painted()) > 0);
    await page.screenshot({ path: `${SHOTS}/shapes.png` });
});
