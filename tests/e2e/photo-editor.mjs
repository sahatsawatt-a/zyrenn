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
    /** `points` are read by name too, and `alpha` is the top-left corner's. */
    const read = (src, points = []) =>
        page.evaluate(
            async ({ src, points }) => {
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

                const light = (x, y) => {
                    const [r, g, b] = context.getImageData(
                        Math.floor(canvas.width * x),
                        Math.floor(canvas.height * y),
                        1,
                        1,
                    ).data;

                    return r + g + b;
                };

                return {
                    // The very corner against the middle of its quarter
                    darkCorner: light(0.01, 0.01) < light(0.25, 0.25) - 60,
                    alpha: context.getImageData(1, 1, 1, 1).data[3],
                    at: points.map(([x, y]) => at(x, y)).join(' '),
                    size: `${canvas.width}×${canvas.height}`,
                    corners: [
                        at(0.25, 0.25),
                        at(0.75, 0.25),
                        at(0.25, 0.75),
                        at(0.75, 0.75),
                    ].join(' '),
                };
            },
            { src, points },
        );

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
    const cropStyle = () => byTest('photo-crop').getAttribute('style');
    const squared = await cropStyle();

    // Undone, and done again
    await page.keyboard.press('Control+z');
    await page.waitForTimeout(200);
    const undone = await cropStyle();
    await byTest('photo-redo').click();
    await page.waitForTimeout(200);
    check(
        'Ctrl+Z undoes the crop, and redo puts it back',
        /height: 100%/.test(undone) && (await cropStyle()) === squared,
        `${undone} → ${await cropStyle()}`,
    );

    // Held up against the original: not turned, nothing cropped
    const box = await byTest('photo-picture').boundingBox();
    const compare = await byTest('photo-compare').boundingBox();
    await page.mouse.move(compare.x + 10, compare.y + 10);
    await page.mouse.down();
    await page.waitForTimeout(250);
    const held = await byTest('photo-picture').boundingBox();
    const cropWhileHeld = await byTest('photo-crop').count();
    await page.mouse.up();
    await page.waitForTimeout(250);
    check(
        'holding compare shows the original, and letting go the edit',
        held.width > held.height &&
            box.height > box.width &&
            cropWhileHeld === 0 &&
            (await byTest('photo-crop').count()) === 1,
        `${Math.round(box.width)}×${Math.round(box.height)} → ${Math.round(held.width)}×${Math.round(held.height)}`,
    );
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

    // Arrow keys move the crop box, each press a step undo takes back
    await byTest('photo-crop').focus();
    for (let i = 0; i < 3; i++) {
        await page.keyboard.press('Shift+ArrowDown');
    }
    const nudged = await cropStyle();
    for (let i = 0; i < 3; i++) {
        await page.keyboard.press('Control+z');
    }
    await page.waitForTimeout(150);
    check(
        'arrow keys nudge the crop, and undo takes the nudges back',
        !/top: 25%/.test(nudged) && /top: 25%/.test(await cropStyle()),
        nudged,
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
    await byTest('look-mono').click();
    await page.waitForTimeout(300);
    check(
        'a look sets the light, and shows as chosen',
        (await byTest('look-mono').getAttribute('aria-pressed')) === 'true' &&
            (await byTest('light-saturation').inputValue()) === '0' &&
            (await byTest('look-mono').locator('img').count()) === 1,
    );
    await byTest('light-vignette').evaluate((input) => {
        input.value = '100';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${SHOTS}/photo-editor-note.png` });
    await byTest('photo-save').click();
    await byTest('photo-editor').waitFor({ state: 'detached' });
    await page.waitForTimeout(1500);

    const noteSrc = await noteImage.getAttribute('src');
    const grey = await read(noteSrc);
    check(
        'the note shows the picture cropped 16:9, black and white, its corners darkened',
        noteSrc !== original.src.replace(base, '') &&
            grey.size === '356×200' &&
            grey.corners === 'grey grey grey grey' &&
            grey.darkCorner,
        `${grey.size}: ${grey.corners}, dark corner ${grey.darkCorner}`,
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

    // ---- in the Drive ----

    await page.goto(`${base}/drive`, { waitUntil: 'networkidle' });
    const card = (name) =>
        page.locator('div.group', {
            has: page.locator(`[title="${name}"]`),
        });
    check(
        'the Drive says which pictures are edited copies, and of what',
        (await byTest('edited-from').first().innerText()).includes(
            'quarters.png',
        ) &&
            /2 edited copies/.test(
                await card('quarters.png')
                    .locator('[data-test="edit-count"]')
                    .innerText(),
            ),
    );

    await card('quarters.png').hover();
    await card('quarters.png')
        .getByRole('button', { name: 'File actions' })
        .click();
    await byTest('edit-photo').click();
    await byTest('photo-crop').waitFor();
    await byTest('turn-left').click();
    await byTest('photo-save').click();
    await byTest('photo-editor').waitFor({ state: 'detached' });
    await page.waitForTimeout(1500);
    check(
        'a picture is edited from the Drive, and its copy is listed',
        /3 edited copies/.test(
            await card('quarters.png')
                .locator('[data-test="edit-count"]')
                .innerText(),
        ) &&
            (await page.evaluate(() => document.body.style.pointerEvents)) !==
                'none',
    );
    await page.screenshot({ path: `${SHOTS}/photo-editor-drive.png` });

    // ---- hiding an area ----

    await card('quarters.png').hover();
    await card('quarters.png')
        .getByRole('button', { name: 'File actions' })
        .click();
    await byTest('edit-photo').click();
    await byTest('photo-crop').waitFor();
    await page.waitForTimeout(400);

    // A black box, dragged onto the red quarter
    await byTest('hide-fill').click();
    const area = await byTest('hidden-0').boundingBox();
    const whole = await byTest('photo-picture').boundingBox();
    await page.mouse.move(area.x + area.width / 2, area.y + area.height / 2);
    await page.mouse.down();
    await page.mouse.move(
        whole.x + whole.width * 0.25,
        whole.y + whole.height * 0.25,
        { steps: 8 },
    );
    await page.mouse.up();

    // A second, taken away with Delete -- and back, and away again
    const areas = '[role="group"][data-test^="hidden-"]';
    await byTest('hide-pixelate').click();
    await byTest('hidden-1').focus();
    await page.keyboard.press('Delete');
    const afterDelete = await page.locator(areas).count();
    await page.keyboard.press('Control+z');
    const afterUndo = await page.locator(areas).count();
    await page.keyboard.press('Control+Shift+z');
    check(
        'a hidden area is added, taken away with Delete, and undone',
        afterDelete === 1 &&
            afterUndo === 2 &&
            (await page.locator(areas).count()) === 1,
        `${afterDelete} → ${afterUndo}`,
    );

    // An oval, to cover a face: the box's corners are left as they were
    await byTest('hidden-0').click();
    await byTest('hidden-shape-circle').click();

    // It goes round with the picture: top left, turned right, is top right
    await byTest('turn-right').click();
    await page.waitForTimeout(300);
    const turnedArea = await byTest('hidden-0').getAttribute('style');
    check(
        'the hidden area turns with the picture',
        Number(turnedArea.match(/left: ([\d.]+)%/)[1]) > 50,
        turnedArea,
    );
    await page.screenshot({ path: `${SHOTS}/photo-editor-hide.png` });
    await byTest('photo-save').click();
    await byTest('photo-editor').waitFor({ state: 'detached' });
    await page.waitForTimeout(1500);

    const hiddenCopy = await page
        .locator('div.group', {
            has: page.locator('[data-test="edited-from"]'),
        })
        .first()
        .locator('img')
        .getAttribute('src');
    // The box was 120×40 round the red quarter's middle; turned right, its
    // corner is at 84% across, 11% down -- outside the oval
    const covered = await read(hiddenCopy, [[0.84, 0.11]]);
    check(
        'the black oval is in the saved picture, where it was put, and only the oval',
        covered.size === '200×400' &&
            covered.corners === 'blue grey yellow green' &&
            covered.at === 'red',
        `${covered.size}: ${covered.corners}; box corner ${covered.at}`,
    );

    // ---- straightened, cut to a circle, and saved smaller ----

    await card('quarters.png').hover();
    await card('quarters.png')
        .getByRole('button', { name: 'File actions' })
        .click();
    await byTest('edit-photo').click();
    await byTest('photo-crop').waitFor();
    await page.waitForTimeout(400);
    await byTest('straighten').evaluate((input) => {
        input.value = '10';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await byTest('shape-circle').click();
    await page.waitForTimeout(400);
    check(
        'a circle crops square, and the crop box shows the circle',
        (await byTest('ratio-1:1').getAttribute('class')).includes(
            'bg-primary',
        ) &&
            /border-radius: 50%/.test(
                await byTest('photo-crop').getAttribute('style'),
            ),
    );
    await page.screenshot({ path: `${SHOTS}/photo-editor-circle.png` });
    await byTest('photo-save').click();
    await byTest('photo-editor').waitFor({ state: 'detached' });
    await page.waitForTimeout(1500);

    const newest = () =>
        page
            .locator('div.group', {
                has: page.locator('[data-test="edited-from"]'),
            })
            .first()
            .locator('img')
            .getAttribute('src');
    // Turned 10° clockwise about the middle, the line between red and green
    // leans right at the top: just right of the middle, near the top, is red
    const round = await read(await newest(), [[0.525, 0.12]]);
    check(
        'straightened clockwise, and cut to a see-through circle',
        round.size === '200×200' &&
            round.alpha === 0 &&
            round.corners === 'red green blue yellow' &&
            round.at === 'red',
        `${round.size}, corner alpha ${round.alpha}: ${round.corners}; top middle ${round.at}`,
    );

    // A large picture, saved small
    const large = await page.evaluate(async () => {
        const canvas = document.createElement('canvas');
        canvas.width = 3000;
        canvas.height = 1500;
        const context = canvas.getContext('2d');
        context.fillStyle = '#336699';
        context.fillRect(0, 0, 3000, 1500);
        const blob = await new Promise((done) =>
            canvas.toBlob(done, 'image/jpeg', 0.8),
        );
        const body = new FormData();
        body.append('files[]', blob, 'large.jpg');
        const token = decodeURIComponent(
            document.cookie.match(/XSRF-TOKEN=([^;]+)/)[1],
        );
        const response = await fetch('/drive/files', {
            method: 'POST',
            body,
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token },
        });

        return (await response.json()).files[0].name;
    });
    await page.reload({ waitUntil: 'networkidle' });
    await card(large).hover();
    await card(large).getByRole('button', { name: 'File actions' }).click();
    await byTest('edit-photo').click();
    await byTest('photo-crop').waitFor();
    await byTest('save-size').click();
    const smallOption = await byTest('save-size-1024').innerText();
    await byTest('save-size-1024').click();
    await byTest('photo-save').click();
    await byTest('photo-editor').waitFor({ state: 'detached' });
    await page.waitForTimeout(1500);
    const small = await read(await newest());
    check(
        'saved smaller, at the size the choice said',
        small.size === '1024×512' && /1024 × 512/.test(smallOption),
        `${small.size}; offered "${smallOption.replace(/\s+/g, ' ')}"`,
    );
});
