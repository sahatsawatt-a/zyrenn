// Videos: played in the Drive, in a note and on a board, from a file that was
// only ever uploaded once. Needs ffmpeg, to make that file.
import { execFileSync } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';
import { SHOTS, removeAt, runBoard } from './harness.mjs';

const APP = process.env.APP_URL ?? 'http://127.0.0.1:8001';
const CLIP = `${SHOTS}/clip.mp4`;
const NAME = `clip-${Date.now().toString().slice(-6)}.mp4`;

await mkdir(SHOTS, { recursive: true });
// Six seconds of moving colour bars and a tone: enough to seek about in
execFileSync('ffmpeg', [
    '-loglevel',
    'error',
    '-y',
    '-f',
    'lavfi',
    '-i',
    'testsrc2=size=640x360:rate=25:duration=6',
    '-f',
    'lavfi',
    '-i',
    'sine=frequency=440:duration=6',
    '-c:v',
    'libx264',
    '-pix_fmt',
    'yuv420p',
    '-c:a',
    'aac',
    '-shortest',
    '-movflags',
    '+faststart',
    CLIP,
]);

await runBoard(
    '/drive',
    async (b) => {
        const { page, check, ready, itemCount, inspectorTitle, afterwards } = b;

        const remove = (path) => removeAt(page, path);

        // ------------------------------------------------------------ Drive
        const chooser = page.waitForEvent('filechooser');
        await page
            .getByRole('button', { name: /^upload/i })
            .first()
            .click();
        await (
            await chooser
        ).setFiles({
            name: NAME,
            mimeType: 'video/mp4',
            buffer: (await import('node:fs')).readFileSync(CLIP),
        });
        await page.getByText(NAME).first().waitFor({ timeout: 30000 });

        const card = page.locator('[title="Play"]').first();
        // The thumbnail asks for a moment just in; the file is the rest
        const fileUrl = (await card.locator('video').getAttribute('src')).split(
            '#',
        )[0];
        const ref = fileUrl.match(/files\/(\w+)/)[1];
        afterwards(() => remove(`/drive/files/${ref}`));

        await page.waitForTimeout(1500);
        check(
            'a video in the Drive shows a frame of itself',
            (await card
                .locator('video')
                .evaluate((video) => video.readyState)) >= 1,
        );

        await card.click();
        const big = page.locator('[data-test="viewer-video"]');
        await big.waitFor();
        await page.waitForTimeout(1800);
        check(
            'opening it plays it',
            (await big.evaluate((video) => video.currentTime)) > 0.5,
            `${(await big.evaluate((video) => video.currentTime)).toFixed(2)}s`,
        );

        const sought = await big.evaluate(
            (video) =>
                new Promise((resolve) => {
                    video.addEventListener(
                        'seeked',
                        () => resolve(video.currentTime),
                        { once: true },
                    );
                    video.currentTime = 4.5;
                }),
        );
        check(
            'and it can be sought, a range of the file at a time',
            sought >= 4.4,
            `${sought.toFixed(2)}s`,
        );
        await page.screenshot({ path: `${SHOTS}/video-drive.png` });
        await page.keyboard.press('Escape');
        check('Escape puts it away', (await big.count()) === 0);

        // ------------------------------------------------------------ Board
        await page.goto(`${APP}/boards`, { waitUntil: 'networkidle' });
        await page.getByRole('button', { name: /new board/i }).click();
        await page.waitForURL(/boards\/\w+/, { timeout: 30000 });
        const boardRef = page.url().split('/').pop();
        afterwards(() => remove(`/boards/${boardRef}`));
        await ready();
        const boardTitle = `Videos ${Date.now().toString().slice(-6)}`;
        await page.locator('[data-test="board-title"]').fill(boardTitle);

        const before = await itemCount();
        await page.locator('[data-test="open-video-picker"]').click();
        await page.getByRole('heading', { name: 'Add video' }).waitFor();
        await page.getByRole('button', { name: 'From Drive' }).click();
        await page.locator(`button[title="${NAME}"]`).click();
        await page.getByRole('button', { name: /^Insert/ }).click();
        await page.waitForTimeout(1500);
        check(
            'a video from the Drive lands on the board',
            (await itemCount()) === before + 1 &&
                (await inspectorTitle()).toLowerCase() === 'video',
            await inspectorTitle(),
        );

        // Where the video item is, on screen, from the canvas itself
        const where = () =>
            page.evaluate(() => {
                const stage = window.Konva.stages[0];
                const image = stage
                    .find('Image')
                    .find((node) => node.image() instanceof HTMLVideoElement);
                const group = image?.getParent();
                const box = group?.getClientRect();
                const canvas = stage.container().getBoundingClientRect();

                return box
                    ? {
                          x: canvas.x + box.x,
                          y: canvas.y + box.y,
                          w: box.width,
                          h: box.height,
                          playing: !image.image().paused,
                          time: image.image().currentTime,
                      }
                    : null;
            });

        let video = await where();
        check(
            'the board draws a frame of it',
            !!video,
            video ? `${Math.round(video.w)}×${Math.round(video.h)}` : 'none',
        );
        check(
            'in the video’s own shape',
            video && Math.abs(video.w / video.h - 16 / 9) < 0.05,
        );

        const centre = { x: video.x + video.w / 2, y: video.y + video.h / 2 };
        const corner = {
            x: video.x + video.w * 0.25,
            y: video.y + video.h * 0.3,
        };

        await page.mouse.click(corner.x, corner.y);
        await page.waitForTimeout(500);
        check(
            'a click on the picture only chooses it',
            !(await where()).playing,
        );
        check(
            'and brings up its controls',
            await page.locator('[data-test="video-controls"]').isVisible(),
        );

        await page.mouse.click(centre.x, centre.y);
        await page.waitForTimeout(1500);
        video = await where();
        check(
            'the play button plays it on the board',
            video.playing && video.time > 0.5,
            `${video.time.toFixed(2)}s`,
        );
        const shotA = await page.screenshot({
            clip: { x: corner.x - 40, y: corner.y - 40, width: 80, height: 80 },
        });
        await page.waitForTimeout(600);
        const shotB = await page.screenshot({
            clip: { x: corner.x - 40, y: corner.y - 40, width: 80, height: 80 },
        });
        check('and the canvas keeps redrawing it', !shotA.equals(shotB));
        await page.screenshot({ path: `${SHOTS}/video-board-playing.png` });

        await page.locator('[data-test="video-toggle"]').click();
        await page.waitForTimeout(400);
        check('its controls pause it', !(await where()).playing);

        await page.locator('[data-test="video-seek"]').fill('5');
        await page.waitForTimeout(700);
        check(
            'and seek it',
            Math.abs((await where()).time - 5) < 0.3,
            `${(await where()).time.toFixed(2)}s`,
        );

        await page.mouse.dblclick(corner.x, corner.y);
        await big.waitFor({ timeout: 3000 });
        await page.waitForTimeout(400);
        check(
            'a double-click watches it full size, from the same moment',
            (await big.evaluate((video) => video.currentTime)) >= 4.9,
        );
        await page.keyboard.press('Escape');

        // A video file dropped straight onto the board goes up to the Drive
        const dropped = await itemCount();
        await page.evaluate(async (url) => {
            const blob = await (await fetch(url)).blob();
            const data = new DataTransfer();
            data.items.add(
                new File([blob], 'dropped.mp4', { type: 'video/mp4' }),
            );
            document.querySelector('[data-zoom]').dispatchEvent(
                new DragEvent('drop', {
                    dataTransfer: data,
                    bubbles: true,
                    cancelable: true,
                }),
            );
        }, fileUrl);
        await page.waitForTimeout(4000);
        check(
            'a video file dropped on the board lands there too',
            (await itemCount()) === dropped + 1,
            `${dropped} → ${await itemCount()}`,
        );
        const droppedSrc = await page.evaluate(() =>
            window.Konva.stages[0]
                .find('Image')
                .map((node) => node.image())
                .filter((image) => image instanceof HTMLVideoElement)
                .map((video) => video.getAttribute('src'))
                .pop(),
        );
        check(
            'by way of the Drive',
            /\/drive\/files\//.test(droppedSrc ?? '') && droppedSrc !== fileUrl,
            droppedSrc,
        );
        const droppedRef = droppedSrc?.match(/files\/(\w+)/)?.[1];
        if (droppedRef) {
            afterwards(() => remove(`/drive/files/${droppedRef}`));
        }

        // Deleting one stops it: nothing plays on where nobody can see it
        await page.mouse.click(centre.x, centre.y);
        await page.waitForTimeout(800);
        const element = await page.evaluateHandle(() =>
            window.Konva.stages[0]
                .find('Image')
                .map((node) => node.image())
                .find((image) => !image.paused),
        );
        await page.keyboard.press('Delete');
        await page.waitForTimeout(500);
        check(
            'deleting a playing video stops it',
            await element.evaluate((video) => video.paused),
        );
        await page.screenshot({ path: `${SHOTS}/video-board.png` });
        await page.waitForTimeout(2500);

        // ------------------------------------------------------------- Note
        // Played in a note; and a board shown in one plays its own
        await page.goto(`${APP}/notes`, { waitUntil: 'networkidle' });
        await page.getByRole('button', { name: /new note/i }).click();
        await page.waitForURL(/notes\/\w+/);
        const noteRef = page.url().split('/').pop();
        afterwards(() => remove(`/notes/${noteRef}`));
        await page.waitForSelector('.tiptap');
        await page.waitForTimeout(700);

        await page.locator('.tiptap').click();
        await page.keyboard.type('/video');
        await page.waitForTimeout(600);
        await page.keyboard.press('Enter');
        await page
            .getByRole('heading', { name: 'Add video' })
            .waitFor({ timeout: 5000 });
        check('/video asks for a video', true);

        await page.getByRole('button', { name: 'From Drive' }).click();
        const pick = page.locator(`button[title="${NAME}"]`);
        await pick.waitFor({ timeout: 8000 });
        check(
            'the Drive tab lists videos, not pictures',
            (await page.locator('[role="dialog"] button img').count()) === 0,
        );
        await pick.dblclick();

        const inNote = page.locator('[data-test="note-video"]');
        await inNote.waitFor({ timeout: 5000 });
        await page.waitForTimeout(1200);
        check(
            'the chosen video sits in the note',
            (await inNote.evaluate((video) => video.duration)) > 5,
            `${(await inNote.evaluate((video) => video.duration)).toFixed(1)}s long`,
        );

        await inNote.evaluate((video) => video.play());
        await page.waitForTimeout(1500);
        check(
            'and plays there',
            (await inNote.evaluate((video) => video.currentTime)) > 0.5,
        );

        await inNote.hover();
        await page.locator('.image-toolbar [title="View full size"]').click();
        await big.waitFor();
        await page.waitForTimeout(500);
        check(
            'full size carries on from where it had got to',
            (await big.evaluate((video) => video.currentTime)) > 1,
            `${(await big.evaluate((video) => video.currentTime)).toFixed(2)}s`,
        );
        check(
            'and the small one stops meanwhile',
            await inNote.evaluate((video) => video.paused),
        );
        await page.keyboard.press('Escape');
        await page.screenshot({ path: `${SHOTS}/video-note.png` });

        await page.waitForTimeout(2500);
        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForSelector('.tiptap');
        await page.waitForTimeout(1200);
        check(
            'it is still there after a reload',
            (await inNote.count()) === 1 &&
                (await inNote.getAttribute('src')) === fileUrl,
        );

        // Dragging along its timeline seeks; it doesn't pick the block up
        const layout = () =>
            page
                .locator('.tiptap > *')
                .evaluateAll((blocks) =>
                    blocks.map((block) =>
                        block.querySelector('video') ? 'video' : block.tagName,
                    ),
                );
        const blocksBefore = await layout();
        const frame = await inNote.boundingBox();
        const lineY = frame.y + frame.height - 22;
        await inNote.hover();
        await page.waitForTimeout(400);
        await page.mouse.move(frame.x + frame.width * 0.15, lineY);
        await page.mouse.down();
        await page.mouse.move(frame.x + frame.width * 0.75, lineY, {
            steps: 8,
        });
        await page.mouse.up();
        await page.waitForTimeout(600);
        check(
            'dragging along its timeline seeks it',
            (await inNote.evaluate((video) => video.currentTime)) > 3,
            `${(await inNote.evaluate((video) => video.currentTime)).toFixed(2)}s`,
        );
        check(
            'and leaves the block where it was',
            JSON.stringify(await layout()) === JSON.stringify(blocksBefore),
        );
        await inNote.evaluate((video) => video.pause());

        // The board from before, shown in the note: its video plays on a click
        await page.locator('.tiptap').press('Control+End');
        await page.keyboard.press('Enter');
        await page.keyboard.type('/board');
        await page.waitForTimeout(600);
        await page.keyboard.press('Enter');
        await page
            .locator('[data-test="board-choose"]')
            .selectOption({ label: boardTitle });
        await page.waitForTimeout(2500);
        const embedded = () =>
            page.evaluate(() => {
                const stage =
                    window.Konva.stages[window.Konva.stages.length - 1];
                const image = stage
                    .find('Image')
                    .find((node) => node.image() instanceof HTMLVideoElement);
                const box = image?.getParent().getClientRect();
                const canvas = stage.container().getBoundingClientRect();

                return box
                    ? {
                          x: canvas.x + box.x + box.width / 2,
                          y: canvas.y + box.y + box.height / 2,
                          playing: !image.image().paused,
                      }
                    : null;
            });
        const shown = await embedded();
        check('a board shown in the note draws its video', !!shown);
        await page.mouse.click(shown.x, shown.y);
        await page.waitForTimeout(1200);
        check(
            'and plays it when clicked, though nothing else there moves',
            (await embedded()).playing,
        );
        await page.mouse.click(shown.x, shown.y);
        await page.screenshot({ path: `${SHOTS}/video-note-board.png` });

        // A PDF can't play it, but the note still prints
        await page.waitForTimeout(2500);
        const pdf = await page.evaluate(async (ref) => {
            const response = await fetch(`/notes/${ref}/pdf`);
            const bytes = new Uint8Array(await response.arrayBuffer());

            return {
                status: response.status,
                type: response.headers.get('Content-Type'),
                bytes: Array.from(bytes),
            };
        }, noteRef);
        // Kept to be looked at: the video should read as a line saying so
        await writeFile(`${SHOTS}/video-note.pdf`, Buffer.from(pdf.bytes));
        check(
            'a note with a video in it still exports to PDF',
            pdf.status === 200 && /pdf/.test(pdf.type ?? ''),
            `${pdf.status} ${pdf.type}`,
        );
    },
    { canvas: false },
);
