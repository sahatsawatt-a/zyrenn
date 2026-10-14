// What was added to a person's own pages: a graph in a note drawn from the
// columns of a table, an answer kept out of sight written as Markdown, and,
// on a board and a table, the notes that link to it.
import { SHOTS, makeNote, open, runBoard, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const WHO = process.env.E2E_EMAIL ?? 'test@example.com';

await runBoard(
    '/notes',
    async (b) => {
        const { page, check, soon, afterwards } = b;
        const base = new URL(page.url()).origin;
        const user = `App\\Models\\User::where('email', '${WHO}')->first()`;

        // A table of readings and a board, behind the scenes
        const table = tinker(
            `App\\Mcp\\Servers\\UserServer::actingAs(${user})->tool(App\\Mcp\\Tools\\Tables\\CreateTable::class, ['title' => 'Readings ${RUN}', 'columns' => [['label' => 'Hour', 'type' => 'numeric'], ['label' => 'Voltage', 'type' => 'numeric']], 'rows' => [['Hour' => 0, 'Voltage' => 4.2], ['Hour' => 1, 'Voltage' => 4.0], ['Hour' => 2, 'Voltage' => 3.7], ['Hour' => 3, 'Voltage' => 3.1]]]); echo ${user}->tables()->where('title', 'Readings ${RUN}')->value('ref_id');`,
        );
        const board = tinker(
            `$b = (new App\\Models\\Board\\Board(['title' => 'Plan ${RUN}']))->ownedBy(${user}, ${user}); $b->save(); echo $b->ref_id;`,
        );

        const trip = tinker(
            `App\\Mcp\\Servers\\UserServer::actingAs(${user})->tool(App\\Mcp\\Tools\\Trips\\CreateTrip::class, ['title' => 'Trip ${RUN}']); echo ${user}->trips()->where('title', 'Trip ${RUN}')->value('ref_id');`,
        );

        afterwards(() =>
            tinker(
                `App\\Models\\Table\\Table::where('ref_id', '${table}')->first()?->delete(); App\\Models\\Board\\Board::where('ref_id', '${board}')->first()?->delete(); App\\Models\\Map\\Trip::where('ref_id', '${trip}')->first()?->delete(); echo 'ok';`,
            ),
        );

        const note = await makeNote(
            b,
            `Log ${RUN}`,
            `Drawn on [[board:Plan ${RUN}]], from [[table:Readings ${RUN}]], on [[trip:Trip ${RUN}]].\n\n\`\`\`plot\ntitle: Battery\ntable: Readings ${RUN}\nx: Hour\ny: Voltage\nfit(h) = 4.2 - 0.3 * h\n\`\`\`\n\n\`\`\`plot\ntable: Readings ${RUN}\nx: Hour\ny: Power\n\`\`\`\n\n:::reveal Why it sags\nThe cell is running down.\n:::`,
        );

        // ------------------------------------------------- a graph of a table
        await open(page, `${base}/notes/${note}`);

        const graph = page.locator('.plot-block').first();

        await graph.locator('svg.plot-drawing').waitFor();
        await soon(
            'a graph draws a column of a table as points, beside a formula',
            async () =>
                (await graph.locator('svg.plot-drawing circle[cx]').count()) ===
                    4 &&
                (await graph
                    .locator('svg.plot-drawing g[clip-path] path')
                    .count()) === 2,
        );

        const words = await graph
            .locator('svg.plot-drawing text')
            .allTextContents();

        check(
            'named after the columns',
            ['Battery', 'Voltage', 'fit', 'Hour'].every((word) =>
                words.includes(word),
            ),
            words.join(' | '),
        );
        await soon(
            'a column the table has not got is said, with those it has',
            async () =>
                /has no column called "Power": it has ID, Hour, Voltage/.test(
                    await page.locator('[data-test="plot-error"]').innerText(),
                ),
        );
        await graph.screenshot({ path: `${SHOTS}/labs-plot-table.png` });

        // An answer written into a real note as Markdown, by an AI client
        const reveal = page.locator('[data-test="reveal"]');
        const hidden = reveal.locator('.reveal-body');

        check(
            'a ":::reveal" block written as Markdown is a line to click',
            (await reveal
                .locator('[data-test="reveal-title"]')
                .inputValue()) === 'Why it sags' && !(await hidden.isVisible()),
        );
        await reveal.locator('[data-test="reveal-toggle"]').click();
        await soon(
            'which shows what it holds',
            async () =>
                (await hidden.isVisible()) &&
                (await hidden.innerText()).includes('running down'),
        );

        // ------------------------------------------------ linked from, on a board
        await open(page, `${base}/boards/${board}`);

        const linked = page.locator('[data-test="linked-from"]');

        await linked.waitFor();
        check(
            'a board shows how many notes link to it',
            (await linked.innerText()).trim() === '1',
            await linked.innerText(),
        );

        await linked.click();

        const list = page.locator('[data-test="note-backlinks"]');

        await list.waitFor();
        check(
            'and lists them',
            (await list.innerText()).includes(`Log ${RUN}`),
            await list.innerText(),
        );
        await page.screenshot({ path: `${SHOTS}/labs-linked-from.png` });

        await list.getByRole('link', { name: `Log ${RUN}` }).click();
        await page.waitForURL(new RegExp(`/notes/${note}$`));
        check('each one a way to the note', true);

        // ----------------------------------------------------- and on a table
        await open(page, `${base}/tables/${table}`);
        await soon(
            'a table says who links to it too',
            async () =>
                (
                    await page.locator('[data-test="linked-from"]').innerText()
                ).trim() === '1',
        );

        // ------------------------------------------------------ and on a trip
        await open(page, `${base}/trips/${trip}`);
        await soon(
            'and so does a trip',
            async () =>
                (
                    await page.locator('[data-test="linked-from"]').innerText()
                ).trim() === '1',
        );
        await page.screenshot({ path: `${SHOTS}/labs-trip-linked.png` });
    },
    { canvas: false },
);
