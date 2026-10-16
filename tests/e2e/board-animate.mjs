// A slide's animations, as PowerPoint plays them: an item's own in the panel,
// added there; then the show -- what comes up with the slide, each click
// bringing the next in or taking it off, back undoing a click, a fade
// through black to the next slide, the slide before come back to as it
// ended, and every item given back once the show is over.
import { open, runBoard, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const WHO = process.env.E2E_EMAIL ?? 'test@example.com';

await runBoard(
    '/boards',
    async (b) => {
        const { page, check, soon, afterwards } = b;
        const base = new URL(page.url()).origin;
        const user = `App\\Models\\User::where('email', '${WHO}')->first()`;
        const title = `Animate ${RUN}`;
        const as = `App\\Mcp\\Servers\\UserServer::actingAs(${user})`;

        // Two slides made behind the scenes. The first: its title comes with
        // it, a flies in, b zooms in, then a goes. The second, come to through
        // black: c appears on a click.
        const ref = tinker(
            `${as}->tool(App\\Mcp\\Tools\\Boards\\CreateBoard::class, ['title' => '${title}', 'items' => [
            ['id' => 's1', 'kind' => 'frame', 'x' => 0, 'y' => 0, 'width' => 1280, 'height' => 720, 'text' => 'One'],
            ['id' => 'title', 'kind' => 'text', 'x' => 80, 'y' => 60, 'width' => 600, 'height' => 80, 'text' => 'Why it matters'],
            ['id' => 'a', 'kind' => 'rect', 'x' => 80, 'y' => 220, 'width' => 300, 'height' => 160, 'text' => 'First'],
            ['id' => 'b', 'kind' => 'rect', 'x' => 480, 'y' => 220, 'width' => 300, 'height' => 160, 'text' => 'Second'],
            ['id' => 's2', 'kind' => 'frame', 'x' => 0, 'y' => 900, 'width' => 1280, 'height' => 720, 'text' => 'Two', 'transition' => 'fade',
                'build' => [['item' => 'c', 'kind' => 'in', 'effect' => 'appear']]],
            ['id' => 'c', 'kind' => 'rect', 'x' => 80, 'y' => 1100, 'width' => 300, 'height' => 160, 'text' => 'Third'],
        ]]);
        $ref = ${user}->boards()->where('title', '${title}')->value('ref_id');
        ${as}->tool(App\\Mcp\\Tools\\Boards\\UpdateBoard::class, ['ref_id' => $ref, 'update_items' => [['id' => 's1', 'build' => [
            ['item' => 'title', 'kind' => 'in', 'effect' => 'fade', 'start' => 'with'],
            ['item' => 'a', 'kind' => 'in', 'effect' => 'fly', 'from' => 'left', 'duration' => 250],
            ['item' => 'b', 'kind' => 'in', 'effect' => 'zoom', 'duration' => 250],
            ['item' => 'a', 'kind' => 'out', 'effect' => 'fade', 'duration' => 250],
        ]]]]);
        echo $ref;`.replace(/\n\s*/g, ' '),
        );

        afterwards(() =>
            tinker(
                `App\\Models\\Board\\Board::where('ref_id', '${ref}')->first()?->delete(); echo 'ok';`,
            ),
        );

        await open(page, `${base}/boards/${ref}`);
        await b.ready();

        /** Whether each item is showing on the canvas just now. */
        const shown = (ids) =>
            page.evaluate(
                (ids) =>
                    Object.fromEntries(
                        ids.map((id) => {
                            const node = window.Konva.stages[0].findOne(
                                (one) =>
                                    one.id() === id &&
                                    one.getClassName() === 'Group',
                            );

                            return [
                                id,
                                !!node &&
                                    node.isVisible() &&
                                    node.opacity() > 0.5,
                            ];
                        }),
                    ),
                ids,
            );
        const showing = async (want) => {
            const now = await shown(Object.keys(want));

            return Object.entries(want).every(([id, on]) => now[id] === on);
        };

        /** The first slide's list of animations, as the app keeps it. */
        const steps = () =>
            page.evaluate(async (ref) => {
                const board = await (
                    await fetch(`/boards/${ref}/content`, {
                        headers: { Accept: 'application/json' },
                    })
                ).json();

                return board.items.find((item) => item.id === 's1').build ?? [];
            }, ref);

        // ------------------------------------------------------- while editing
        await soon(
            'while editing, every item shows, whatever its animations',
            () => showing({ title: true, a: true, b: true, c: true }),
        );

        // b, chosen on the canvas: its own animation in the panel, one added
        const middle = await b.screenOf(630, 300);
        await page.mouse.click(middle.x, middle.y);
        await soon(
            "an item's own animations are in the panel",
            async () =>
                (await page
                    .locator(
                        '[data-test="item-animation"] [data-test="animation-step"]',
                    )
                    .count()) === 1,
        );
        await page.locator('[data-test="animate-emphasis"]').click();
        await soon(
            "one added goes on the end of its slide's list, on a click",
            async () => {
                const list = await steps();

                return (
                    list.length === 5 &&
                    list[4].item === 'b' &&
                    list[4].kind === 'emphasis' &&
                    list[4].start === 'click'
                );
            },
        );

        /** The show's next (or previous) click, once what is playing has played. */
        const click = async (key) => {
            await page
                .locator(
                    '[data-test="present-curtain"][data-build-playing="false"]',
                )
                .waitFor();
            await page.keyboard.press(key);
        };

        // ---------------------------------------------------------- presenting
        await page.keyboard.press('Escape');
        await page.locator('[data-test="present"]').click();
        await page.locator('[data-test="frame-position"]').waitFor();

        await soon(
            'the slide comes up with its title; a and b wait for their clicks',
            () => showing({ title: true, a: false, b: false }),
        );

        await click('ArrowRight');
        await soon('a click brings a in', () => showing({ a: true, b: false }));
        await click(' ');
        await soon('Space is a click too: b comes in', () =>
            showing({ a: true, b: true }),
        );
        await click('ArrowRight');
        await soon('the next click takes a off', () =>
            showing({ a: false, b: true }),
        );
        await click('ArrowLeft');
        await soon('back undoes the click: a is there again', () =>
            showing({ a: true, b: true }),
        );
        check(
            'all the while, the show is on the first slide',
            (
                await page.locator('[data-test="frame-position"]').innerText()
            ).startsWith('1 / 2'),
        );

        // Through to the next slide, watching for the black a fade goes through
        await page.evaluate(() => {
            window.darkest = 0;
            const curtain = document.querySelector(
                '[data-test="present-curtain"]',
            );

            new MutationObserver(() => {
                window.darkest = Math.max(
                    window.darkest,
                    Number(curtain.style.opacity || 0),
                );
            }).observe(curtain, { attributes: true });
        });
        await click('ArrowRight'); // a goes
        await soon('a goes again', () => showing({ a: false }));
        await click('ArrowRight'); // b's emphasis
        await click('ArrowRight'); // the next slide
        await soon(
            'the next slide comes, with c waiting for its click',
            async () =>
                (
                    await page
                        .locator('[data-test="frame-position"]')
                        .innerText()
                ).startsWith('2 / 2') && (await showing({ c: false })),
        );
        check(
            'it came through black, as its transition asks',
            (await page.evaluate(() => window.darkest)) === 1,
        );
        await click('ArrowRight');
        await soon('a click brings c in', () => showing({ c: true }));

        await click('ArrowLeft');
        await soon('back takes c off again', () => showing({ c: false }));
        await click('ArrowLeft');
        await soon(
            'back again: the first slide, as it ended',
            async () =>
                (
                    await page
                        .locator('[data-test="frame-position"]')
                        .innerText()
                ).startsWith('1 / 2') &&
                (await showing({ title: true, a: false, b: true })),
        );

        // ------------------------------------------------------------- the end
        await page.keyboard.press('Escape');
        await soon('the show over, every item shows again', () =>
            showing({ title: true, a: true, b: true, c: true }),
        );
    },
    { canvas: false },
);
