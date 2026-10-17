// A prototype with a project table as its database, played: a row card shown
// for each row, what was below it moved down; a row tapped opens its detail
// with its words; a row added from a form and deleted again -- on the play's
// own copy, which From the start puts back; and, set to save to the tables,
// the same reaching the real table.
import {
    deleteProject,
    open,
    runBoard,
    SHOTS,
    shareProject,
    tinker,
    until,
} from './harness.mjs';

const EMAIL = process.env.E2E_EMAIL ?? 'test@example.com';
const MEMBER = process.env.E2E_MEMBER ?? 'e2e-member@example.com';
const RUN = Date.now().toString().slice(-6);

const frame = (id, text, x) => ({
    id,
    kind: 'frame',
    x,
    y: 0,
    width: 390,
    height: 844,
    text,
    fill: '#ffffff',
});
const part = (id, kind, x, y, width, height, more = {}) => ({
    id,
    kind,
    x,
    y,
    width,
    height,
    text: '',
    fill: '#e0e7ff',
    ...more,
});

const itemsFor = (table) => [
    frame('list', 'List', 0),
    frame('detail', 'Detail', 470),
    frame('new', 'New', 940),
    // The list: one card, repeated for each row, highest points first
    part('card', 'card', 16, 100, 358, 80, {
        text: '{Title}',
        repeat: { table, sort: { column: 'Points', desc: true }, gap: 12 },
        link: { to: 'detail' },
    }),
    part('points', 'text', 32, 140, 120, 30, { text: '{Points} points' }),
    part('add', 'button', 16, 200, 358, 48, {
        text: 'Add',
        link: { to: 'new' },
    }),
    // A tab bar at the foot, which the list growing must not move
    part('tabs', 'button', 0, 790, 390, 54, {
        text: 'Tabs',
        fixed: 'bottom',
        link: { to: 'list' },
    }),
    // A row's detail: its title says it, and it can be deleted
    part('heading', 'button', 486, 100, 358, 48, {
        text: 'About {Title}',
        link: { back: true },
    }),
    part('delete', 'button', 486, 700, 358, 48, {
        text: 'Delete',
        link: { all: [{ remove: table }, { to: 'list' }] },
    }),
    // A form: what is typed into Title becomes a new row's Title
    part('title', 'text_field', 956, 100, 358, 52, {
        text: 'Title',
        field: 'Title',
    }),
    part('save', 'button', 956, 700, 358, 48, {
        text: 'Save',
        link: {
            all: [
                { save: table, values: { Points: '1', Status: 'Open' } },
                { to: 'list' },
            ],
        },
    }),
];

await runBoard(
    '/dashboard',
    async ({ page, check, soon, afterwards }) => {
        const { project, base } = await shareProject(
            page,
            `Prototype data ${RUN}`,
            MEMBER,
        );
        afterwards(() => deleteProject(page, base, project));

        const who = `App\\Models\\User::where('email', '${EMAIL}')->firstOrFail()`;
        const table = tinker(
            `App\\Mcp\\Servers\\UserServer::actingAs(${who})->tool(App\\Mcp\\Tools\\Tables\\CreateTable::class, ['project' => '${project}', 'title' => 'Tickets ${RUN}', 'columns' => [['label' => 'Title'], ['label' => 'Points', 'type' => 'integer'], ['label' => 'Status', 'type' => 'select', 'choices' => ['Open', 'Done']]], 'rows' => [['Title' => 'Login page', 'Points' => 3, 'Status' => 'Open'], ['Title' => 'Checkout', 'Points' => 5, 'Status' => 'Done']]]); echo App\\Models\\Table\\Table::where('title', 'Tickets ${RUN}')->latest('id')->firstOrFail()->ref_id;`,
        );
        const realRows = () =>
            Number(
                tinker(
                    `echo App\\Support\\Table\\TableStorage::count(App\\Models\\Table\\Table::where('ref_id', '${table}')->firstOrFail());`,
                ),
            );

        const board = tinker(
            `$b = (new App\\Models\\Board\\Board)->ownedBy(App\\Models\\Project::where('ref_id', '${project}')->firstOrFail(), ${who}); $b->title = 'Ticket list'; $b->kind = App\\Models\\Board\\Board::PROTOTYPE; $b->prototype = ['device' => 'phone', 'start' => 'list']; $b->content = ['items' => json_decode('${JSON.stringify(itemsFor(table))}', true)]; $b->save(); echo $b->ref_id;`,
        );

        const test = (name) => page.locator(`[data-test="${name}"]`);
        const shown = () => test('play-screen').getAttribute('data-screen');
        const on = (id) => async () => (await shown()) === id;
        const settled = () =>
            until(async () => (await test('play-view').count()) === 1);
        const cards = () => page.locator('[data-hotspot^="card::"]');
        const titles = async () =>
            cards().evaluateAll((all) =>
                all.map((one) => one.getAttribute('aria-label')),
            );
        const rows = async () =>
            JSON.parse((await test('play').getAttribute('data-rows')) ?? '{}')[
                table
            ];

        await open(page, `${base}/prototypes/${board}/play`);
        await test('play-screen').waitFor();

        // ------------------------------------------------------------ The list
        await soon(
            'a row card is shown for each row, in its order',
            async () =>
                JSON.stringify(await titles()) ===
                JSON.stringify(['Checkout', 'Login page']),
            async () => JSON.stringify(await titles()),
        );
        const second = await cards().nth(1).boundingBox();
        const add = await page.locator('[data-hotspot="add"]').boundingBox();
        check(
            'and what was below the card moves down to make room',
            add.y > second.y + second.height,
            JSON.stringify({ second, add }),
        );
        const glass = await test('play-screen').boundingBox();
        const tabs = await page.locator('[data-hotspot="tabs"]').boundingBox();
        check(
            'and the bar fixed to the foot stays at the foot',
            Math.abs(tabs.y + tabs.height - (glass.y + glass.height)) < 2,
            JSON.stringify({ tabs, glass }),
        );
        await page.screenshot({ path: `${SHOTS}/prototype-data-list.png` });

        // ------------------------------------------------ A row's own detail
        await cards().nth(1).click();
        await soon('tapping a row opens its detail', on('detail'));
        await settled();
        check(
            'with that row’s words',
            (await page
                .locator('[data-hotspot="heading"]')
                .getAttribute('aria-label')) === 'About Login page',
        );

        // ------------------------------------------- Added, then deleted, in play
        await page.locator('[data-hotspot="heading"]').click();
        await soon('(back to the list)', on('list'));
        await settled();
        await page.locator('[data-hotspot="add"]').click();
        await soon('(the form)', on('new'));
        await settled();
        await page.locator('[data-field="Title"]').fill('Fresh one');
        await page.locator('[data-hotspot="save"]').click();
        await soon('saving adds a row', async () => (await rows()) === 3);
        await soon('and the list shows it', async () =>
            (await titles()).includes('Fresh one'),
        );
        await settled();
        check(
            'in the play’s own copy: the real table is as it was',
            realRows() === 2,
        );

        await page
            .locator('[data-hotspot^="card::"][aria-label="Fresh one"]')
            .click();
        await soon('(its detail)', on('detail'));
        await settled();
        await page.locator('[data-hotspot="delete"]').click();
        await soon(
            'deleting takes the row away',
            async () => (await rows()) === 2 && (await shown()) === 'list',
        );
        await settled();

        await page.locator('[data-hotspot="add"]').click();
        await soon('(the form again)', on('new'));
        await settled();
        await page.locator('[data-field="Title"]').fill('Gone at restart');
        await page.locator('[data-hotspot="save"]').click();
        await soon('(added)', async () => (await rows()) === 3);
        await test('play-restart').click();
        await soon(
            'From the start puts the play’s copy back',
            async () => (await rows()) === 2,
        );

        // ------------------------------------------------- Saving to the table
        tinker(
            `$b = App\\Models\\Board\\Board::where('ref_id', '${board}')->firstOrFail(); $b->prototype = [...$b->prototype, 'data' => 'tables']; $b->save(); echo 'ok';`,
        );
        await open(page, `${base}/prototypes/${board}/play`);
        await test('play-screen').waitFor();
        await settled();
        await page.locator('[data-hotspot="add"]').click();
        await soon('(the form, saving to the table)', on('new'));
        await settled();
        await page.locator('[data-field="Title"]').fill('Kept for real');
        await page.locator('[data-hotspot="save"]').click();
        await soon(
            'set to save to the tables, a row added reaches the table',
            async () => realRows() === 3,
        );
        await soon(
            'and comes back with the id the table gave it',
            async () =>
                (
                    await cards().evaluateAll((all) =>
                        all.map((one) => one.dataset.hotspot),
                    )
                ).some((id) => /^card::\d+$/.test(id) && !id.endsWith('::1')) &&
                !(
                    await cards().evaluateAll((all) =>
                        all.map((one) => one.dataset.hotspot),
                    )
                ).some((id) => id.includes('new-')),
        );
        await settled();
        await page.screenshot({ path: `${SHOTS}/prototype-data-saved.png` });

        await page
            .locator('[data-hotspot^="card::"][aria-label="Kept for real"]')
            .click();
        await soon('(its detail)', on('detail'));
        await settled();
        await page.locator('[data-hotspot="delete"]').click();
        await soon(
            'and deleted, it goes from the table too',
            async () => realRows() === 2,
        );
    },
    { canvas: false },
);
