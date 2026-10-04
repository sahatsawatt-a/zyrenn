// Importing a Mermaid diagram: whatever kind it is, it lands as one picture --
// light on white, its labels drawn -- that moves and resizes like any other.
import { SHOTS, runBoard } from './harness.mjs';

const CHART = `flowchart TD
    A[Order placed] --> B{In stock?}
    B -- yes --> C[(Warehouse)]
    B -- no --> D[/Back-order/]
    C --> E([Shipped])`;

await runBoard('/demo/konva', async (b) => {
    const { page, check, itemCount, inspectorTitle, fields, shot } = b;

    const paste = async (source) => {
        await page.locator('[data-test="open-image-picker"]').click();
        await page.waitForTimeout(400);
        await page.getByRole('button', { name: 'Mermaid' }).click();
        await page.waitForTimeout(300);
        await page.locator('[data-test="mermaid-source"]').fill(source);
        await page.locator('[data-test="mermaid-add"]').click();
        await page.waitForTimeout(2500);
    };

    /** How many distinct colours a board box is painted in. */
    const colours = async (box) => {
        const png = await shot(box);

        return page.evaluate(async (data) => {
            const picture = new Image();
            picture.src = `data:image/png;base64,${data}`;
            await picture.decode();
            const canvas = document.createElement('canvas');
            canvas.width = picture.width;
            canvas.height = picture.height;
            const context = canvas.getContext('2d');
            context.drawImage(picture, 0, 0);
            const { data: pixels } = context.getImageData(
                0,
                0,
                canvas.width,
                canvas.height,
            );
            const seen = new Set();

            for (let index = 0; index < pixels.length; index += 16) {
                seen.add(
                    `${pixels[index] >> 4},${pixels[index + 1] >> 4},${pixels[index + 2] >> 4}`,
                );
            }

            return seen.size;
        }, png.toString('base64'));
    };

    // --- a flowchart comes in as one picture, not as shapes
    const before = await itemCount();
    await paste(CHART);
    check(
        'a flowchart lands on the board as one item',
        (await itemCount()) === before + 1,
        `${before} → ${await itemCount()}`,
    );
    check(
        'and it is a picture',
        (await inspectorTitle()).toLowerCase() === 'image',
        await inspectorTitle(),
    );
    const picture = await fields();
    check(
        'sized to the diagram',
        picture.h > picture.w,
        `${picture.w}×${picture.h} (a top-down chart is taller than wide)`,
    );
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    check(
        'with its boxes and labels drawn',
        (await colours({
            x: picture.x,
            y: picture.y,
            w: picture.w,
            h: picture.h,
        })) > 4,
    );
    await page.screenshot({ path: `${SHOTS}/mermaid-imported.png` });

    // --- any other kind of diagram, the same
    const charts = await itemCount();
    await paste(
        'pie title Where the time goes\n "Drawing" : 70\n "Undoing" : 30',
    );
    check(
        'a diagram that is not a flowchart lands as one picture too',
        (await itemCount()) === charts + 1 &&
            (await inspectorTitle()).toLowerCase() === 'image',
        `${charts} → ${await itemCount()}, ${await inspectorTitle()}`,
    );

    // --- nonsense is reported, not drawn
    const kept = await itemCount();
    await paste('flowchart TD\n A -->');
    check(
        'broken Mermaid adds nothing',
        (await itemCount()) === kept,
        `${kept} → ${await itemCount()}`,
    );
    check(
        'and says why',
        (await page.locator('[data-sonner-toast]').count()) > 0,
    );
});
