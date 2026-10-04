// A note's toolbar: it stays at the top as the note scrolls, and its ⋯ menu
// widens the page, copies the note's reference, says how long the note is
// (Thai, written without spaces, counted by word too), moves it to a folder
// and deletes it. Makes a note over MCP and a folder with `docker exec` into
// E2E_APP_CONTAINER (zyrenn-app-1), and takes both away again.
import { SHOTS, makeNote, runBoard, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const USER = "App\\Models\\User::where('email', 'test@example.com')->first()";

await runBoard(
    '/notes',
    async (ctx) => {
        const { page, check, afterwards } = ctx;
        const filler = Array.from(
            { length: 40 },
            (_, i) => `Filler line ${i} of the note.`,
        ).join('\n\n');
        const ref = await makeNote(
            ctx,
            `Toolbar ${RUN}`,
            `Alpha beta gamma.\n\nสวัสดีครับ\n\n${filler}\n`,
        );
        const folder = tinker(
            `echo ${USER}->noteFolders()->create(['name' => 'Toolbar folder ${RUN}'])->ref_id;`,
        );
        afterwards(() =>
            tinker(
                `App\\Models\\Note\\NoteFolder::where('ref_id', '${folder}')->first()?->delete();`,
            ),
        );

        const base = new URL(page.url()).origin;
        await page.goto(`${base}/notes/${ref}`, { waitUntil: 'networkidle' });
        await page.waitForSelector('[data-test="note-toolbar"]');
        await page.waitForTimeout(1000);
        const bar = page.locator('[data-test="note-toolbar"]');

        check(
            'status says it is saved',
            /Saved/.test(
                await page.locator('[data-test="note-status"]').innerText(),
            ),
        );

        // ------------------------------------------------ Stays in reach
        await page.mouse.wheel(0, 2500);
        await page.waitForTimeout(500);
        const top = (await bar.boundingBox()).y;
        check(
            'the bar stays at the top as the note scrolls',
            Math.abs(top) < 2,
            `top ${top}`,
        );
        await page.screenshot({ path: `${SHOTS}/note-toolbar-scrolled.png` });
        await page.mouse.wheel(0, -5000);
        await page.waitForTimeout(300);

        // ------------------------------------------------ How long it is
        await page.locator('[data-test="note-more"]').click();
        const info = await page.locator('[data-test="note-info"]').innerText();
        // 3 + 2 Thai words (สวัสดี ครับ) + 40 lines × 6 words
        check(
            'words counted, Thai too',
            info.includes(`${3 + 2 + 40 * 6} words`),
            info.replace(/\n/g, ' | '),
        );
        await page.screenshot({ path: `${SHOTS}/note-toolbar-menu.png` });

        // ------------------------------------------------ Full width
        const width = () =>
            page
                .locator('.ProseMirror')
                .evaluate((el) => el.getBoundingClientRect().width);
        const narrow = await width();
        await page.locator('[data-test="note-wide"]').click();
        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);
        check(
            'full width widens the page',
            (await width()) > narrow + 100,
            `${narrow} → ${await width()}`,
        );
        await page.locator('[data-test="note-more"]').click();
        await page.locator('[data-test="note-wide"]').click();
        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);
        check('and back', Math.abs((await width()) - narrow) < 2);

        // ------------------------------------------------ Copy reference
        await page.locator('[data-test="note-more"]').click();
        await page.locator('[data-test="note-ref-id"]').click();
        await page.getByText('Reference copied').waitFor({ timeout: 3000 });
        check('reference copied', true);

        // ------------------------------------------------ Move
        await page.locator('[data-test="note-more"]').click();
        await page.locator('[data-test="note-move"]').click();
        const dialog = page.getByRole('dialog');
        await dialog.getByRole('combobox').click();
        await page
            .getByRole('option', { name: `Toolbar folder ${RUN}` })
            .click();
        await dialog.getByRole('button', { name: 'Move' }).click();
        await page
            .getByText(`Moved to Toolbar folder ${RUN}`)
            .waitFor({ timeout: 5000 });
        const stored = tinker(
            `echo App\\Models\\Note\\Note::where('ref_id', '${ref}')->first()->folder?->ref_id;`,
        );
        check('moved to the folder', stored === folder, stored);
        check(
            'the breadcrumbs follow',
            (await page.locator('header').innerText()).includes(
                `Toolbar folder ${RUN}`,
            ),
        );

        // ------------------------------------------------ Delete
        await page.locator('[data-test="note-more"]').click();
        await page.locator('[data-test="note-delete"]').click();
        await page.locator('[data-test="note-delete-confirm"]').click();
        await page
            .waitForURL(
                (url) =>
                    url.pathname === '/notes' &&
                    url.searchParams.get('folder') === folder,
                { timeout: 5000 },
            )
            .catch(() => {});
        // Its own delete reaches this page live too, and isn't taken for someone else's
        check(
            'no "someone deleted" for one\'s own delete',
            !(await page.getByText('Someone deleted this note').count()),
        );
        check(
            'deleted, and back to its folder',
            new URL(page.url()).searchParams.get('folder') === folder &&
                tinker(
                    `echo App\\Models\\Note\\Note::where('ref_id', '${ref}')->count();`,
                ) === '0',
            page.url(),
        );
    },
    { canvas: false },
);
