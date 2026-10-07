// Links and places in a note: a link looks like one, with its site's icon;
// the caret in one offers to open, edit or take it off; Ctrl+click opens it;
// an unsafe one is no link at all. A link pasted on its own line can become
// a card or, for a map link, a map; pasted in a sentence it is written short.
// And /map shows a place found by search.
//
// The card asks example.com, and the map asks the geocoder, through the app,
// so those checks need the internet -- like tests/e2e/maps.mjs.
//
//     node tests/e2e/note-links.mjs
import { makeNote, runBoard, SHOTS, tinker } from './harness.mjs';

await runBoard(
    '/notes',
    async (ctx) => {
        const { page, check } = ctx;
        const byTest = (id) => page.locator(`[data-test="${id}"]`);
        const base = new URL(page.url()).origin;

        const ref = await makeNote(
            ctx,
            `Links ${Date.now().toString().slice(-6)}`,
            'Read [the guide](https://example.com/guide) and [this](javascript:alert(1)).\n\nEnd.\n',
        );
        await page.goto(`${base}/notes/${ref}`, { waitUntil: 'networkidle' });
        await page.waitForSelector('.ProseMirror');
        await page.waitForTimeout(1200);
        const editor = page.locator('.ProseMirror');

        /** Pastes text as the clipboard would, where the caret is. */
        const paste = (text) =>
            page.evaluate((text) => {
                const data = new DataTransfer();
                data.setData('text/plain', text);
                document.querySelector('.ProseMirror').dispatchEvent(
                    new ClipboardEvent('paste', {
                        clipboardData: data,
                        bubbles: true,
                        cancelable: true,
                    }),
                );
            }, text);

        /** The caret at the end of the last paragraph, on a new line. */
        const newLine = async () => {
            await editor.locator('p').last().click();
            await page.keyboard.press('End');
            await page.keyboard.press('Enter');
        };

        // ---- how a link looks ----

        const guide = editor.locator('a[href="https://example.com/guide"]');
        check(
            'a link reads as one: coloured and underlined',
            await guide.evaluate((link) => {
                const style = getComputedStyle(link);

                return (
                    style.textDecorationLine.includes('underline') &&
                    style.color !== getComputedStyle(link.parentElement).color
                );
            }),
        );
        check(
            'with its site’s icon before it, served from the app',
            (
                await editor
                    .locator('img.link-icon')
                    .first()
                    .getAttribute('src')
            ).startsWith('/link-preview/icon?host=example.com'),
        );
        check(
            'a javascript: link is no link at all',
            (await editor.locator('a[href^="javascript"]').count()) === 0 &&
                (await editor.innerText()).includes('this'),
        );

        // ---- the caret in a link ----

        await guide.click();
        await byTest('link-menu').waitFor();
        check(
            'the caret in a link shows where it goes, written short',
            (await byTest('link-open').innerText()).includes(
                'example.com/guide',
            ),
        );

        await byTest('link-edit-open').click();
        await byTest('link-edit-href').fill('www.wikipedia.org');
        await byTest('link-edit-text').fill('the encyclopedia');
        await byTest('link-edit-save').click();
        await page.waitForTimeout(300);
        check(
            'a link is edited -- where it goes, and what it says; www. is https',
            (await editor
                .locator('a[href="https://www.wikipedia.org"]')
                .innerText()) === 'the encyclopedia',
        );

        // What is opened in a new tab, written down rather than opened
        await page.evaluate(() => {
            window.__opened = [];
            window.open = (url) => {
                window.__opened.push(url);

                return null;
            };
        });
        const wiki = editor.locator('a[href="https://www.wikipedia.org"]');
        await wiki.click({ modifiers: ['Control'] });
        await wiki.click();
        const opened = await page.evaluate(() => window.__opened);
        check(
            'Ctrl+click opens a link in a new tab, and a plain click does not',
            JSON.stringify(opened) === '["https://www.wikipedia.org"]' &&
                page.url().includes(`/notes/${ref}`),
            JSON.stringify(opened),
        );

        await byTest('link-remove').click();
        await page.waitForTimeout(200);
        check(
            'a link is taken off, its words kept',
            (await editor.locator('a[href*="wikipedia"]').count()) === 0 &&
                (await editor.innerText()).includes('the encyclopedia'),
        );

        // ---- a link pasted ----

        await editor.locator('p').first().click();
        await page.keyboard.press('End');
        await page.keyboard.type(' see ');
        await paste(
            'https://example.com/a/rather/long/path/to/some/page?with=query',
        );
        await page.waitForTimeout(300);
        const inline = editor.locator('a[href*="rather/long"]');
        check(
            'pasted in a sentence, a long link is written short and links the whole',
            (await inline.innerText()).endsWith('…') &&
                (await inline.innerText()).length <= 40,
            await inline.innerText(),
        );

        await newLine();
        await paste('https://example.com/');
        await byTest('link-paste-menu').waitFor();
        check(
            'pasted on a line of its own, it asks what to show it as',
            (await byTest('link-paste-card').count()) === 1 &&
                (await byTest('link-paste-link').count()) === 1,
        );
        await byTest('link-paste-card').click();
        await byTest('link-card').waitFor();
        await page
            .waitForFunction(
                () =>
                    document
                        .querySelector('[data-test="link-card-title"]')
                        ?.textContent.includes('Example Domain'),
                null,
                { timeout: 15000 },
            )
            .catch(() => {});
        check(
            'as a card, it shows the page’s title, read by the server',
            (await byTest('link-card-title').innerText()).includes(
                'Example Domain',
            ),
            await byTest('link-card-title').innerText(),
        );

        await newLine();
        await paste(
            'https://www.openstreetmap.org/?mlat=13.74646&mlon=100.53413#map=17/13.74646/100.53413',
        );
        await byTest('link-paste-map').waitFor({ timeout: 15000 });
        await byTest('link-paste-map').click();
        await byTest('map-block').last().waitFor();
        await page
            .locator('[data-test="map-block"]')
            .last()
            .locator('[data-test="place-map"] canvas')
            .waitFor({ timeout: 20000 });
        check(
            'a map link becomes a map of the place, named by the geocoder',
            (await byTest('map-block-name').last().innerText()).length > 0 &&
                (
                    await byTest('map-block').last().getAttribute('class')
                ).includes('map-block'),
            await byTest('map-block-name').last().innerText(),
        );

        await newLine();
        await paste('https://example.com/kept-as-a-link');
        await byTest('link-paste-menu').waitFor();
        await page.keyboard.press('Escape');
        check(
            'Escape leaves it a link',
            (await byTest('link-paste-menu').count()) === 0 &&
                (await editor.locator('a[href$="kept-as-a-link"]').count()) ===
                    1,
        );

        // ---- /map ----

        await newLine();
        await page.keyboard.type('/map');
        await page.waitForTimeout(400);
        await page.keyboard.press('Enter');
        const search = byTest('map-block-search')
            .last()
            .locator('[data-test="place-search"]');
        await search.waitFor();
        await search.fill('Wat Pho Bangkok');
        await page
            .locator('[data-test="place-suggestions"] li')
            .first()
            .waitFor({
                timeout: 15000,
            });
        await page.waitForTimeout(500);
        await search.press('Enter');
        await page
            .locator('[data-test="map-block"]')
            .last()
            .locator('[data-test="place-map"] canvas')
            .waitFor({ timeout: 20000 });
        check(
            '/map finds a place and shows it',
            // Named by the geocoder, in whatever language it names it
            (await byTest('map-block-name').last().innerText()).trim().length >
                0,
            await byTest('map-block-name').last().innerText(),
        );
        await page.screenshot({
            path: `${SHOTS}/note-links.png`,
            fullPage: true,
        });

        // ---- /url, and a link turned into a card and back ----

        await newLine();
        await page.keyboard.type('/url');
        await page.waitForTimeout(400);
        const firstItem = await page
            .locator('.notion-dropdown .dropdown-item')
            .first()
            .innerText();
        check(
            '/url finds the link block first',
            /^\W*Link\b/m.test(firstItem.replace(/^.*\n/, '')),
            firstItem.replace(/\n/g, ' · '),
        );
        await page.keyboard.press('Enter');
        await byTest('link-card-url').waitFor();
        await byTest('link-card-url').fill('example.org');
        await byTest('link-card-url').press('Enter');
        await page
            .waitForFunction(
                () =>
                    [
                        ...document.querySelectorAll(
                            '[data-test="link-card-title"]',
                        ),
                    ].some((title) => /example/i.test(title.textContent)),
                null,
                { timeout: 15000 },
            )
            .catch(() => {});
        check(
            'it asks for the link, and shows it as a card',
            (await byTest('link-card').count()) === 2,
        );

        // In a sentence: the card goes under it, the sentence keeps its words
        await newLine();
        await page.keyboard.type('Read more on ');
        await paste('https://example.net/');
        await page.keyboard.type(' today.');
        const inSentence = editor.locator('p a[href="https://example.net/"]');
        await inSentence.click();
        await byTest('link-as-card').click();
        await page.waitForTimeout(400);
        check(
            'a link in a sentence is shown as a card under it, the sentence kept',
            (await byTest('link-card').count()) === 3 &&
                (await editor.innerText()).includes(
                    'Read more on example.net today.',
                ),
        );

        // Printed, the caret in a link brings up no menu
        await inSentence.click();
        await byTest('link-menu').waitFor();
        await page.emulateMedia({ media: 'print' });
        check(
            'printed, the link menu is not there',
            await byTest('link-menu').evaluate(
                (menu) => getComputedStyle(menu).display === 'none',
            ),
        );
        await page.emulateMedia({ media: 'screen' });

        // And a card goes back to a link
        await byTest('link-card').last().hover();
        await byTest('link-card-as-link').last().click();
        await page.waitForTimeout(300);
        check(
            'and a card goes back to a link',
            (await byTest('link-card').count()) === 2,
        );

        // ---- kept ----

        await page.waitForTimeout(2500);
        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForSelector('.ProseMirror');
        await page.waitForTimeout(1500);
        check(
            'the cards and both maps are still there after a reload',
            (await byTest('link-card').count()) === 2 &&
                (await byTest('map-block').count()) === 2,
            `${await byTest('link-card').count()} card, ${await byTest('map-block').count()} maps`,
        );

        // As the server keeps it, and hands it to Markdown and MCP
        const markdown = JSON.parse(
            tinker(
                `echo json_encode(App\\Support\\Markdown\\TiptapMarkdown::toMarkdown(App\\Models\\Note\\Note::where('ref_id', '${ref}')->first()->content));`,
            ),
        );
        check(
            'a map is kept as the place it shows: its name and where',
            /```map\n[^`]*lat: 13\.74/.test(markdown) &&
                /```link\nurl: https:\/\/example\.com/.test(markdown),
            markdown.match(/```map[^`]*```/)?.[0]?.replace(/\n/g, ' | '),
        );
    },
    { canvas: false },
);
