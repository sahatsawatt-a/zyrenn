// Importing a Mermaid diagram: a flowchart arrives as shapes and connectors
// that behave like any others; anything else arrives as a picture.
import { SHOTS, runBoard } from './harness.mjs';

const CHART = `flowchart TD
    A[Order placed] --> B{In stock?}
    B -- yes --> C[(Warehouse)]
    B -- no --> D[/Back-order/]
    C --> E([Shipped])`;

await runBoard('/demo/konva', async (b) => {
    const {
        page,
        check,
        itemCount,
        inspectorTitle,
        layerNames,
        link,
        painted,
        rows,
        fields,
        screenOf,
    } = b;

    const paste = async (source) => {
        await page.locator('[data-test="open-image-picker"]').click();
        await page.waitForTimeout(400);
        await page.getByRole('button', { name: 'Mermaid' }).click();
        await page.waitForTimeout(300);
        await page.locator('[data-test="mermaid-source"]').fill(source);
        await page.locator('[data-test="mermaid-add"]').click();
        await page.waitForTimeout(2500);
    };

    // --- a flowchart comes in as items
    const before = await itemCount();
    await paste(CHART);
    check(
        'a flowchart lands on the board',
        (await itemCount()) === before + 9,
        `${before} → ${await itemCount()} (5 nodes + 4 lines)`,
    );

    const names = await layerNames();
    check(
        'the labels come with it',
        [
            'Order placed',
            'In stock?',
            'Warehouse',
            'Back-order',
            'Shipped',
        ].every((label) => names.some((name) => name.includes(label))),
        names.slice(0, 6).join(' | '),
    );
    check(
        'and so do the edge labels',
        names.some((name) => name === 'yes') &&
            names.some((name) => name === 'no'),
    );

    const kinds = await page.evaluate(() => {
        const stage = window.Konva.stages[0];
        return {
            polygons: stage.find('Line').filter((l) => l.closed()).length,
            paths: stage.find('Path').length,
            arrows: stage
                .find('Line')
                .filter((l) => !l.closed() && l.points().length >= 4).length,
        };
    });
    check(
        'the shapes are drawn as shapes, not as a picture',
        kinds.polygons > 0 && kinds.paths > 0,
        JSON.stringify(kinds),
    );
    await page.screenshot({ path: `${SHOTS}/mermaid-imported.png` });

    // --- the connectors are real: they say what they join, and follow it
    await rows().filter({ hasText: 'yes' }).first().click();
    await page.waitForTimeout(350);
    check(
        'an imported line is a connector',
        /arrow/i.test(await inspectorTitle()),
        await inspectorTitle(),
    );
    check('and it knows what it joins', /→/.test(await link()), await link());

    await page.keyboard.press('Escape');
    await rows().filter({ hasText: 'In stock?' }).first().click();
    await page.waitForTimeout(350);
    const decision = await fields();
    check(
        'a node can be picked up like any shape',
        decision.w > 0,
        `${decision.w}×${decision.h}`,
    );
    const drawn = await painted();
    await page.locator('[data-test="prop-x"]').fill(String(decision.x + 260));
    await page.locator('[data-test="prop-x"]').press('Enter');
    await page.waitForTimeout(500);
    check('moving it redraws the lines with it', (await painted()) !== drawn);
    await page.screenshot({ path: `${SHOTS}/mermaid-moved.png` });

    // --- dragging the lot keeps the lines on their shapes
    const lineStarts = () =>
        page.evaluate(() =>
            window.Konva.stages[0]
                .find('Line')
                .filter((l) => !l.closed() && l.points().length >= 4)
                .map((l) => [
                    Math.round(l.points()[0]),
                    Math.round(l.points()[1]),
                ]),
        );
    const shapeAt = () =>
        page.evaluate(() => {
            const group = window.Konva.stages[0]
                .find('Group')
                .filter((g) => g.id())
                .pop();
            const box = group.getClientRect();
            return { x: Math.round(box.x), y: Math.round(box.y) };
        });

    await rows().filter({ hasText: 'Order placed' }).first().click();
    await page.keyboard.press('Control+a').catch(() => {});
    const started = { lines: await lineStarts(), shape: await shapeAt() };
    const grab = await screenOf(
        (await fields()).x + 30,
        (await fields()).y + 20,
    );
    await page.mouse.move(grab.x, grab.y);
    await page.mouse.down();
    await page.mouse.move(grab.x + 60, grab.y + 40, { steps: 10 });
    await page.mouse.up();
    await page.waitForTimeout(500);
    const ended = { lines: await lineStarts(), shape: await shapeAt() };
    const moved = Math.min(started.lines.length, ended.lines.length);
    check(
        'dragging a shape does not shift the lines off their shapes',
        ended.lines
            .slice(0, moved)
            .every(
                ([x, y], index) =>
                    Math.abs(x - started.lines[index][0]) < 90 &&
                    Math.abs(y - started.lines[index][1]) < 90,
            ),
        `${JSON.stringify(started.lines[0])} → ${JSON.stringify(ended.lines[0])}`,
    );
    await page.screenshot({ path: `${SHOTS}/mermaid-dragged.png` });

    // --- a subgraph becomes a frame, which is a slide to present
    const frames = await page.evaluate(() =>
        Number(
            (document
                .querySelector('[data-test="inspector"]')
                .innerText.match(/(\d+) frames/) ?? [])[1] ?? 0,
        ),
    );
    await paste(`flowchart LR
    subgraph Fulfilment
      P[Pick] --> Q[Pack]
    end
    Q --> R[Post]`);
    const nowFrames = await page.evaluate(() =>
        Number(
            (document
                .querySelector('[data-test="inspector"]')
                .innerText.match(/(\d+) frames/) ?? [])[1] ?? 0,
        ),
    );
    check(
        'a subgraph comes in as a frame',
        nowFrames === frames + 1,
        `${frames} → ${nowFrames} frames`,
    );
    check(
        'and what is inside it is filed under it',
        (
            await page.locator('[data-test^="layer-group-"]').allInnerTexts()
        ).some((t) => /Fulfilment/.test(t)),
        (await page.locator('[data-test^="layer-group-"]').allInnerTexts())
            .map((t) => t.replace(/\s+/g, ' '))
            .join(' | '),
    );
    await page.screenshot({ path: `${SHOTS}/mermaid-subgraph.png` });

    // --- anything else comes in as a picture
    const shapes = await itemCount();
    await paste(
        'pie title Where the time goes\n "Drawing" : 70\n "Undoing" : 30',
    );
    check(
        'a diagram that is not a flowchart lands as one picture',
        (await itemCount()) === shapes + 1,
        `${shapes} → ${await itemCount()}`,
    );
    check(
        'and it is a picture',
        (await inspectorTitle()).toLowerCase() === 'image',
        await inspectorTitle(),
    );

    // --- nonsense is reported, not drawn
    const kept = await itemCount();
    await paste('flowchart TD\n A -->');
    check(
        'broken Mermaid adds nothing',
        (await itemCount()) === kept,
        `${kept} → ${await itemCount()}`,
    );
});
