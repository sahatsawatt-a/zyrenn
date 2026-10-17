// A project table as a prototype's database, played as an app: the list
// screen repeats a row card for each row with its {Column}s filled in, a
// tap opens that row's detail, a form adds a row, another changes one and a
// button deletes one -- all on the play's own copy, which From the start
// puts back while the real table stays as it was. Set to save to the tables,
// an editor's new row is really in the table; a viewer still plays on a copy.
import {
    deleteProject,
    open,
    runBoard,
    shareProject,
    signIn,
    tinker,
    until,
} from './harness.mjs';

const EMAIL = process.env.E2E_EMAIL ?? 'test@example.com';
const MEMBER = process.env.E2E_MEMBER ?? 'e2e-member@example.com';
const RUN = Date.now().toString().slice(-6);

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
const words = (id, text, x, y, extra = {}) => ({
    id,
    kind: 'text',
    x,
    y,
    width: 350,
    height: 40,
    text,
    fontSize: 22,
    ...extra,
});
const button = (id, text, x, y, link) => ({
    id,
    kind: 'rect',
    x,
    y,
    width: 160,
    height: 56,
    text,
    fill: '#6366f1',
    link,
});
const field = (id, name, x, y) => ({
    id,
    kind: 'text_field',
    x,
    y,
    width: 350,
    height: 48,
    text: name,
    field: name,
});

/** The app: a list, a row's detail, a form to add one and one to change it. */
const itemsFor = (table) => [
    screen('list', 'Tickets', 0),
    words('heading', 'Tickets', 20, 30),
    {
        id: 'card',
        kind: 'rect',
        x: 20,
        y: 100,
        width: 350,
        height: 64,
        text: '{Title} · {Points}',
        fill: '#f1f5f9',
        repeat: { table, gap: 12 },
        link: { to: 'detail' },
    },
    button('add', 'New', 20, 760, { to: 'form' }),

    screen('detail', 'Ticket', 470),
    words('detail-title', 'Ticket: {Title}', 490, 100),
    button('edit', 'Edit', 490, 680, { to: 'edit' }),
    button('delete', 'Delete', 670, 680, {
        all: [{ remove: table }, { to: 'list' }],
    }),
    button('back', 'Back', 490, 760, { back: true }),

    screen('form', 'New ticket', 940),
    field('title-field', 'Title', 960, 100),
    field('points-field', 'Points', 960, 170),
    button('save', 'Save', 960, 760, {
        all: [{ save: table }, { to: 'list' }],
    }),

    screen('edit', 'Edit ticket', 1410),
    field('edit-title', 'Title', 1430, 100),
    button('save-edit', 'Save', 1430, 760, {
        all: [{ save: table, row: 'current' }, { to: 'list' }],
    }),
];

await runBoard(
    '/dashboard',
    async (b) => {
        const { page, browser, check, soon, afterwards } = b;
        const { project, base } = await shareProject(
            page,
            `Tables ${RUN}`,
            MEMBER,
            'viewer',
        );
        afterwards(() => deleteProject(page, base, project));

        const me = `App\\Models\\User::where('email', '${EMAIL}')->firstOrFail()`;
        tinker(
            `App\\Mcp\\Servers\\UserServer::actingAs(${me})->tool(App\\Mcp\\Tools\\Tables\\CreateTable::class, ['project' => '${project}', 'title' => 'Tickets ${RUN}', 'columns' => [['label' => 'Title'], ['label' => 'Points', 'type' => 'integer']], 'rows' => [['Title' => 'Login page', 'Points' => 3], ['Title' => 'Checkout', 'Points' => 5]]]); echo 'ok';`,
        );
        const table = tinker(
            `echo App\\Models\\Table\\Table::where('title', 'Tickets ${RUN}')->value('ref_id');`,
        );
        const board = tinker(
            `$b = (new App\\Models\\Board\\Board)->ownedBy(App\\Models\\Project::where('ref_id', '${project}')->firstOrFail(), ${me}); $b->title = 'Tracker'; $b->kind = App\\Models\\Board\\Board::PROTOTYPE; $b->prototype = ['device' => 'phone', 'start' => 'list']; $b->content = ['items' => json_decode('${JSON.stringify(itemsFor(table))}', true)]; $b->save(); echo $b->ref_id;`,
        );

        /** The real table's titles, in the order they were added. */
        const kept = () =>
            JSON.parse(
                tinker(
                    `echo json_encode(array_column(App\\Support\\Table\\TableStorage::rows(App\\Models\\Table\\Table::where('ref_id', '${table}')->firstOrFail()), App\\Support\\Table\\RowValues::column(App\\Models\\Table\\Table::where('ref_id', '${table}')->firstOrFail(), 'Title')->name));`,
                ),
            );
        const idOf = (title) =>
            tinker(
                `$t = App\\Models\\Table\\Table::where('ref_id', '${table}')->firstOrFail(); $name = App\\Support\\Table\\RowValues::column($t, 'Title')->name; echo collect(App\\Support\\Table\\TableStorage::rows($t))->firstWhere($name, '${title}')['id'];`,
            );

        // ----------------------------------------------------- one player
        const playing = (on) => {
            const test = (name) => on.locator(`[data-test="${name}"]`);

            return {
                test,
                // Every word the screen draws, as one string
                drawn: () =>
                    on.evaluate(() =>
                        (window.Konva?.stages ?? [])
                            .flatMap((stage) =>
                                stage.find('Text').map((text) => text.text()),
                            )
                            .join(' | '),
                    ),
                shown: () => test('play-screen').getAttribute('data-screen'),
                // Settled on a screen: the move to it has finished
                settled: () =>
                    until(async () => (await test('play-view').count()) === 1),
                tap: (id) =>
                    on.locator(`[data-hotspot="${id}"]`).first().click(),
                type: (name, text) =>
                    on.locator(`[data-field="${name}"]`).first().fill(text),
            };
        };
        const at = async (player, id) => {
            await until(async () => (await player.shown()) === id);
            await player.settled();
        };

        const me1 = playing(page);
        await open(page, `${base}/prototypes/${board}/play`);
        await me1.test('play-screen').waitFor();
        await at(me1, 'list');

        await soon(
            'the list repeats its card for each row, {Column}s filled in',
            async () => {
                const drawn = await me1.drawn();

                return (
                    drawn.includes('Login page · 3') &&
                    drawn.includes('Checkout · 5') &&
                    !drawn.includes('{Title}')
                );
            },
            me1.drawn,
        );

        await me1.tap(`card::${idOf('Checkout')}`);
        await at(me1, 'detail');
        await soon(
            'tapping a row opens its detail, showing that row',
            async () => (await me1.drawn()).includes('Ticket: Checkout'),
            me1.drawn,
        );
        await me1.tap('back');
        await at(me1, 'list');

        // --------------------------------------------- add, change, delete
        await me1.tap('add');
        await at(me1, 'form');
        await me1.type('Title', 'Search');
        await me1.type('Points', '8');
        await me1.tap('save');
        await at(me1, 'list');
        await soon(
            'a row added through a form is in the list',
            async () => (await me1.drawn()).includes('Search · 8'),
            me1.drawn,
        );

        await me1.tap(`card::${idOf('Login page')}`);
        await at(me1, 'detail');
        await me1.tap('edit');
        await at(me1, 'edit');
        await me1.type('Title', 'Sign in page');
        await me1.tap('save-edit');
        await at(me1, 'list');
        await soon(
            'a row changed through a form shows its new words',
            async () => {
                const drawn = await me1.drawn();

                return (
                    drawn.includes('Sign in page · 3') &&
                    !drawn.includes('Login page')
                );
            },
            me1.drawn,
        );

        await me1.tap(`card::${idOf('Checkout')}`);
        await at(me1, 'detail');
        await me1.tap('delete');
        await at(me1, 'list');
        await soon(
            'a row deleted is gone from the list',
            async () => !(await me1.drawn()).includes('Checkout'),
            me1.drawn,
        );

        // ------------------------------------------- From the start
        await me1.test('play-restart').click();
        await at(me1, 'list');
        await soon(
            'From the start brings back the real rows',
            async () => {
                const drawn = await me1.drawn();

                return (
                    drawn.includes('Login page · 3') &&
                    drawn.includes('Checkout · 5') &&
                    !drawn.includes('Search')
                );
            },
            me1.drawn,
        );
        check(
            'and the real table was never changed',
            JSON.stringify(kept()) === '["Login page","Checkout"]',
            JSON.stringify(kept()),
        );

        // ------------------------------------- saved to the tables, by an editor
        await open(page, `${base}/prototypes/${board}`);
        await page.locator('[data-test="prototype-device"]').click();
        await page.locator('[data-prototype-data="tables"]').click();
        await until(
            () =>
                tinker(
                    `echo App\\Models\\Board\\Board::where('ref_id', '${board}')->value('prototype')['data'] ?? '';`,
                ) === 'tables',
        );

        await open(page, `${base}/prototypes/${board}/play`);
        await me1.test('play-screen').waitFor();
        await at(me1, 'list');
        await me1.tap('add');
        await at(me1, 'form');
        await me1.type('Title', 'Billing');
        await me1.type('Points', '2');
        await me1.tap('save');
        await at(me1, 'list');
        await soon(
            "set to save to the tables, an editor's new row is really in the table",
            async () => kept().includes('Billing'),
            async () => JSON.stringify(kept()),
        );

        // ------------------------------------------- a viewer, on a copy
        const { context, page: theirs } = await signIn(browser, base, MEMBER);
        afterwards(() => context.close());
        const viewer = playing(theirs);

        await open(theirs, `${base}/prototypes/${board}/play`);
        await viewer.test('play-screen').waitFor();
        await at(viewer, 'list');
        await viewer.tap('add');
        await at(viewer, 'form');
        await viewer.type('Title', 'From a viewer');
        await viewer.tap('save');
        await at(viewer, 'list');
        await soon(
            'a viewer sees their row in their play',
            async () => (await viewer.drawn()).includes('From a viewer'),
            viewer.drawn,
        );
        check(
            'but it is not saved to the table',
            !kept().includes('From a viewer'),
            JSON.stringify(kept()),
        );
    },
    { canvas: false },
);
