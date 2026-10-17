// A prototype doing more than going from screen to screen when played: a
// sheet opened over a screen and closed by a tap on the dim, switches and a
// choice that flip, a field typed into and what it says used elsewhere, a
// hidden part shown, a long press and a swipe, a screen that scrolls under a
// header that stays put, a condition, and a screen that moves on by itself.
import {
    SHOTS,
    deleteProject,
    open,
    runBoard,
    shareProject,
    tinker,
    until,
} from './harness.mjs';

const EMAIL = process.env.E2E_EMAIL ?? 'test@example.com';
const MEMBER = process.env.E2E_MEMBER ?? 'e2e-member@example.com';
const RUN = Date.now().toString().slice(-6);

const frame = (id, text, x, height = 844, more = {}) => ({
    id,
    kind: 'frame',
    x,
    y: 0,
    width: 390,
    height,
    text,
    fill: '#ffffff',
    ...more,
});
const part = (id, kind, x, y, width, height, more = {}) => ({
    id,
    kind,
    x,
    y,
    width,
    height,
    text: '',
    fill: '#6366f1',
    ...more,
});

const ITEMS = [
    frame('home', 'Home', 0),
    frame('menu', 'Menu', 470, 1600),
    frame('sheet', 'Sheet', 940, 300),
    frame('done', 'Done', 1410, 844, {
        screen: {
            after: { seconds: 0.6, do: { to: 'home', transition: 'none' } },
        },
    }),
    // Home
    part('open-sheet', 'button', 16, 60, 358, 48, {
        text: 'More',
        link: { to: 'sheet', overlay: 'bottom' },
    }),
    part('wifi', 'toggle', 16, 130, 52, 32, { on: true }),
    part('small', 'radio', 16, 180, 24, 24, { on: true, choice: 'size' }),
    part('large', 'radio', 60, 180, 24, 24, { on: false, choice: 'size' }),
    part('name', 'text_field', 16, 230, 358, 52, {
        text: 'Your name',
        field: 'name',
    }),
    part('hello', 'rect', 16, 300, 358, 48, {
        text: 'Hello {name}',
        startsHidden: true,
    }),
    part('greet', 'button', 16, 370, 358, 48, {
        text: 'Greet',
        link: { all: [{ show: ['hello'] }, { set: 'greeted', value: true }] },
    }),
    part('hold', 'rect', 16, 440, 170, 60, {
        text: 'Hold me',
        when: { 'long-press': { to: 'menu', transition: 'fade' } },
    }),
    part('swipe', 'rect', 204, 440, 170, 60, {
        text: 'Swipe me',
        when: { 'swipe-left': { to: 'menu', transition: 'push' } },
    }),
    // Menu: a header that stays put, and far down, a condition
    part('header', 'rect', 470, 0, 390, 80, {
        text: 'Back',
        fixed: 'top',
        link: { back: true },
    }),
    part('deep', 'button', 486, 1500, 358, 48, {
        text: 'Finish',
        // The contract names it so; nothing here is ever awaited
        // oxlint-disable-next-line unicorn/no-thenable
        link: { if: 'greeted', then: { to: 'done' }, else: { to: 'home' } },
    }),
    // The sheet
    part('close', 'button', 956, 220, 358, 48, {
        text: 'Close',
        link: { close: true },
    }),
];

await runBoard(
    '/dashboard',
    async ({ page, check, soon, afterwards }) => {
        const { project, base } = await shareProject(
            page,
            `Prototype more ${RUN}`,
            MEMBER,
        );
        afterwards(() => deleteProject(page, base, project));

        const board = tinker(
            `$b = (new App\\Models\\Board\\Board)->ownedBy(App\\Models\\Project::where('ref_id', '${project}')->firstOrFail(), App\\Models\\User::where('email', '${EMAIL}')->firstOrFail()); $b->title = 'Coffee shop'; $b->kind = App\\Models\\Board\\Board::PROTOTYPE; $b->prototype = ['device' => 'phone', 'start' => 'home']; $b->content = ['items' => json_decode('${JSON.stringify(ITEMS)}', true)]; $b->save(); echo $b->ref_id;`,
        );

        const test = (name) => page.locator(`[data-test="${name}"]`);
        const shown = () => test('play-screen').getAttribute('data-screen');
        const on = (id) => async () => (await shown()) === id;
        const spot = (id) => page.locator(`[data-hotspot="${id}"]`);
        const values = async () =>
            JSON.parse(
                (await test('play').getAttribute('data-values')) ?? '{}',
            );
        // One screen on the page: the move between two has finished
        const settled = () =>
            until(async () => (await test('play-view').count()) === 1);
        const overlays = async () =>
            Number(await test('play').getAttribute('data-overlays'));

        await open(page, `${base}/prototypes/${board}/play`);
        await test('play-screen').waitFor();

        // ------------------------------------------------- Over the screen
        await spot('open-sheet').click();
        await test('play-overlay').waitFor();
        check(
            'a sheet opens over the screen, which stays behind it',
            (await overlays()) === 1 && (await shown()) === 'home',
        );
        // (pictured once it has come all the way up)
        await until(() =>
            page
                .locator('.play-overlay-screen')
                .evaluate(
                    (sheet) => getComputedStyle(sheet).transform === 'none',
                ),
        );
        await page.screenshot({ path: `${SHOTS}/prototype-more-sheet.png` });
        await spot('close').click();
        await soon('its Close closes it', async () => (await overlays()) === 0);
        await spot('open-sheet').click();
        await test('play-overlay').waitFor();
        // The dim above the sheet, so the tap finds the dim and not the sheet
        const dim = await test('play-dim').boundingBox();
        await page.mouse.click(dim.x + dim.width / 2, dim.y + 40);
        await soon(
            'and so does a tap on the dim behind it',
            async () => (await overlays()) === 0,
        );
        await test('play-overlay').waitFor({ state: 'detached' });

        // --------------------------------------------------- Switches and a choice
        await spot('wifi').click();
        await soon(
            'a switch flips off',
            async () =>
                (await spot('wifi').getAttribute('aria-pressed')) === 'false',
        );
        await spot('large').click();
        await soon(
            'one of a choice tapped is on, and the rest of it off',
            async () =>
                (await spot('large').getAttribute('aria-pressed')) === 'true' &&
                (await spot('small').getAttribute('aria-pressed')) === 'false',
        );

        // --------------------------------------------------- Typing, showing
        check(
            'a hidden part answers nothing until shown',
            (await spot('hello').count()) === 0,
        );
        await page.locator('[data-field="name"]').fill('Ada');
        await soon(
            'what is typed is kept under the field’s name',
            async () => (await values()).name === 'Ada',
        );
        // (this field is a dark indigo: what is typed is written light on it)
        check(
            'and written to be read on its field, not in its hint’s grey',
            (await page
                .locator('[data-field="name"]')
                .evaluate((field) => getComputedStyle(field).color)) ===
                'rgb(248, 250, 252)',
        );
        await spot('greet').click();
        await soon(
            'one tap does several things: a value set',
            async () => (await values()).greeted === true,
        );
        await page.screenshot({ path: `${SHOTS}/prototype-more-home.png` });

        // ------------------------------------------------ A long press, a swipe
        const hold = await spot('hold').boundingBox();
        await page.mouse.move(
            hold.x + hold.width / 2,
            hold.y + hold.height / 2,
        );
        await page.mouse.down();
        await soon('a long press goes where it says', on('menu'), '', {
            timeout: 3000,
        });
        await page.mouse.up();
        // (a fixed wait only to prove nothing comes: letting go is no missed tap)
        await page.waitForTimeout(300);
        check(
            'letting go of a long press is no missed tap',
            (await test('play-hint').count()) === 0,
        );
        await page.goBack({ waitUntil: 'commit' });
        await soon(
            'the browser’s Back comes home, keeping what was typed',
            async () =>
                (await shown()) === 'home' && (await values()).name === 'Ada',
        );
        await settled();

        // A mouse dragged well past the part, as one would on a desktop
        const swipe = await spot('swipe').boundingBox();
        await page.mouse.move(swipe.x + swipe.width - 10, swipe.y + 30);
        await page.mouse.down();
        await page.mouse.move(swipe.x + swipe.width - 170, swipe.y + 30, {
            steps: 8,
        });
        await page.mouse.up();
        await soon(
            'a swipe goes where it says, even dragged off its part',
            on('menu'),
        );
        await settled();

        // ------------------------------------------------- A screen that scrolls
        const scroller = page.locator('[data-test="play-view"] .is-scrolling');
        check(
            'a screen taller than the phone scrolls',
            (await scroller.count()) === 1,
        );
        const header = await spot('header').boundingBox();
        await scroller.evaluate((box) => (box.scrollTop = box.scrollHeight));
        await until(
            async () =>
                (await spot('deep').boundingBox())?.y <
                (await test('play-screen').boundingBox()).y +
                    (await test('play-screen').boundingBox()).height,
        );
        check(
            'with its header staying put',
            (await spot('header').boundingBox()).y === header.y,
        );
        await page.screenshot({ path: `${SHOTS}/prototype-more-scrolled.png` });

        // ------------------------------------------------- A condition, a wait
        await spot('deep').click();
        await soon('a condition goes by what was set', on('done'));
        await soon(
            'and a screen moves on by itself after a while',
            on('home'),
            '',
            { timeout: 4000 },
        );

        // --------------------------------------------------- From the start
        await test('play-restart').click();
        await soon(
            'From the start forgets what was typed and set',
            async () => JSON.stringify(await values()) === '{}',
        );
        check(
            'and puts the switches back',
            (await spot('wifi').getAttribute('aria-pressed')) === 'true',
        );
    },
    { canvas: false },
);
