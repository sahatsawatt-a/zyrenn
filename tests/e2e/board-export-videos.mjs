// A board with a video exported: a PDF or a picture can only show a still of
// it, so the export comes as a zip -- what was drawn, and the board's video
// beside it, to play. A board without videos still comes as its PDF.
import { execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { open, runBoard, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const WHO = process.env.E2E_EMAIL ?? 'test@example.com';

await runBoard(
    '/boards',
    async (b) => {
        const { page, check, soon, afterwards } = b;
        const base = new URL(page.url()).origin;
        const user = `App\\Models\\User::where('email', '${WHO}')->first()`;
        const as = `App\\Mcp\\Servers\\UserServer::actingAs(${user})`;
        const dir = mkdtempSync(join(tmpdir(), 'board-zip-'));

        // A video in the Drive (one of the guide's), and two boards: one
        // showing it, one without
        const [withVideo, without, film] = tinker(
            `$tmp = tempnam(sys_get_temp_dir(), 'film'); copy(public_path('images/guide/tour-board.webm'), $tmp);
            $film = App\\Models\\Drive\\DriveFile::keep($tmp, 'Demo ${RUN}.webm', ${user}, null, ${user});
            ${as}->tool(App\\Mcp\\Tools\\Boards\\CreateBoard::class, ['title' => 'Video board ${RUN}', 'items' => [
                ['id' => 's1', 'kind' => 'frame', 'x' => 0, 'y' => 0, 'width' => 1280, 'height' => 720, 'text' => 'Watch'],
                ['id' => 'v', 'kind' => 'video', 'x' => 80, 'y' => 80, 'width' => 640, 'height' => 360, 'src' => '/drive/files/'.$film->ref_id],
                ['id' => 'r', 'kind' => 'rect', 'x' => 800, 'y' => 80, 'width' => 300, 'height' => 160, 'text' => 'Beside it'],
            ]]);
            ${as}->tool(App\\Mcp\\Tools\\Boards\\CreateBoard::class, ['title' => 'Plain board ${RUN}', 'items' => [
                ['id' => 'r', 'kind' => 'rect', 'x' => 0, 'y' => 0, 'width' => 300, 'height' => 160, 'text' => 'Only this'],
            ]]);
            echo implode(' ', [${user}->boards()->where('title', 'Video board ${RUN}')->value('ref_id'), ${user}->boards()->where('title', 'Plain board ${RUN}')->value('ref_id'), $film->ref_id]);`.replace(
                /\n\s*/g,
                ' ',
            ),
        ).split(' ');

        afterwards(() => {
            rmSync(dir, { recursive: true, force: true });
            tinker(
                `App\\Models\\Board\\Board::whereIn('ref_id', ['${withVideo}', '${without}'])->get()->each->delete();
                 App\\Models\\Drive\\DriveFile::where('ref_id', '${film}')->first()?->delete(); echo 'ok';`.replace(
                    /\n\s*/g,
                    ' ',
                ),
            );
        });

        /** Export the board open as a PDF, and the file it hands over. */
        const exported = async () => {
            await page.locator('[data-test="board-export"]:visible').click();
            await page.locator('[data-test="pdf-preview-choose-pdf"]').click();
            await page.locator('[data-test="pdf-preview-frame"]').waitFor();

            const [download] = await Promise.all([
                page.waitForEvent('download'),
                page.locator('[data-test="pdf-preview-download"]').click(),
            ]);
            const path = join(dir, download.suggestedFilename());
            await download.saveAs(path);

            return path;
        };

        // ------------------------------------------------- with a video
        await open(page, `${base}/boards/${withVideo}`);
        await b.ready();
        const zipped = await exported();
        await soon(
            'the dialog says the export comes as a zip with the video',
            async () =>
                /zip, with the board’s video/.test(
                    await page
                        .locator('[data-test="pdf-preview-bundle"]')
                        .innerText(),
                ),
        );
        check(
            'the download is a zip named after the board',
            zipped.endsWith(`Video board ${RUN}.zip`),
            zipped,
        );
        const listed = execFileSync('unzip', ['-Z1', zipped])
            .toString()
            .trim()
            .split('\n');
        check(
            'it holds the PDF, and the video beside it',
            listed.includes(`Video board ${RUN}.pdf`) &&
                listed.includes(`videos/Demo ${RUN}.webm`) &&
                listed.length === 2,
            listed.join(', '),
        );
        check(
            'the video in it is the one in the Drive, whole',
            execFileSync('unzip', ['-p', zipped, `videos/Demo ${RUN}.webm`], {
                maxBuffer: 16 * 1024 * 1024,
            }).length === 1584449,
        );
        check(
            'every file in it is as it was written',
            /No errors detected/.test(
                execFileSync('unzip', ['-t', zipped]).toString(),
            ),
        );
        await page.keyboard.press('Escape');

        // ------------------------------------------------- without one
        await open(page, `${base}/boards/${without}`);
        await b.ready();
        const plain = await exported();
        check(
            'a board without videos still downloads as its PDF',
            plain.endsWith(`Plain board ${RUN}.pdf`) &&
                (await page
                    .locator('[data-test="pdf-preview-bundle"]')
                    .count()) === 0,
            plain,
        );
    },
    { canvas: false },
);
