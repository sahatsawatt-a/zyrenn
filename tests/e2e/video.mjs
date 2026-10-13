// Videos: played in the Drive, in a note and on a board, from a file that was
// only ever uploaded once. Needs ffmpeg, to make that file.
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdir, writeFile } from 'node:fs/promises';
import {
    SHOTS,
    boardKept,
    open,
    removeAt,
    reopen,
    runBoard,
    saved,
    slash,
    steady,
    until,
} from './harness.mjs';

const APP = process.env.APP_URL ?? 'http://127.0.0.1:8001';
const CLIP = `${SHOTS}/clip.mp4`;
const NAME = `clip-${Date.now().toString().slice(-6)}.mp4`;

await mkdir(SHOTS, { recursive: true });
// Six seconds of moving colour bars and a tone: enough to seek about in.
// Made once, and kept for the runs after.
if (!existsSync(CLIP)) {
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
}

await runBoard(
    '/drive',
    async (b) => {
        const {
            page,
            check,
            soon,
            ready,
            itemCount,
            inspectorTitle,
            afterwards,
        } = b;

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

        // This upload's own card, never one an earlier run left behind
        const card = page
            .locator('div.group', { has: page.locator(`[title="${NAME}"]`) })
            .locator('[title="Play"]');
        // The thumbnail asks for a moment just in; the file is the rest
        const fileUrl = (await card.locator('video').getAttribute('src')).split(
            '#',
        )[0];
        const ref = fileUrl.match(/files\/(\w+)/)[1];
        afterwards(() => remove(`/drive/files/${ref}`));

        await soon('a video in the Drive shows a frame of itself', () =>
            card.locator('video').evaluate((video) => video.readyState >= 1),
        );

        await card.click();
        const big = page.locator('[data-test="viewer-video"]');
        await big.waitFor();
        await soon(
            'opening it plays it',
            () => big.evaluate((video) => video.currentTime > 0.5),
            async () =>
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
        await open(page, `${APP}/boards`);
        await page
            .getByRole('button', { name: /new board/i })
            .first()
            .click();
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
        await soon(
            'a video from the Drive lands on the board',
            async () =>
                (await itemCount()) === before + 1 &&
                (await inspectorTitle()).toLowerCase() === 'video',
            inspectorTitle,
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

        // (once it has its size: the clicks below go by where it is)
        let video = await steady(where);
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
        await soon('a click on the picture brings up its controls', () =>
            page.locator('[data-test="video-controls"]').isVisible(),
        );
        check('and only chooses it', !(await where()).playing);

        await page.mouse.click(centre.x, centre.y);
        await soon(
            'the play button plays it on the board',
            async () => {
                video = await where();

                return video.playing && video.time > 0.5;
            },
            () => `${video.time.toFixed(2)}s`,
        );
        const patch = () =>
            page.screenshot({
                clip: {
                    x: corner.x - 40,
                    y: corner.y - 40,
                    width: 80,
                    height: 80,
                },
            });
        const shotA = await patch();
        await soon(
            'and the canvas keeps redrawing it',
            async () => !shotA.equals(await patch()),
        );
        await page.screenshot({ path: `${SHOTS}/video-board-playing.png` });

        await page.locator('[data-test="video-toggle"]').click();
        await soon(
            'its controls pause it',
            async () => !(await where()).playing,
        );

        await page.locator('[data-test="video-seek"]').fill('5');
        await soon(
            'and seek it',
            async () => Math.abs((await where()).time - 5) < 0.3,
            async () => `${(await where()).time.toFixed(2)}s`,
        );

        await page.mouse.dblclick(corner.x, corner.y);
        await big.waitFor({ timeout: 3000 });
        await soon(
            'a double-click watches it full size, from the same moment',
            () => big.evaluate((video) => video.currentTime >= 4.9),
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
        // (it has to go up to the Drive first)
        await soon(
            'a video file dropped on the board lands there too',
            async () => (await itemCount()) === dropped + 1,
            async () => `${dropped} → ${await itemCount()}`,
            { timeout: 20000 },
        );
        let droppedSrc;
        await soon(
            'by way of the Drive',
            async () => {
                droppedSrc = await page.evaluate(() =>
                    window.Konva.stages[0]
                        .find('Image')
                        .map((node) => node.image())
                        .filter((image) => image instanceof HTMLVideoElement)
                        .map((video) => video.getAttribute('src'))
                        .pop(),
                );

                return (
                    /\/drive\/files\//.test(droppedSrc ?? '') &&
                    droppedSrc !== fileUrl
                );
            },
            () => droppedSrc,
        );
        const droppedRef = droppedSrc?.match(/files\/(\w+)/)?.[1];
        if (droppedRef) {
            afterwards(() => remove(`/drive/files/${droppedRef}`));
        }

        // Deleting one stops it: nothing plays on where nobody can see it --
        // once the one just dropped has its frame and its place
        await steady(() =>
            page.evaluate(() =>
                window.Konva.stages[0]
                    .find('Image')
                    .filter((node) => node.image() instanceof HTMLVideoElement)
                    .map((node) => [
                        node.image().readyState >= 2,
                        node.getParent().getClientRect(),
                    ]),
            ),
        );
        await page.mouse.click(centre.x, centre.y);
        // (whichever of them is under the click)
        await until(() =>
            page.evaluate(() =>
                window.Konva.stages[0]
                    .find('Image')
                    .some((node) => node.image().paused === false),
            ),
        );
        const element = await page.evaluateHandle(() =>
            window.Konva.stages[0]
                .find('Image')
                .map((node) => node.image())
                .find((image) => !image.paused),
        );
        await page.keyboard.press('Delete');
        await soon('deleting a playing video stops it', () =>
            element.evaluate((video) => video.paused),
        );
        await page.screenshot({ path: `${SHOTS}/video-board.png` });
        // (kept by the app before leaving: the note below draws it from there)
        await saved(page);
        await until(
            async () =>
                Object.values(await boardKept(page, boardRef)).filter(
                    (item) => item.kind === 'video',
                ).length === 1,
        );

        // ------------------------------------------------------------- Note
        // Played in a note; and a board shown in one plays its own
        await open(page, `${APP}/notes`);
        await page.getByRole('button', { name: /new note/i }).click();
        await page.waitForURL(/notes\/\w+/);
        const noteRef = page.url().split('/').pop();
        afterwards(() => remove(`/notes/${noteRef}`));
        await page.waitForSelector('.tiptap');
        await page.waitForTimeout(700);

        await page.locator('.tiptap').click();
        await slash(page, 'video');
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
        await soon(
            'the chosen video sits in the note',
            () => inNote.evaluate((video) => video.duration > 5),
            async () =>
                `${(await inNote.evaluate((video) => video.duration)).toFixed(1)}s long`,
        );

        await inNote.evaluate((video) => video.play());
        await soon('and plays there', () =>
            inNote.evaluate((video) => video.currentTime > 0.5),
        );

        await inNote.hover();
        await page.locator('.image-toolbar [title="View full size"]').click();
        await big.waitFor();
        await soon(
            'full size carries on from where it had got to',
            () => big.evaluate((video) => video.currentTime > 1),
            async () =>
                `${(await big.evaluate((video) => video.currentTime)).toFixed(2)}s`,
        );
        check(
            'and the small one stops meanwhile',
            await inNote.evaluate((video) => video.paused),
        );
        await page.keyboard.press('Escape');
        await page.screenshot({ path: `${SHOTS}/video-note.png` });

        await saved(page);
        await reopen(page);
        await page.waitForSelector('.tiptap');
        await soon(
            'it is still there after a reload',
            async () =>
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
        await soon(
            'dragging along its timeline seeks it',
            () => inNote.evaluate((video) => video.currentTime > 3),
            async () =>
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
        await slash(page, 'board');
        await page
            .locator('[data-test="board-choose"]')
            .selectOption({ label: boardTitle });
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
        const shown = await soon(
            'a board shown in the note draws its video',
            embedded,
        );
        await page.mouse.click(shown.x, shown.y);
        await soon(
            'and plays it when clicked, though nothing else there moves',
            async () => (await embedded()).playing,
        );
        await page.mouse.click(shown.x, shown.y);
        await page.screenshot({ path: `${SHOTS}/video-note-board.png` });

        // A PDF can't play it, but the note still prints
        await saved(page);
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
