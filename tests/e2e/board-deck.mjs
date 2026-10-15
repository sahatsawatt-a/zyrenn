// A board's frames as a deck: the footer and numbers its deck writes on
// every slide, the deck changed and every slide put in its look from the
// panel, a slide added from a layout, and the speaker notes -- under the
// slide while presenting, and in the presenter's own window, which moves the
// show on.
import { open, runBoard, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
// How long what the app keeps may take to catch up with the page
const SAVED = { timeout: 15000 };
const WHO = process.env.E2E_EMAIL ?? 'test@example.com';

await runBoard(
    '/boards',
    async (b) => {
        const { page, check, soon, afterwards } = b;
        const base = new URL(page.url()).origin;
        const user = `App\\Models\\User::where('email', '${WHO}')->first()`;
        const title = `Deck ${RUN}`;

        // Three slides in the Ivory look, made behind the scenes
        const ref = tinker(
            `App\\Mcp\\Servers\\UserServer::actingAs(${user})->tool(App\\Mcp\\Tools\\Boards\\CreateBoard::class, ['title' => '${title}',
                'deck' => ['theme' => 'ivory', 'footer' => '{title} · {section}', 'numbers' => true],
                'slides' => [
                    ['id' => 'cover', 'layout' => 'title', 'title' => 'Q3 in review'],
                    ['id' => 'part', 'layout' => 'section', 'title' => 'Results', 'notes' => 'Begin with the results'],
                    ['id' => 'point', 'layout' => 'title-body', 'title' => 'Sign-ups doubled', 'body' => '- From search', 'notes' => 'Pause on this one'],
                ]]);
             echo ${user}->boards()->where('title', '${title}')->value('ref_id');`.replace(
                /\n\s*/g,
                ' ',
            ),
        );

        afterwards(() =>
            tinker(
                `App\\Models\\Board\\Board::where('ref_id', '${ref}')->first()?->delete(); echo 'ok';`,
            ),
        );

        await open(page, `${base}/boards/${ref}`);
        await b.ready();

        /** The board as the app keeps it: its items by id, and its deck. */
        const kept = () =>
            page.evaluate(async (ref) => {
                const board = await (
                    await fetch(`/boards/${ref}/content`, {
                        headers: { Accept: 'application/json' },
                    })
                ).json();

                return {
                    items: Object.fromEntries(
                        board.items.map((item) => [item.id, item]),
                    ),
                    deck: board.deck,
                };
            }, ref);

        /** Every line of words drawn on the canvas. */
        const words = () =>
            page.evaluate(() =>
                window.Konva.stages[0].find('Text').map((text) => text.text()),
            );

        // -------------------------------------------- what every slide says
        await soon(
            'the footer and number are written on each slide but the title slide',
            async () => {
                const drawn = await words();

                return (
                    drawn.filter((line) => line === `${title} · Results`)
                        .length === 2 &&
                    drawn.includes('2') &&
                    drawn.includes('3') &&
                    !drawn.includes('1')
                );
            },
            JSON.stringify(await words()),
        );

        // --------------------------------------------- the deck, from the panel
        await page.locator('[data-test="deck-open"]').click();
        await page.locator('[data-test="deck-theme-night"]').click();
        const header = page.locator('[data-test="deck-header"]');
        await header.fill('Q3 · {n} of {count}');
        await header.press('Enter');
        await header.blur();

        await soon(
            'a theme and header chosen in the panel are kept with the board',
            async () => {
                const { deck } = await kept();

                return (
                    deck?.theme === 'night' &&
                    deck.header === 'Q3 · {n} of {count}' &&
                    deck.numbers === true
                );
            },
            JSON.stringify((await kept()).deck),
            SAVED,
        );
        await soon('…and the header is written on the slides', async () =>
            (await words()).includes('Q3 · 3 of 3'),
        );

        await page.locator('[data-test="deck-restyle"]').click();
        await soon(
            'every slide put in the look: its background, and its title in the theme',
            async () => {
                const { items } = await kept();

                return (
                    items.cover.fill === '#0f172a' &&
                    items.point.fill === '#0f172a' &&
                    items['point-title'].color === '#f8fafc'
                );
            },
            '',
            SAVED,
        );
        await page.keyboard.press('Escape');

        // ------------------------------------------------- a slide laid out
        await page.locator('[data-test="new-slide"]').click();
        await page.locator('[data-test="slide-layout-two-columns"]').click();
        await soon(
            "a slide from a layout goes after the last, in the deck's look, with its columns",
            async () => {
                const items = Object.values((await kept()).items);
                const frames = items.filter((item) => item.kind === 'frame');
                const added = frames.find(
                    (frame) => !['cover', 'part', 'point'].includes(frame.id),
                );

                return (
                    frames.length === 4 &&
                    added?.x === 2080 + 960 + 80 &&
                    added.fill === '#0f172a' &&
                    items.filter(
                        (item) =>
                            item.kind === 'text' &&
                            item.x >= added.x &&
                            item.x < added.x + added.width,
                    ).length === 3
                );
            },
            '',
            SAVED,
        );

        // ------------------------------------------------------- presenting
        await page.keyboard.press('Escape');
        await page.locator('[data-test="present"]').click();
        await page.locator('[data-test="frame-position"]').waitFor();
        await page.keyboard.press('n');
        await soon('N shows the speaker notes under the slide', async () =>
            (
                await page
                    .locator('[data-test="present-notes"]')
                    .textContent()
                    .catch(() => '')
            )?.includes('No notes on this slide'),
        );
        await page.locator('[data-test="next-frame"]').click();
        await soon(
            "…and they follow the show: the next slide's notes",
            async () =>
                (
                    await page
                        .locator('[data-test="present-notes"]')
                        .textContent()
                )?.includes('Begin with the results'),
        );

        // The presenter's window, beside the show
        const [presenter] = await Promise.all([
            page.context().waitForEvent('page'),
            page.locator('[data-test="present-presenter"]').click(),
        ]);
        await presenter.locator('[data-test="presenter-notes"]').waitFor();
        await soon(
            "the presenter's window shows this slide's notes and where the show is",
            async () =>
                (
                    await presenter
                        .locator('[data-test="presenter-notes"]')
                        .textContent()
                )?.includes('Begin with the results') &&
                (
                    await presenter
                        .locator('[data-test="presenter-position"]')
                        .textContent()
                )?.trim() === '2 / 4',
        );
        check(
            'opening it leaves the show going',
            await page.locator('[data-test="frame-position"]').isVisible(),
        );

        await presenter.locator('[data-test="presenter-next"]').click();
        await soon(
            "Next in the presenter's window moves the show on",
            async () =>
                (
                    await page
                        .locator('[data-test="frame-position"]')
                        .textContent()
                )?.includes('3 / 4') &&
                (
                    await presenter
                        .locator('[data-test="presenter-notes"]')
                        .textContent()
                )?.includes('Pause on this one'),
        );

        await presenter.locator('[data-test="presenter-stop"]').click();
        await soon(
            '…and ending it there ends the show',
            async () =>
                !(await page.locator('[data-test="frame-position"]').count()) &&
                (await presenter.locator('text=The show is over').count()) > 0,
        );
        await presenter.close();
    },
    { canvas: false },
);
