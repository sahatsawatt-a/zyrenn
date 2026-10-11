// Files in a workflow, built in the editor: the Drive's steps are a group of
// their own in the palette; "Save to Drive" shows only the settings its
// source needs, and its folder is chosen from a dropdown of the Drive's own
// folders -- or written as a path, made when it isn't there. Run, the file is
// in the Drive and a later step reads it back.
import { SHOTS, runBoard, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const TITLE = `e2e files ${RUN}`;
const FOLDER = `e2e files ${RUN}`;
const NAME = `e2e-${RUN}.md`;
const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;

await runBoard(
    '/workflows',
    async ({ page, check, afterwards }) => {
        afterwards(() =>
            tinker(
                `$u = ${user}; $u->workflows()->where('title', '${TITLE}')->delete(); $u->driveFiles()->where('name', '${NAME}')->get()->each->delete(); $u->driveFolders()->where('name', 'Deeper')->delete(); $u->driveFolders()->where('name', '${FOLDER}')->delete();`,
            ),
        );

        // A folder already in the Drive, for the dropdown to offer
        tinker(
            `$u = ${user}; App\\Support\\Folders::ensure($u->driveFolders(), '${FOLDER}', $u); echo 'ok';`,
        );

        const settings = page.locator('[data-step-settings]');
        const step = (key) => page.locator(`[data-step="${key}"]`);
        const pick = async (where, option) => {
            await where.click();
            await page
                .getByRole('option', { name: option, exact: true })
                .click();
        };

        await page
            .getByRole('button', { name: /new workflow/i })
            .first()
            .click();
        await page.waitForURL(/\/workflows\/\w+$/);
        await page.getByLabel('Workflow title').fill(TITLE);

        // --- the palette ---
        const drive = page
            .locator('[data-step-palette] section')
            .filter({ has: page.locator('h3', { hasText: /^Drive$/ }) })
            .locator('[data-palette-step]');
        const kinds = await drive.evaluateAll((steps) =>
            steps.map((s) => s.getAttribute('data-palette-step')),
        );
        check(
            "the Drive's steps are a group of their own",
            kinds.join() === 'drive.save,drive.find,drive.read',
            kinds.join(),
        );

        // Each service's own API is its own step, in its own group
        const inGroup = (group) =>
            page
                .locator('[data-step-palette] section')
                .filter({ has: page.locator('h3', { hasText: group }) })
                .locator('[data-palette-step]')
                .evaluateAll((steps) =>
                    steps.map((s) => s.getAttribute('data-palette-step')),
                );
        const [telegram, line] = [
            await inGroup(/^Telegram$/),
            await inGroup(/^LINE$/),
        ];
        check(
            "each call to a service is a step of that service's own: getting a file, replying, pushing",
            telegram.includes('telegram.file') &&
                telegram.includes('telegram.send_file') &&
                ['line.file', 'line.reply', 'line.send'].every((kind) =>
                    line.includes(kind),
                ),
            `${telegram.join()} | ${line.join()}`,
        );

        const shown = async (name) =>
            (await settings.locator(`#setting-${name}`).count()) === 1;
        await page.locator('[data-palette-step="line.file"]').click();
        await step('line_get_the_file').waitFor();
        check(
            'it asks for its channel from a dropdown, and where to keep the file',
            (await shown('connection')) &&
                (await shown('folder')) &&
                (await settings
                    .locator('#setting-connection')
                    .getAttribute('data-pick-one')) !== null,
        );
        await settings.locator('[data-remove-step]').click();

        // --- Save to Drive: only what its source needs ---
        await page.locator('[data-palette-step="drive.save"]').click();
        await step('save_to_drive').waitFor();
        check(
            'a file at an address asks for the address',
            (await shown('url')) &&
                !(await shown('text')) &&
                !(await shown('connection')),
        );
        await pick(settings.locator('#setting-from'), 'Text written here');
        check(
            'text written here asks for the text instead',
            (await shown('text')) && !(await shown('url')),
        );
        await settings.locator('#setting-text').fill('kept by a workflow');
        await settings.locator('#setting-name').fill(NAME);

        // --- its folder: chosen, or a path of one's own ---
        const folder = settings.locator('#setting-folder');
        check(
            'left alone, a file goes to the top of the Drive',
            (await folder.innerText()).includes('The top of the Drive'),
            await folder.innerText(),
        );
        await pick(folder, FOLDER);
        check(
            "a folder is chosen from the Drive's own",
            (await folder.innerText()).includes(FOLDER) &&
                (await settings.getByLabel('Folder, by its path').count()) ===
                    0,
        );
        await pick(folder, 'Another, by its path…');
        await settings
            .getByLabel('Folder, by its path')
            .fill(`${FOLDER}/Deeper`);
        check(
            'or written as a path, to be made',
            (await settings.innerText()).includes(
                'Folders not there yet are made.',
            ),
        );
        await page.screenshot({ path: `${SHOTS}/workflow-files.png` });

        // --- read it back ---
        await page.locator('[data-palette-step="drive.read"]').click();
        await step('read_a_file').waitFor();
        await settings
            .locator('#setting-file')
            .fill('{{ node("save_to_drive").ref_id }}');
        await settings.getByRole('button', { name: 'Close' }).click();
        await page
            .getByRole('button', { name: 'Fit the workflow in view' })
            .click();
        await page.waitForTimeout(400);

        const join = async (from, to) => {
            await step(from)
                .locator('.vue-flow__handle.source')
                .first()
                .dragTo(step(to).locator('.vue-flow__handle.target'));
            await page.waitForTimeout(200);
        };
        await join('start', 'save_to_drive');
        await join('save_to_drive', 'read_a_file');
        await page
            .locator('[data-save-status]', { hasText: /^\s*Saved\s*$/ })
            .waitFor({ timeout: 10000 });
        check(
            'nothing stands in the way',
            (await page.locator('[data-problems]').innerText()).includes(
                'Ready',
            ),
            await page.locator('[data-problems]').innerText(),
        );

        // --- run ---
        await page.locator('[data-run]').click();
        await page
            .locator('[data-run-status]', { hasText: 'Succeeded' })
            .waitFor({ timeout: 30000 });
        await page.locator('[data-run-step="read_a_file"]').click();
        const output = await page.locator('[data-run-output]').innerText();
        check(
            'run, the file is saved and read back by a later step',
            output.includes('kept by a workflow') && output.includes(NAME),
            output.replace(/\s+/g, ' ').slice(0, 200),
        );

        const kept = tinker(
            `$f = ${user}->driveFiles()->where('name', '${NAME}')->with('folder.parent')->first(); echo $f ? $f->folder->parent->name.'/'.$f->folder->name : 'none';`,
        );
        check(
            'it is in the Drive, in the folder made for it',
            kept === `${FOLDER}/Deeper`,
            kept,
        );
        await page.screenshot({ path: `${SHOTS}/workflow-files-run.png` });

        // --- in a chat: a picture a workflow wrote is drawn, not named ---
        const PNG =
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        const roomRef = tinker(
            `$u = ${user}; $t = tempnam(sys_get_temp_dir(), 'e2e'); file_put_contents($t, base64_decode('${PNG}')); $f = App\\Models\\Drive\\DriveFile::keep($t, '${NAME}.png', $u); unlink($t); $r = new App\\Models\\Chat\\ChatRoom; $r->kind = 'personal'; $r->user_id = $u->id; $r->title = '${TITLE}'; $r->save(); $r->messages()->create(['role' => 'workflow', 'content' => '', 'meta' => ['name' => 'Ada (LINE)', 'outside' => true, 'file' => $f->ref_id]]); echo $r->ref_id;`,
        );
        afterwards(() =>
            tinker(
                `$u = ${user}; App\\Models\\Chat\\ChatRoom::where('ref_id', '${roomRef}')->delete(); $u->driveFiles()->where('name', '${NAME}.png')->get()->each->delete();`,
            ),
        );
        await page.goto(new URL(`/chats/${roomRef}`, page.url()).href, {
            waitUntil: 'networkidle',
        });
        const picture = page.locator(
            '[data-chat-workflow] [data-chat-file] img',
        );
        await picture.waitFor({ timeout: 10000 });
        check(
            'a file a workflow wrote in a chat is shown under its sender, a picture as a picture',
            (await picture.evaluate(
                (img) => img.complete && img.naturalWidth > 0,
            )) &&
                (
                    await page.locator('[data-chat-workflow]').innerText()
                ).includes('Ada (LINE)'),
        );
        await page.screenshot({ path: `${SHOTS}/workflow-files-chat.png` });
    },
    { canvas: false },
);
