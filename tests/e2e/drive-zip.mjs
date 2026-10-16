// A Drive folder downloaded as a zip, from its menu: its files and the
// folders in it, as they sit; and from the header, the folder open.
import { execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { open, runBoard, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const WHO = process.env.E2E_EMAIL ?? 'test@example.com';

await runBoard(
    '/drive',
    async (b) => {
        const { page, check, afterwards } = b;
        const base = new URL(page.url()).origin;
        const user = `App\\Models\\User::where('email', '${WHO}')->first()`;
        const dir = mkdtempSync(join(tmpdir(), 'drive-zip-'));
        const top = `Trip ${RUN}`;

        // A folder with a picture, and a folder in it with a film
        const [folder, inner] = tinker(
            `$u = ${user};
            $top = $u->driveFolders()->create(['name' => '${top}']);
            $in = $u->driveFolders()->create(['name' => 'Day 1', 'parent_id' => $top->id]);
            $keep = function ($from, $name, $folder) use ($u) { $tmp = tempnam(sys_get_temp_dir(), 'f'); copy(public_path($from), $tmp); App\\Models\\Drive\\DriveFile::keep($tmp, $name, $u, $folder, $u); };
            $keep('apple-touch-icon.png', 'cover.png', $top);
            $keep('images/guide/tour-board.webm', 'walk.webm', $in);
            echo $top->ref_id.' '.$in->ref_id;`.replace(/\n\s*/g, ' '),
        ).split(' ');

        afterwards(() => {
            rmSync(dir, { recursive: true, force: true });
            tinker(
                `App\\Models\\Drive\\DriveFolder::where('ref_id', '${folder}')->first()?->deleteTree(); echo 'ok';`,
            );
        });

        /** Click, and the file it downloads, saved. */
        const downloaded = async (click) => {
            const [download] = await Promise.all([
                page.waitForEvent('download', { timeout: 60000 }),
                click(),
            ]);
            const path = join(dir, download.suggestedFilename());
            await download.saveAs(path);

            return path;
        };
        const listed = (path) =>
            execFileSync('unzip', ['-Z1', path])
                .toString()
                .trim()
                .split('\n')
                .sort();

        // ------------------------------------------- from the folder's menu
        await open(page, `${base}/drive`);
        const card = page.locator('a', { hasText: top }).locator('..');
        await card.waitFor();
        await card.getByRole('button', { name: 'Folder actions' }).click();
        const fromMenu = await downloaded(() =>
            page.getByRole('menuitem', { name: 'Download as zip' }).click(),
        );
        check(
            'the menu downloads the folder as a zip of its name',
            fromMenu.endsWith(`${top}.zip`),
            fromMenu,
        );
        check(
            'it holds its picture, and the folder in it with its film',
            JSON.stringify(listed(fromMenu)) ===
                JSON.stringify([`${top}/Day 1/walk.webm`, `${top}/cover.png`]),
            listed(fromMenu).join(', '),
        );
        check(
            'every file in it is whole',
            /No errors detected/.test(
                execFileSync('unzip', ['-t', fromMenu]).toString(),
            ),
        );

        // ----------------------------------------------- from the header
        await open(page, `${base}/drive?folder=${inner}`);
        const fromHeader = await downloaded(() =>
            page.locator('[data-test="drive-zip"]').click(),
        );
        check(
            'inside a folder, the header downloads that folder',
            fromHeader.endsWith('Day 1.zip') &&
                JSON.stringify(listed(fromHeader)) ===
                    JSON.stringify(['Day 1/walk.webm']),
            listed(fromHeader).join(', '),
        );
    },
    { canvas: false },
);
