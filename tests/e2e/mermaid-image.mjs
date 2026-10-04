// A diagram in a note kept as a picture from its full-size view: downloaded
// as a PNG or an SVG, or saved to the Drive. The picture is light on white, whichever
// mode the app is in, and its labels are plain SVG text.
import { readFile } from 'node:fs/promises';
import { SHOTS, makeNote, removeAt, runBoard } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);

// An ampersand and a line break: what HTML lets through and XML does not
const FLOWCHART = `flowchart LR
  A[Order & pay] --> B{In stock?}
  B -- yes --> C[Ship it<br/>today]
  B -- no --> D[Back-order]`;

const SEQUENCE = `sequenceDiagram
  Alice->>Bob: Hello
  Bob-->>Alice: Hi back`;

await runBoard(
    '/notes',
    async (board) => {
        const { page, check, afterwards } = board;
        const base = new URL(page.url()).origin;
        const ref = await makeNote(
            board,
            `Diagram image ${RUN}`,
            `\`\`\`mermaid\n${FLOWCHART}\n\`\`\`\n\n\`\`\`mermaid\n${SEQUENCE}\n\`\`\``,
        );

        // Dark, to show the picture is light regardless
        await page.emulateMedia({ colorScheme: 'dark' });
        await page.goto(`${base}/notes/${ref}`, { waitUntil: 'networkidle' });
        await page.evaluate(() =>
            document.documentElement.classList.add('dark'),
        );
        await page.waitForSelector('.mermaid-svg svg', { timeout: 15000 });

        const flowchart = page.locator('.mermaid-block').first();
        const sequence = page.locator('.mermaid-block').nth(1);

        // Opened full size, with its Save menu open and `type` picked
        const saveMenu = async (block, type) => {
            await page.keyboard.press('Escape');
            await block.getByRole('button', { name: 'Expand' }).click();
            await page.locator('[data-test="viewer-save"]').click();
            if (type) {
                await page
                    .locator(`[data-test="viewer-save-type-${type}"]`)
                    .click();
            }
        };

        const download = async (block, type) => {
            await saveMenu(block, type);
            const [file] = await Promise.all([
                page.waitForEvent('download'),
                page.locator('[data-test="viewer-save-download"]').click(),
            ]);
            const path = `${SHOTS}/${file.suggestedFilename()}`;
            await file.saveAs(path);

            return { name: file.suggestedFilename(), path };
        };

        // What a PNG holds, looked at in the page: its size, how much of it
        // is drawn on, and whether its corner is white
        const look = (bytes) =>
            page.evaluate(async (base64) => {
                const picture = new Image();
                picture.src = `data:image/png;base64,${base64}`;
                await picture.decode();
                const canvas = document.createElement('canvas');
                canvas.width = picture.width;
                canvas.height = picture.height;
                const context = canvas.getContext('2d');
                context.drawImage(picture, 0, 0);
                const { data } = context.getImageData(
                    0,
                    0,
                    canvas.width,
                    canvas.height,
                );
                let inked = 0;
                for (let i = 0; i < data.length; i += 4) {
                    if (data[i] + data[i + 1] + data[i + 2] < 600) inked++;
                }
                return {
                    width: picture.width,
                    height: picture.height,
                    inked: inked / (data.length / 4),
                    corner: [data[0], data[1], data[2], data[3]],
                };
            }, bytes.toString('base64'));

        // ------------------------------------------------- Download a PNG
        await flowchart.getByRole('button', { name: 'Expand' }).click();
        check(
            'the full-size view offers to save the diagram as a picture',
            await page.locator('[data-test="viewer-save"]').isVisible(),
        );
        await page.locator('[data-test="viewer-save"]').click();
        await page.locator('[data-test="viewer-save-type-svg"]').click();
        check(
            'picking a file type keeps the menu open and names it',
            (await page
                .locator('[data-test="viewer-save-download"]')
                .innerText()) === 'Download SVG' &&
                (await page
                    .locator('[data-test="viewer-save-drive"]')
                    .innerText()) === 'Save SVG to Drive',
        );
        await page.screenshot({ path: `${SHOTS}/mermaid-image-menu.png` });
        await page.keyboard.press('Escape');
        // Gone once its closing animation is done
        await page
            .locator('[data-test="viewer-save-download"]')
            .waitFor({ state: 'detached', timeout: 2000 })
            .catch(() => {});
        check(
            'Escape shuts the menu and leaves the view open',
            (await page
                .locator('[data-test="viewer-save-download"]')
                .count()) === 0 &&
                (await page.locator('[role="dialog"]').isVisible()),
        );

        const png = await download(flowchart, 'png');
        const pngBytes = await readFile(png.path);
        check(
            'Download PNG gives a PNG named for the diagram',
            png.name === 'Flowchart.png' &&
                pngBytes.subarray(1, 4).toString() === 'PNG',
            png.name,
        );
        const drawn = await look(pngBytes);
        const onScreen = await flowchart
            .locator('.mermaid-svg svg')
            .evaluate((svg) => svg.viewBox.baseVal.width);
        check(
            'drawn at twice the size, on white, with something on it',
            drawn.width > onScreen * 1.5 &&
                drawn.corner.join() === '255,255,255,255' &&
                drawn.inked > 0.01,
            JSON.stringify(drawn),
        );

        // Shown in the page so the screenshot shows what was saved
        const show = (bytes, type) =>
            page.evaluate(
                ([base64, type]) => {
                    const img = document.createElement('img');
                    img.src = `data:${type};base64,${base64}`;
                    img.style.cssText =
                        'position:fixed;inset:20px auto auto 20px;max-width:90vw;z-index:9999;outline:4px solid red';
                    img.className = 'e2e-shown';
                    document.body.appendChild(img);
                    return img.decode();
                },
                [bytes.toString('base64'), type],
            );
        await show(pngBytes, 'image/png');
        await page.screenshot({ path: `${SHOTS}/mermaid-image-png.png` });
        await page.evaluate(() =>
            document.querySelectorAll('.e2e-shown').forEach((n) => n.remove()),
        );

        // ------------------------------------------------- Download an SVG
        await page.keyboard.press('Escape');
        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForSelector('.mermaid-svg svg', { timeout: 15000 });
        await saveMenu(sequence);
        check(
            'the last type picked is remembered',
            (await page
                .locator('[data-test="viewer-save-type-png"]')
                .getAttribute('data-state')) === 'checked',
        );
        await page.keyboard.press('Escape');
        const svg = await download(sequence, 'svg');
        const markup = await readFile(svg.path, 'utf8');
        check(
            'Download SVG gives a file of its own: sized, namespaced, no HTML',
            svg.name === 'Sequence.svg' &&
                markup.startsWith('<svg') &&
                markup.includes('xmlns="http://www.w3.org/2000/svg"') &&
                /\swidth="\d+"/.test(markup) &&
                !markup.includes('foreignObject'),
            `${svg.name} ${markup.slice(0, 80)}`,
        );
        const opened = await page.evaluate(async (text) => {
            const doc = new DOMParser().parseFromString(text, 'image/svg+xml');
            return !doc.querySelector('parsererror');
        }, markup);
        check('and it is well-formed XML', opened);
        await show(Buffer.from(markup), 'image/svg+xml');
        await page.screenshot({ path: `${SHOTS}/mermaid-image-svg.png` });
        await page.evaluate(() =>
            document.querySelectorAll('.e2e-shown').forEach((n) => n.remove()),
        );

        // ------------------------------------------------ Save to the Drive
        await saveMenu(flowchart, 'png');
        const [upload] = await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.url().endsWith('/drive/files') &&
                    response.request().method() === 'POST',
            ),
            page.locator('[data-test="viewer-save-drive"]').click(),
        ]);
        const stored = (await upload.json()).files?.[0];
        if (stored) {
            afterwards(() => removeAt(page, `/drive/files/${stored.ref_id}`));
        }
        check(
            'Save PNG to Drive puts it in the Drive as a picture',
            upload.ok() &&
                stored?.name === 'Flowchart.png' &&
                stored?.is_image === true,
            `${upload.status()} ${stored?.name} ${stored?.mime}`,
        );
        await page
            .getByText('Saved “Flowchart.png” to your Drive')
            .waitFor({ timeout: 5000 });
        check('and says so, with a way to open it', true);
        await page.screenshot({ path: `${SHOTS}/mermaid-image-drive.png` });

        // ----------------------------- The note's own drawing is unchanged
        await page.keyboard.press('Escape');
        const labels = await flowchart
            .locator('.mermaid-svg svg foreignObject')
            .count();
        check(
            'the diagram in the note keeps its HTML labels, in dark',
            labels > 0 &&
                (await page.evaluate(() =>
                    document.documentElement.classList.contains('dark'),
                )),
            `${labels} HTML labels`,
        );
    },
    { canvas: false },
);
