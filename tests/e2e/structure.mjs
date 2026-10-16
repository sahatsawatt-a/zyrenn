// Shapes with a structure inside them: a table's columns set a part at a
// time or written as text, a line from a foreign key to the column it points
// at, the schema read back as SQL, a lane's title turned to its side, and a
// message that stays where it was put on a lifeline.
import { SHOTS, runBoard, until } from './harness.mjs';

// How rows are laid out under a title, at the size a new table is set in
// (model/rows.ts)
const HEAD = 34;
const ROW = 23;
const tall = (rows) => HEAD + rows * ROW + 6;

await runBoard('/demo/konva', async (b) => {
    const { page, check, soon, drawAt, join, screenOf, fields, setField } = b;
    const { inspectorTitle, link } = b;
    const test = (name) => page.locator(`[data-test="${name}"]`);

    /** Picks up the thing whose box this is, by its top edge. */
    const choose = async (box) => {
        const top = await screenOf(box.x + box.w / 2, box.y + 10);
        await page.mouse.click(top.x, top.y);
        await until(
            async () => !/nothing selected/i.test(await inspectorTitle()),
        );
    };

    /** A row's edge on the board, where a connector holds on to it. */
    const rowAt = (box, index, side) =>
        screenOf(
            side === 'left' ? box.x : box.x + box.w,
            box.y + HEAD + index * ROW + ROW / 2,
        );

    // ------------------------------------------------------- A table's rows
    const orders = await drawAt('table', 520, -900, 240, 110);
    await choose(orders);
    await test('structure-config').waitFor();
    check(
        'a table starts with columns of its own, each a row to edit',
        (await test('row-name-0').inputValue()) === 'id' &&
            (await test('row-type-0').inputValue()) === 'int' &&
            (await test('row-mark-0').innerText()).trim() === 'PK',
        `${await test('row-name-0').inputValue()} ${await test('row-type-0').inputValue()}`,
    );

    await test('row-add').click();
    await test('row-name-2').fill('customer_id');
    await test('row-name-2').press('Enter');
    await test('row-type-2').fill('int');
    await test('row-type-2').press('Enter');
    await test('row-mark-2').click();
    await test('row-mark-2-fk').click();
    await page.keyboard.press('Escape');
    await soon(
        'a column is added and made a foreign key, and the table grows to it',
        async () =>
            (await test('row-mark-2').innerText()).trim() === 'FK' &&
            (await fields()).h === tall(3),
        async () => `${(await fields()).h} tall`,
    );

    // The whole list at once, as text: a row keeps its place by its name
    await test('rows-as-text').click();
    await test('rows-text').fill(
        'id int pk\ncustomer_id int fk\ntotal decimal\nnote text?',
    );
    await test('rows-text').press('Tab');
    await test('rows-as-text').click();
    await soon(
        'written as text, the columns are read back a row each',
        async () =>
            (await test('row-name-3').inputValue()) === 'note' &&
            (await test('row-mark-1').innerText()).trim() === 'FK' &&
            (await fields()).h === tall(4),
        async () => `${(await fields()).h} tall`,
    );
    await test('row-mark-3').click();
    check(
        'a "?" on its type lets a column be empty',
        (await test('row-nullable-3').getAttribute('aria-checked')) === 'true',
    );
    await page.keyboard.press('Escape');
    await page.keyboard.press('Escape');

    // ------------------------------------------- A line from row to row
    const customers = await drawAt('table', 100, -900, 240, 110);

    await join(orders, customers, {
        from: await rowAt(orders, 1, 'left'),
        to: await rowAt(customers, 0, 'right'),
    });
    await soon(
        'a connector dropped on a row holds on to that row',
        async () => /table\.customer_id → table\.id/.test(await link()),
        () => link(),
    );
    await test('connector-type').click();
    await test('connector-type-one-to-any').click();
    await page.keyboard.press('Escape');

    // ------------------------------------------------ The schema as SQL
    await test('board-schema').click();
    await test('schema-dialog').waitFor();
    let sql;
    await soon(
        'the tables are written out as SQL, the foreign key pointing where its line does',
        async () => {
            sql = await test('schema-text').innerText();

            return (
                /CREATE TABLE/.test(sql) &&
                /FOREIGN KEY \(customer_id\) REFERENCES \w+ \(id\)/.test(sql) &&
                /note TEXT,?\n/.test(sql) &&
                // (and no second key made up for a line already on one)
                !/-- added/.test(sql)
            );
        },
        () => sql?.slice(0, 200),
    );
    await test('schema-mermaid').click();
    await soon('or as a Mermaid erDiagram, for a note', async () =>
        (await test('schema-text').innerText()).startsWith('erDiagram'),
    );
    await page.screenshot({ path: `${SHOTS}/structure-sql.png` });
    await page.keyboard.press('Escape');
    await test('schema-dialog').waitFor({ state: 'detached' });

    // ---------------------------------------------------------- A lane
    const lane = await drawAt('lane', 900, -900, 400, 160);
    await choose(lane);
    await test('header-left').click();
    await soon("a lane's title can run down its side", async () =>
        (await test('header-left').getAttribute('class')).includes('is-on'),
    );
    await page.keyboard.press('Escape');

    // ------------------------------------------------------ A lifeline
    const one = await drawAt('lifeline', 100, -560, 150, 300);
    const other = await drawAt('lifeline', 500, -560, 150, 300);
    const down = 120;

    await join(one, other, {
        from: await screenOf(one.x + one.w / 2, one.y + down),
        to: await screenOf(other.x + other.w / 2, other.y + down),
    });

    // Where the newest connector's line starts, on the board
    const startOf = () =>
        page.evaluate(() => {
            const stage = window.Konva.stages[0];
            // (a connector is a line and its caps, with no shape of its own)
            const lines = stage
                .find('Group')
                .filter(
                    (group) =>
                        group.id() &&
                        group.findOne('Line') &&
                        !group.findOne('Path') &&
                        !group.findOne('Rect'),
                );
            const points = lines.at(-1)?.findOne('Line').points();

            return points ? { x: points[0], y: points[1] } : null;
        });

    let start;
    await soon(
        'a message stays at the height it was dropped on a lifeline',
        async () => {
            start = await startOf();

            return (
                start &&
                Math.abs(start.y - (one.y + down)) < 3 &&
                Math.abs(start.x - (one.x + one.w / 2)) < 3
            );
        },
        () => JSON.stringify(start),
    );

    await choose(one);
    await setField('height', one.h * 2);
    await soon(
        'and keeps its place down the lifeline when that is made longer',
        async () => {
            start = await startOf();

            return start && Math.abs(start.y - (one.y + down * 2)) < 4;
        },
        () => JSON.stringify(start),
    );

    await page.keyboard.press('Escape');
    await page.screenshot({ path: `${SHOTS}/structure.png` });
});
