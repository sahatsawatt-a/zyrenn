// A prototype's links: a button given somewhere to go in the inspector's
// "Goes to", kept after a reload with its badge on the board, and taken off
// -- with a word to say so -- when the screen it went to is deleted. And the
// UI kit comes first, open, in the shapes of a prototype.
import {
    SHOTS,
    boardKept,
    open,
    reopen,
    runBoard,
    tinker,
} from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
// How long what the app keeps may take to catch up with the page
const SAVED = { timeout: 15000 };
const WHO = process.env.E2E_EMAIL ?? 'test@example.com';

await runBoard(
    '/demo/konva',
    async (b) => {
        const { page, check, soon, afterwards, screenOf } = b;
        const base = new URL(page.url()).origin;
        const user = `App\\Models\\User::where('email', '${WHO}')->first()`;

        // Two phone screens and a button, made a prototype behind the scenes:
        // the suite is about its links
        const ref = tinker(
            `App\\Mcp\\Servers\\UserServer::actingAs(${user})->tool(App\\Mcp\\Tools\\Boards\\CreateBoard::class, ['title' => 'Shop ${RUN}', 'items' => [
                ['id' => 'home', 'kind' => 'frame', 'text' => 'Home', 'x' => 0, 'y' => 0, 'width' => 390, 'height' => 844],
                ['id' => 'cart', 'kind' => 'frame', 'text' => 'Cart', 'x' => 550, 'y' => 0, 'width' => 390, 'height' => 844],
                ['id' => 'buy', 'kind' => 'button', 'text' => 'Buy', 'x' => 16, 'y' => 700, 'width' => 358, 'height' => 48]]]);
             $board = ${user}->boardsAndPrototypes()->where('title', 'Shop ${RUN}')->sole();
             $board->forceFill(['kind' => 'prototype', 'prototype' => ['device' => 'phone', 'start' => 'home']])->save();
             echo $board->ref_id;`.replace(/\n\s*/g, ' '),
        );

        afterwards(() =>
            tinker(
                `App\\Models\\Board\\Board::where('ref_id', '${ref}')->first()?->delete(); echo 'ok';`,
            ),
        );

        await open(page, `${base}/boards/${ref}`);
        await b.ready();

        const click = async (x, y) => {
            const point = await screenOf(x, y);

            await page.mouse.click(point.x, point.y);
        };
        const test = (name) => page.locator(`[data-test="${name}"]`);
        const kind = test('tap-kind');
        const badges = () =>
            page.evaluate(
                () => window.Konva.stages[0].find('.link-badge').length,
            );

        // ------------------------------------------------- The tap, set
        await click(195, 724);
        await kind.waitFor();
        check(
            'a button on a screen of a prototype can be given something to do in Play',
            (await kind.getAttribute('data-value')) === 'none',
            await kind.getAttribute('data-value'),
        );

        await kind.click();
        await test('tap-kind-to').click();
        await test('tap-screen').click();
        await test('tap-screen-cart').click();
        await soon(
            'and the screen a tap goes to is kept',
            async () => (await boardKept(page, ref)).buy?.link?.to === 'cart',
            async () => JSON.stringify((await boardKept(page, ref)).buy?.link),
            SAVED,
        );

        // ------------------------------------------- Another trigger, too
        await test('add-trigger').click();
        await test('add-trigger-long-press').click();
        await soon(
            'a long press is another trigger, kept beside the tap',
            async () =>
                (await boardKept(page, ref)).buy?.when?.['long-press']?.back ===
                true,
            async () => JSON.stringify((await boardKept(page, ref)).buy?.when),
            SAVED,
        );
        await soon(
            'with a badge on it for each',
            async () => (await badges()) === 2,
            async () => `${await badges()} badges`,
        );

        // ------------------------------------------------- After a reload
        await reopen(page);
        await b.ready();
        await click(195, 724);
        await kind.waitFor();
        await soon(
            'after a reload the tap still goes there, as the inspector says',
            async () =>
                (await test('tap-screen').getAttribute('data-value')) ===
                'cart',
            () => test('tap-screen').getAttribute('data-value'),
        );
        check(
            'and the long press is still set',
            (await test('long-press-kind').getAttribute('data-value')) ===
                'back',
        );
        check('and both badges are still on it', (await badges()) === 2);

        // ----------------------------------------- The UI kit, first and open
        await page.keyboard.press('Escape');
        await page.locator('[data-test="tool-shapes"]').click();
        const flyout = page.locator('[data-test="shape-flyout"]');
        await flyout.waitFor();
        await soon(
            'the UI kit is open in the shapes of a prototype',
            async () =>
                (await flyout.locator('[data-test="tool-button"]').count()) ===
                1,
        );
        await page.screenshot({ path: `${SHOTS}/prototype-kit.png` });
        await page.keyboard.press('Escape');
        await flyout.waitFor({ state: 'hidden' });

        // ------------------------------- Its screen deleted, the link goes
        // A screen is picked where nothing else is on it
        await click(745, 400);
        await page.keyboard.press('Delete');
        await soon(
            'deleting the screen it went to says so',
            async () =>
                (await page.getByText(/used what was deleted/).count()) > 0,
        );
        await soon(
            'and takes the tap off, keeping the long press',
            async () => {
                const kept = await boardKept(page, ref);

                return (
                    !kept.cart &&
                    kept.buy &&
                    !kept.buy.link &&
                    kept.buy.when?.['long-press']?.back === true
                );
            },
            async () => JSON.stringify((await boardKept(page, ref)).buy),
            SAVED,
        );
        check(
            'and its badge, leaving the long press its own',
            (await badges()) === 1,
        );

        await page.screenshot({ path: `${SHOTS}/prototype-links.png` });
    },
    { width: 1500, height: 950 },
);
