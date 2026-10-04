// A note's PDF: leaving blocks out of it from the block menu, page breaks
// (typed as /page break, or below a block from its menu), and exporting it
// through the preview: the hidden block stays in the note, faded on
// screen, and is gone from what the server prints; a break starts a new page,
// and one with nothing after it prints no blank page. Needs the `chrome`
// service (docker/chrome), and pdftotext and pdfinfo on the machine running
// the suite.
import { execFileSync } from 'node:child_process';
import { SHOTS, makeNote, runBoard } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);

await runBoard(
    '/notes',
    async (ctx) => {
        const { page, check } = ctx;
        const ref = await makeNote(
            ctx,
            `Note PDF ${RUN}`,
            `Kept paragraph ${RUN}.\n\nDraft paragraph ${RUN}.\n\n## Heading ${RUN}\n\nLast paragraph ${RUN}.\n`,
        );
        await page.goto(`${new URL(page.url()).origin}/notes/${ref}`, {
            waitUntil: 'networkidle',
        });
        await page.waitForSelector('.ProseMirror');
        await page.waitForTimeout(1200);

        const draft = page.locator('.ProseMirror p', {
            hasText: `Draft paragraph ${RUN}`,
        });
        await draft.hover();
        await page.waitForTimeout(300);
        await page.locator('[data-test="block-grip"]').click();
        await page.locator('[data-test="block-pdf-toggle"]').click();
        await page.waitForTimeout(400);
        check(
            'block marked hidden on screen',
            await draft.evaluate((el) =>
                el.classList.contains('is-pdf-hidden'),
            ),
        );
        check(
            'faded, not removed',
            Number(await draft.evaluate((el) => getComputedStyle(el).opacity)) <
                1,
        );
        await page.screenshot({ path: `${SHOTS}/pdf-hidden-editor.png` });

        // The menu now offers to put it back
        await draft.hover();
        await page.waitForTimeout(300);
        await page.locator('[data-test="block-grip"]').click();
        check(
            'menu offers Show in PDF',
            (
                await page.locator('[data-test="block-pdf-toggle"]').innerText()
            ).includes('Show in PDF'),
        );
        await page.keyboard.press('Escape');

        // Ctrl+P too: printed, the block takes no room
        await page.emulateMedia({ media: 'print' });
        check(
            'gone when printed',
            (await draft.evaluate((el) => getComputedStyle(el).display)) ===
                'none',
        );
        await page.emulateMedia({ media: 'screen' });

        // A page break typed after the first paragraph...
        await page
            .locator('.ProseMirror p', { hasText: `Kept paragraph ${RUN}` })
            .click();
        await page.waitForTimeout(300);
        await page.keyboard.press('End');
        await page.keyboard.press('Enter');
        // The slash menu stops at a space: "/page" or "/pagebreak" finds it
        await page.keyboard.type('/pagebreak');
        await page.waitForTimeout(300);
        await page.keyboard.press('Enter');
        await page.waitForTimeout(300);
        // ...and one below the last paragraph, with nothing after it to print
        const last = page.locator('.ProseMirror p', {
            hasText: `Last paragraph ${RUN}`,
        });
        await last.hover();
        await page.waitForTimeout(300);
        await page.locator('[data-test="block-grip"]').click();
        await page.locator('[data-test="block-page-break"]').click();
        await page.waitForTimeout(400);
        const breaks = page.locator('.ProseMirror .page-break');
        check('two page breaks on screen', (await breaks.count()) === 2);
        check(
            'the one at the end is idle, the first is not',
            (await breaks
                .nth(1)
                .evaluate((el) => el.classList.contains('is-idle'))) &&
                !(await breaks
                    .nth(0)
                    .evaluate((el) => el.classList.contains('is-idle'))),
        );
        await page.screenshot({ path: `${SHOTS}/note-pdf-breaks.png` });

        // Export goes straight to the preview: every PDF is seen first
        await page.locator('[data-test="note-export"]').click();
        const frame = page.locator('[data-test="pdf-preview-frame"]');
        await frame.waitFor({ timeout: 60000 });
        const src = await frame.getAttribute('src');
        check('preview shows a printed PDF', src?.startsWith('blob:'), src);
        await page.waitForTimeout(1500);
        await page.screenshot({ path: `${SHOTS}/pdf-preview.png` });

        // What the preview holds is what Download hands over
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.locator('[data-test="pdf-preview-download"]').click(),
        ]);
        const file = `${SHOTS}/note-pdf-${RUN}.pdf`;
        await download.saveAs(file);
        const text = execFileSync('pdftotext', [file, '-'], {
            encoding: 'utf8',
        });
        check(
            'PDF keeps the rest',
            text.includes(`Kept paragraph ${RUN}`) &&
                text.includes(`Last paragraph ${RUN}`),
            text.replace(/\s+/g, ' ').slice(0, 140),
        );
        check(
            'PDF leaves the hidden block out',
            !text.includes(`Draft paragraph ${RUN}`),
        );

        const pages = Number(
            execFileSync('pdfinfo', [file], { encoding: 'utf8' }).match(
                /Pages:\s+(\d+)/,
            )[1],
        );
        check(
            'a page per break, and no blank page at the end',
            pages === 2,
            `${pages} pages`,
        );
        const page1 = execFileSync(
            'pdftotext',
            ['-f', '1', '-l', '1', file, '-'],
            { encoding: 'utf8' },
        );
        const page2 = execFileSync(
            'pdftotext',
            ['-f', '2', '-l', '2', file, '-'],
            { encoding: 'utf8' },
        );
        check(
            'the break starts the second page',
            page1.includes(`Kept paragraph ${RUN}`) &&
                !page1.includes(`Heading ${RUN}`) &&
                page2.includes(`Heading ${RUN}`) &&
                page2.includes(`Last paragraph ${RUN}`),
            page2.replace(/\s+/g, ' ').slice(0, 80),
        );

        // Another style prints again
        await page.locator('[data-test="pdf-preview-style-report"]').click();
        await page.waitForFunction(
            (old) =>
                document
                    .querySelector('[data-test="pdf-preview-frame"]')
                    ?.getAttribute('src') !== old,
            src,
            { timeout: 60000 },
        );
        check('switching style reprints', true);
        await page.keyboard.press('Escape');
    },
    { canvas: false },
);
