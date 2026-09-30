// Two people writing one note at once, as in Google Docs: each sees the
// other's words and caret as they type, the note keeps both, and a change
// made from elsewhere (MCP) reaches it while it is open.
//
// Needs the collaboration server (the `collab` service) and the second account
// E2E_MEMBER (e2e-member@example.com, password "password"). The outside change
// is made with `docker exec` into E2E_APP_CONTAINER (zyrenn-app-1).
import { execFileSync } from 'node:child_process';
import {
    SHOTS,
    deleteProject,
    runBoard,
    shareProject,
    signIn,
} from './harness.mjs';

const MEMBER = process.env.E2E_MEMBER ?? 'e2e-member@example.com';
const APP_CONTAINER = process.env.E2E_APP_CONTAINER ?? 'zyrenn-app-1';
const RUN = Date.now().toString().slice(-6);

/** Runs PHP in the app, as MCP would change a note. */
const tinker = (php) =>
    execFileSync(
        'docker',
        ['exec', APP_CONTAINER, 'php', 'artisan', 'tinker', '--execute', php],
        {
            encoding: 'utf8',
        },
    );

await runBoard(
    '/notes',
    async ({ browser, page, check, afterwards }) => {
        const { project, base } = await shareProject(
            page,
            `Coedit ${RUN}`,
            MEMBER,
        );
        afterwards(() => deleteProject(page, base, project));

        await page.goto(`${base}/p/${project}/notes`);
        await page
            .getByRole('button', { name: /new note/i })
            .first()
            .click();
        await page.waitForURL(/\/notes\/\w+$/);
        const noteUrl = page.url();
        const ref = noteUrl.match(/\/notes\/(\w+)$/)[1];

        const { context, page: other } = await signIn(browser, base, MEMBER);
        await other.goto(noteUrl, { waitUntil: 'networkidle' });

        const editor = (who) => who.locator('.ProseMirror');
        const status = (who) =>
            who.locator('[aria-live="polite"]').first().innerText();

        await page.waitForFunction(
            () => /Saved/.test(document.body.innerText),
            null,
            { timeout: 10000 },
        );
        check(
            'the note opens shared, and says it is saved',
            /Saved/.test(await status(page)),
            await status(page),
        );

        // ---------------------------------------------------- Both of them write
        await editor(page).click();
        await page.keyboard.type(`Owner wrote this ${RUN}.`);
        await other
            .waitForFunction(
                (text) =>
                    document
                        .querySelector('.ProseMirror')
                        ?.innerText.includes(text),
                `Owner wrote this ${RUN}.`,
                { timeout: 5000 },
            )
            .catch(() => {});
        check(
            'what the owner types appears for the member',
            (await editor(other).innerText()).includes(
                `Owner wrote this ${RUN}.`,
            ),
        );

        await editor(other).click();
        await other.keyboard.press('Control+End');
        await other.keyboard.press('Enter');
        await other.keyboard.type(`Member added this ${RUN}.`);
        await page.waitForTimeout(1200);

        const ownerSees = await editor(page).innerText();
        check(
            'and what the member types appears for the owner, both kept',
            ownerSees.includes(`Owner wrote this ${RUN}.`) &&
                ownerSees.includes(`Member added this ${RUN}.`),
            ownerSees.replace(/\s+/g, ' ').slice(0, 90),
        );
        check(
            'the owner sees where the member is typing, by name',
            (await page
                .locator('.collaboration-carets__label', {
                    hasText: 'E2E Member',
                })
                .count()) === 1,
        );

        await other.getByPlaceholder('Untitled').fill(`Shared plan ${RUN}`);
        await page.waitForTimeout(1200);
        check(
            'a title one gives it reaches the other',
            (await page.getByPlaceholder('Untitled').inputValue()) ===
                `Shared plan ${RUN}`,
        );
        await page.screenshot({ path: `${SHOTS}/coedit-1-owner.png` });

        // ------------------------------------------- Kept, for search and MCP too
        await page.waitForTimeout(3500);
        const saved = tinker(
            `$n = App\\Models\\Note\\Note::where('ref_id', '${ref}')->first(); echo json_encode([$n->title, $n->plain_text, $n->ydoc !== null, $n->updated_by !== null]);`,
        );
        const [title, text, hasState, hasEditor] = JSON.parse(
            saved.trim().split('\n').pop(),
        );
        check(
            'the app keeps the title and both people’s words',
            title === `Shared plan ${RUN}` &&
                text.includes(`Owner wrote this ${RUN}.`) &&
                text.includes(`Member added this ${RUN}.`),
            `${title} | ${text.replace(/\s+/g, ' ').slice(0, 70)}`,
        );
        check(
            'with the shared state and who changed it last',
            hasState && hasEditor,
        );

        await other.reload({ waitUntil: 'networkidle' });
        await other.waitForFunction(
            () => /Saved/.test(document.body.innerText),
            null,
            { timeout: 10000 },
        );
        check(
            'reopened, it is all still there',
            (await editor(other).innerText()).includes(
                `Member added this ${RUN}.`,
            ),
        );

        // ------------------- Printed to PDF with the others' latest words in it
        await editor(other).click();
        await other.keyboard.press('Control+End');
        await other.keyboard.type(` Last words ${RUN}.`);
        await page.waitForTimeout(400);

        await page.locator('[data-test="note-export"]').click();
        const [download] = await Promise.all([
            page.waitForEvent('download', { timeout: 30000 }),
            page.locator('[data-test="note-export-download"]').click(),
        ]);
        const pdfPath = `${SHOTS}/coedit-${RUN}.pdf`;
        await download.saveAs(pdfPath);
        const printed = execFileSync('pdftotext', [pdfPath, '-'], {
            encoding: 'utf8',
        }).replace(/\s+/g, ' ');
        check(
            'a PDF of the shared note has everyone’s words, the latest too',
            printed.includes(`Owner wrote this ${RUN}.`) &&
                printed.includes(`Member added this ${RUN}.`) &&
                printed.includes(`Last words ${RUN}.`),
            printed.slice(0, 90),
        );

        // --------------------------------- A change from elsewhere, while open
        tinker(
            `$n = App\\Models\\Note\\Note::where('ref_id', '${ref}')->first(); $n->update(['title' => 'Renamed by MCP ${RUN}', 'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Rewritten by MCP ${RUN}']]]]]]);`,
        );
        await page.waitForTimeout(1500);
        check(
            'a change made over MCP reaches the open note',
            (await editor(page).innerText()).includes(
                `Rewritten by MCP ${RUN}`,
            ) &&
                (await page.getByPlaceholder('Untitled').inputValue()) ===
                    `Renamed by MCP ${RUN}`,
            (await editor(page).innerText()).slice(0, 60),
        );
        check(
            'for everyone who has it open',
            (await editor(other).innerText()).includes(
                `Rewritten by MCP ${RUN}`,
            ),
        );
        await other.screenshot({ path: `${SHOTS}/coedit-2-member.png` });

        await context.close();
    },
    { canvas: false },
);
