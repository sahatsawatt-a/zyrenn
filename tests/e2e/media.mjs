// Pictures and formulae: every way one gets onto a board, and what the board
// keeps afterwards.
import { writeFile, mkdir } from 'node:fs/promises';
import { SHOTS, runBoard } from './harness.mjs';

const SAMPLE = `${SHOTS}/sample-upload.png`;

await mkdir(SHOTS, { recursive: true });
// a small square, so there is a real file to choose from this machine
await writeFile(
    SAMPLE,
    Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAYAAACqaXHeAAAAT0lEQVR42u3OMQ0AAAgDsOHf9HFhBRKq0K1' +
            'DAAABAgQIECBAgAABAgQIECBAgAABAgQIECBAgAABAgQIECBAgAABAgQIECBAgAABAgQI/Bs8sQABI9r6' +
            'twAAAABJRU5ErkJggg==',
        'base64',
    ),
);

await runBoard('/demo/konva', async (b) => {
    const {
        page,
        check,
        soon,
        draw,
        screenOf,
        label,
        itemCount,
        inspectorTitle,
        fields,
    } = b;

    const openPicker = async () => {
        await page.locator('[data-test="open-image-picker"]').click();
        await page.getByRole('heading', { name: 'Add image' }).waitFor();
    };

    const lastPictureSrc = () =>
        page.evaluate(() =>
            window.Konva.stages[0]
                .find('Image')
                .map((node) => node.image()?.src ?? '')
                .pop(),
        );

    // --- from this machine, by way of the Drive
    let before = await itemCount();
    await openPicker();
    check(
        'the picture dialog opens',
        await page.getByRole('heading', { name: 'Add image' }).isVisible(),
    );
    check(
        'it offers the three sources and markup',
        (await page
            .getByRole('button', { name: /Upload|From Drive|Link|SVG/ })
            .count()) >= 4,
    );
    await page.locator('input[type="file"]').setInputFiles(SAMPLE);
    await soon(
        'a picture from this machine lands on the board',
        async () => (await itemCount()) === before + 1,
        async () => `${before} → ${await itemCount()}`,
        { timeout: 20000 },
    );
    await soon(
        'and it is a picture',
        async () => (await inspectorTitle()).toLowerCase() === 'image',
        inspectorTitle,
    );
    await soon(
        'kept in the Drive, not stuffed into the board',
        async () => /\/drive\/files\//.test(await lastPictureSrc()),
        async () => (await lastPictureSrc()).slice(0, 52),
    );

    // --- from the Drive
    before = await itemCount();
    await openPicker();
    await page.getByRole('button', { name: 'From Drive' }).click();
    const thumbnail = page.locator('button img').first();
    await soon(
        'the Drive tab lists what is already there',
        async () => (await thumbnail.count()) > 0,
    );
    await thumbnail.click();
    await page
        .getByRole('button', { name: /^Insert/ })
        .first()
        .click();
    await soon(
        'one chosen from the Drive lands on the board',
        async () => (await itemCount()) === before + 1,
        async () => `${before} → ${await itemCount()}`,
        { timeout: 20000 },
    );

    // --- as SVG markup
    before = await itemCount();
    await openPicker();
    await page.getByRole('button', { name: 'SVG' }).click();
    await page.locator('[data-test="svg-markup"]').fill('just some words');
    await soon(
        'markup that is not SVG is refused',
        async () =>
            (await page.locator('[data-test="svg-add"]').isDisabled()) &&
            (await page.locator('[data-test="svg-problem"]').count()) > 0,
        () => page.locator('[data-test="svg-problem"]').innerText(),
    );
    await page
        .locator('[data-test="svg-markup"]')
        .fill(
            '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#ef4444" stroke-width="2"><circle cx="12" cy="12" r="9" /></svg>',
        );
    await page.locator('[data-test="svg-add"]').click();
    await soon(
        'a drawing pasted as markup lands on the board',
        async () => (await itemCount()) === before + 1,
        async () => `${before} → ${await itemCount()}`,
        { timeout: 20000 },
    );

    const box = await fields();
    await b.setField('width', 320);
    await soon(
        'a picture resizes like any other shape',
        async () => (await fields()).w === 320,
        async () => `${box.w} → ${(await fields()).w}`,
    );

    // --- a pasted screenshot goes to the Drive too
    before = await itemCount();
    await page.evaluate(async () => {
        const canvas = document.createElement('canvas');
        canvas.width = 40;
        canvas.height = 30;
        const context = canvas.getContext('2d');
        context.fillStyle = '#10b981';
        context.fillRect(0, 0, 40, 30);
        const blob = await new Promise((resolve) =>
            canvas.toBlob(resolve, 'image/png'),
        );
        const data = new DataTransfer();
        data.items.add(new File([blob], 'pasted.png', { type: 'image/png' }));
        window.dispatchEvent(
            new ClipboardEvent('paste', { clipboardData: data, bubbles: true }),
        );
    });
    await soon(
        'a pasted screenshot lands on the board',
        async () => (await itemCount()) === before + 1,
        async () => `${before} → ${await itemCount()}`,
        { timeout: 20000 },
    );
    await soon('and goes to the Drive rather than into the board', async () =>
        /\/drive\/files\//.test(await lastPictureSrc()),
    );
    await page.screenshot({ path: `${SHOTS}/media-pictures.png` });

    // --- formulae
    const formula = await draw('math', 0.3, 0.66, 0.14, 0.1);
    check(
        'the formula tool puts one on the board',
        (await inspectorTitle()).toLowerCase() === 'math',
        await inspectorTitle(),
    );
    const set = page.locator('.math-item .katex').first();
    await soon('KaTeX sets it', async () => (await set.count()) > 0);
    check(
        'and it reads as mathematics, not as LaTeX',
        !/\^/.test(await set.innerText()),
        (await set.innerText()).replace(/\s+/g, ' ').slice(0, 30),
    );

    const middle = await screenOf(
        formula.x + formula.w / 2,
        formula.y + formula.h / 2,
    );
    await label(middle, '\\frac{1}{2}\\int_0^\\infty e^{-x^2}\\,dx');
    await soon('typing new LaTeX re-sets it', async () =>
        /∫/.test(
            (
                await page.locator('.math-item .katex').first().innerText()
            ).replace(/\s+/g, ' '),
        ),
    );

    await label(middle, '\\frac{1}{');
    await page.waitForTimeout(500);
    check(
        'a mistake in it is shown, not thrown',
        (await page.locator('.math-item').count()) === 1,
    );

    const wide = (await page.locator('.math-item').first().boundingBox()).width;
    await page.locator('[data-test="zoom-in"]').click();
    await soon(
        'the formula follows the camera',
        async () =>
            Math.abs(
                (await page.locator('.math-item').first().boundingBox()).width -
                    wide,
            ) > 2,
    );
    await page.screenshot({ path: `${SHOTS}/media-formula.png` });
});
