// The photo editor, from a board and from a note: a picture cropped to a
// ratio, turned, flipped and taken to grey, read back pixel by pixel; the
// original kept, the edit picked up again where it was left, and undone.
//
//     node tests/e2e/photo-editor.mjs
import { makeNote, runBoard, SHOTS, tinker } from './harness.mjs';

const me = "App\\Models\\User::where('email', 'test@example.com')->first()";

await runBoard('/demo/konva', async (ctx) => {
    const { page, check, afterwards, inspectorTitle } = ctx;
    const base = new URL(page.url()).origin;
    const byTest = (id) => page.locator(`[data-test="${id}"]`);

    // Whatever this run puts in the Drive goes again
    const lastFile = tinker(`echo ${me}->driveFiles()->max('id') ?? 0;`);
    afterwards(() =>
        tinker(
            `${me}->driveFiles()->where('id', '>', ${Number(lastFile)})->get()->each->delete(); echo 'ok';`,
        ),
    );

    // A 400×200 picture in four colours: red, green / blue, yellow
    const quarters = Buffer.from(
        await page.evaluate(() => {
            const canvas = document.createElement('canvas');
            canvas.width = 400;
            canvas.height = 200;
            const context = canvas.getContext('2d');
            [
                ['#ff0000', 0, 0],
                ['#00ff00', 200, 0],
                ['#0000ff', 0, 100],
                ['#ffff00', 200, 100],
            ].forEach(([colour, x, y]) => {
                context.fillStyle = colour;
                context.fillRect(x, y, 200, 100);
            });

            return canvas.toDataURL('image/png').split(',')[1];
        }),
        'base64',
    );

    /** The colour at each quarter of a picture, by name, and its size. */
    const read = (src) =>
        page.evaluate(async (src) => {
            const image = new Image();
            image.src = src;
            await image.decode();
            const canvas = document.createElement('canvas');
            canvas.width = image.naturalWidth;
            canvas.height = image.naturalHeight;
            const context = canvas.getContext('2d', {
                willReadFrequently: true,
            });
            context.drawImage(image, 0, 0);
            const name = ([r, g, b]) =>
                Math.abs(r - g) < 12 && Math.abs(g - b) < 12
                    ? 'grey'
                    : r > 200 && g > 200
                      ? 'yellow'
                      : r > 200
                        ? 'red'
                        : g > 200
                          ? 'green'
                          : b > 200
                            ? 'blue'
                            : `${r},${g},${b}`;
            const at = (x, y) =>
                name(
                    context.getImageData(
                        Math.floor(canvas.width * x),
                        Math.floor(canvas.height * y),
                        1,
                        1,
                    ).data,
                );

            return {
                size: `${canvas.width}×${canvas.height}`,
                corners: [
                    at(0.25, 0.25),
                    at(0.75, 0.25),
                    at(0.25, 0.75),
                    at(0.75, 0.75),
                ].join(' '),
            };
        }, src);

    // ---- on a board ----

    await page.locator('[data-test="open-image-picker"]').click();
    await page.waitForTimeout(400);
    await page.locator('input[type="file"]').setInputFiles({
        name: 'quarters.png',
        mimeType: 'image/png',
        buffer: quarters,
    });
    await page.waitForTimeout(2500);
    check(
        'a picture on the board is chosen',
        (await inspectorTitle()).toLowerCase() === 'image',
    );

    const picture = () =>
        page.evaluate(() => {
            const node = window.Konva.stages[0].find('Image').pop();

            return {
                src: node.image()?.src ?? '',
                width: Math.round(node.width()),
                height: Math.round(node.height()),
            };
        });
    const original = await picture();

    await byTest('edit-photo').click();
    await byTest('photo-crop').waitFor();
    await page.waitForTimeout(400);
    check(
        'the editor opens on the picture, nothing cut off',
        (await byTest('photo-save').isDisabled()) &&
            (await byTest('photo-crop').getAttribute('style')).includes(
                'width: 100%',
            ),
    );

    await byTest('turn-right').click();
    await byTest('flip-x').click();
    await byTest('ratio-1:1').click();
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${SHOTS}/photo-editor-board.png` });
    await byTest('photo-save').click();
    await byTest('photo-editor').waitFor({ state: 'detached' });
    await page.waitForTimeout(1500);

    const edited = await picture();
    const made = await read(edited.src);
    check(
        'the board shows a new picture from the Drive',
        edited.src !== original.src && /\/drive\/files\//.test(edited.src),
    );
    check(
        'turned right, flipped and cropped square, pixel for pixel',
        made.size === '200×200' && made.corners === 'red blue green yellow',
        `${made.size}: ${made.corners}`,
    );
    check(
        'the picture on the board takes the new shape, as wide as before',
        edited.width === original.width && edited.height === edited.width,
        `${original.width}×${original.height} → ${edited.width}×${edited.height}`,
    );
    check(
        'the original is still there',
        (await read(original.src)).corners === 'red green blue yellow',
    );

    // Opened again: the whole original, with the same edit to carry on from
    await byTest('edit-photo').click();
    await byTest('photo-crop').waitFor();
    await page.waitForTimeout(500);
    const crop = await byTest('photo-crop').getAttribute('style');
    const stage = await byTest('photo-crop')
        .locator('..')
        .evaluate((element) => [element.offsetWidth, element.offsetHeight]);
    check(
        'the edit is picked up where it was left, on the whole picture',
        /height: 50%/.test(crop) && stage[1] > stage[0],
        `${crop} on ${stage.join('×')}`,
    );
    await byTest('photo-reset').click();
    await byTest('photo-save').click();
    await byTest('photo-editor').waitFor({ state: 'detached' });
    await page.waitForTimeout(1200);
    check(
        'undone to the original, it is the original again',
        (await picture()).src === original.src,
    );

    // ---- in a note ----

    const ref = await makeNote(
        ctx,
        `Photo editor ${Date.now().toString().slice(-6)}`,
        `A picture:\n\n![quarters](${original.src.replace(base, '')})\n`,
    );
    await page.goto(`${base}/notes/${ref}`, { waitUntil: 'networkidle' });
    await page.waitForSelector('.ProseMirror img');
    await page.waitForTimeout(1200);
    const noteImage = page.locator('.ProseMirror img').first();
    await noteImage.hover();
    await byTest('edit-photo').click();
    await byTest('photo-crop').waitFor();
    await page.waitForTimeout(400);

    // A handle dragged in by half the picture's width
    const handle = await byTest('crop-e').boundingBox();
    const frame = await byTest('photo-crop').boundingBox();
    await page.mouse.move(handle.x + 7, handle.y + 7);
    await page.mouse.down();
    await page.mouse.move(handle.x + 7 - frame.width / 2, handle.y + 7, {
        steps: 8,
    });
    await page.mouse.up();
    check(
        'a crop handle drags the side in',
        /width: 5\d(\.\d+)?%/.test(
            await byTest('photo-crop').getAttribute('style'),
        ),
        await byTest('photo-crop').getAttribute('style'),
    );

    await byTest('ratio-16:9').click();
    await byTest('light-saturation').evaluate((input) => {
        input.value = '0';
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${SHOTS}/photo-editor-note.png` });
    await byTest('photo-save').click();
    await byTest('photo-editor').waitFor({ state: 'detached' });
    await page.waitForTimeout(1500);

    const noteSrc = await noteImage.getAttribute('src');
    const grey = await read(noteSrc);
    check(
        'the note shows the picture cropped 16:9, in grey',
        noteSrc !== original.src.replace(base, '') &&
            grey.size === '356×200' &&
            grey.corners === 'grey grey grey grey',
        `${grey.size}: ${grey.corners}`,
    );

    // Kept with the note
    await page.waitForTimeout(2500);
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForSelector('.ProseMirror img');
    check(
        'and the note keeps it after a reload',
        (await page.locator('.ProseMirror img').first().getAttribute('src')) ===
            noteSrc,
    );
});
