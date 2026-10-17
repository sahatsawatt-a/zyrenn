// A prototype played: it opens on its start screen inside the device it is
// made for, a tap on a linked part goes on to that screen, Back -- the
// prototype's, the bar's and the browser's -- comes back, a tap on nothing
// lights up what can be tapped, and on a phone the screen fills it.
import {
    SHOTS,
    deleteProject,
    open,
    reopen,
    runBoard,
    shareProject,
    tinker,
    until,
} from './harness.mjs';

const EMAIL = process.env.E2E_EMAIL ?? 'test@example.com';
const MEMBER = process.env.E2E_MEMBER ?? 'e2e-member@example.com';
const RUN = Date.now().toString().slice(-6);

// Three phone screens side by side; the first leads to the second, which
// leads back and on to the third
const screen = (id, text, x) => ({
    id,
    kind: 'frame',
    x,
    y: 0,
    width: 390,
    height: 844,
    text,
    fill: '#ffffff',
});
const part = (id, text, x, y, link) => ({
    id,
    kind: 'rect',
    x,
    y,
    width: 200,
    height: 60,
    text,
    fill: '#6366f1',
    link,
});
const ITEMS = [
    screen('home', 'Home', 0),
    screen('menu', 'Menu', 470),
    screen('cart', 'Cart', 940),
    part('order', 'Order', 95, 700, { to: 'menu' }),
    part('back', 'Back', 485, 40, { back: true }),
    part('add', 'Add to cart', 565, 700, { to: 'cart' }),
];

await runBoard(
    '/dashboard',
    async ({ page, check, soon, afterwards }) => {
        const { project, base } = await shareProject(
            page,
            `Prototype ${RUN}`,
            MEMBER,
        );
        afterwards(() => deleteProject(page, base, project));

        const board = tinker(
            `$b = (new App\\Models\\Board\\Board)->ownedBy(App\\Models\\Project::where('ref_id', '${project}')->firstOrFail(), App\\Models\\User::where('email', '${EMAIL}')->firstOrFail()); $b->title = 'Coffee order'; $b->kind = App\\Models\\Board\\Board::PROTOTYPE; $b->prototype = ['device' => 'phone', 'start' => 'home']; $b->content = ['items' => json_decode('${JSON.stringify(ITEMS)}', true)]; $b->save(); echo $b->ref_id;`,
        );

        const test = (name) => page.locator(`[data-test="${name}"]`);
        const shown = () => test('play-screen').getAttribute('data-screen');
        const on = (id) => async () => (await shown()) === id;
        const spot = (id) => page.locator(`[data-hotspot="${id}"]`);

        await open(page, `${base}/prototypes/${board}/play`);
        await test('play-screen').waitFor();
        check(
            'it opens on its start screen, inside a phone',
            (await shown()) === 'home' &&
                (await page
                    .locator('.play-device.is-framed.is-phone')
                    .count()) === 1,
        );
        check(
            'the bar says which screen of how many',
            /1\/3\s*Home/.test(await test('play-screens').innerText()),
            await test('play-screens').innerText(),
        );
        // The prototype's own address, where it is edited (not its Play)
        const edit = (await test('play-edit').getAttribute('href')) ?? '';
        check(
            'with a way back to editing',
            new RegExp(`/prototypes/${board}$`).test(edit),
            edit,
        );

        // --------------------------------------------- A tap that finds nothing
        const screenBox = await test('play-screen').boundingBox();
        await page.mouse.click(
            screenBox.x + screenBox.width / 2,
            screenBox.y + screenBox.height * 0.3,
        );
        await soon('a tap on nothing says what can be tapped', () =>
            test('play-hint').isVisible(),
        );
        // (pictured once it has faded in)
        await until(() =>
            test('play-hint').evaluate(
                (hint) => getComputedStyle(hint).opacity === '1',
            ),
        );
        await page.screenshot({ path: `${SHOTS}/prototype-play-hint.png` });
        check(
            'and lights it up',
            (await page.locator('[data-test="play-view"].is-lit').count()) ===
                1,
        );
        await test('play-hint').waitFor({ state: 'detached' });

        // ------------------------------------------------------- Going through
        await spot('order').click();
        await soon('tapping a linked part goes to its screen', on('menu'));
        check(
            'and the address says so',
            page.url().endsWith('#menu'),
            page.url(),
        );
        await spot('add').click();
        await soon('and on again', on('cart'));

        await page.goBack({ waitUntil: 'commit' });
        await soon("the browser's Back comes back a screen", on('menu'));
        await spot('back').click();
        await soon("the prototype's own Back goes back too", on('home'));
        check(
            'with nothing more to go back to',
            await test('play-back').isDisabled(),
        );

        // --------------------------------------------------- The bar and keys
        await test('play-screens').click();
        await page.locator('[data-play-screen="cart"]').click();
        await soon('the screen menu goes to any screen', on('cart'));
        // The menu gone first, or it takes the keys
        await page.locator('[role="menu"]').waitFor({ state: 'detached' });
        await page.keyboard.press('ArrowLeft');
        await soon('and the arrow keys through them in order', on('menu'));
        await test('play-back').click();
        await soon("the bar's Back goes back", on('cart'));

        await reopen(page);
        await test('play-screen').waitFor();
        check(
            'opened again, it is on the screen it was on',
            (await shown()) === 'cart',
        );
        await test('play-restart').click();
        await soon('From the start goes to the start', on('home'));
        await page.screenshot({ path: `${SHOTS}/prototype-play.png` });

        // ------------------------------------------------------------ A desktop
        tinker(
            `$b = App\\Models\\Board\\Board::where('ref_id', '${board}')->firstOrFail(); $b->prototype = ['device' => 'desktop', 'start' => 'home']; $b->save(); echo 'ok';`,
        );
        await reopen(page);
        await test('play-screen').waitFor();
        check(
            'made for a desktop, it is shown in a browser window',
            (await page
                .locator('.play-device.is-desktop .play-window-bar')
                .count()) === 1,
        );
        await page.screenshot({ path: `${SHOTS}/prototype-play-desktop.png` });

        // ---------------------------------------------------------------- Dark
        await page.emulateMedia({ colorScheme: 'dark' });
        await reopen(page);
        await test('play-screen').waitFor();
        await page.screenshot({ path: `${SHOTS}/prototype-play-dark.png` });
        check('it draws in the dark too', (await shown()) === 'home');
        await page.emulateMedia({ colorScheme: 'light' });

        // ------------------------------------------------------------- A phone
        // (made for a phone again: a phone's screen in a desktop's window is
        // shorter than the screen, and scrolls)
        tinker(
            `$b = App\\Models\\Board\\Board::where('ref_id', '${board}')->firstOrFail(); $b->prototype = ['device' => 'phone', 'start' => 'home']; $b->save(); echo 'ok';`,
        );
        await page.setViewportSize({ width: 390, height: 844 });
        await reopen(page);
        await test('play-screen').waitFor();
        const phone = await test('play-screen').boundingBox();
        check(
            'on a phone the screen fills it, with no phone drawn round it',
            (await page.locator('.play-device.is-framed').count()) === 0 &&
                phone.width >= 380 &&
                (await test('play-bar').count()) === 0,
            JSON.stringify(phone),
        );
        await test('play-bar-toggle').click();
        await test('play-bar').waitFor();
        check('its bar is there when asked for', true);
        await page.screenshot({ path: `${SHOTS}/prototype-play-phone.png` });
        await test('play-bar-toggle').click();
        await test('play-bar').waitFor({ state: 'detached' });
        await spot('order').click();
        await soon('and a tap goes on there too', on('menu'));
    },
    { canvas: false },
);
