import { describe, expect, it } from 'vitest';
import type { Item } from '@/features/boards/model/items';
import { makeItem } from '@/features/boards/model/items';
import type { Action } from '@/features/prototypes/interactions';
import {
    fill,
    freshData,
    pick,
    removeRow,
    resolve,
    rowsFor,
    said,
    saveRow,
    settle,
    withRows,
} from './data';
import type { Tables } from './data';
import { drawnOf, freshState, perform } from './engine';

const TABLES: Tables = {
    t1: {
        title: 'Tickets',
        columns: [
            { label: 'Title', type: 'text' },
            { label: 'Priority', type: 'select' },
            { label: 'Done', type: 'checkbox' },
            { label: 'Score', type: 'number' },
            { label: 'Label', type: 'formula' },
        ],
        rows: [
            {
                id: '1',
                Title: 'Menu shakes',
                Priority: 'High',
                Done: false,
                Score: 9,
            },
            {
                id: '2',
                Title: 'Dark board',
                Priority: 'Low',
                Done: true,
                Score: 10,
            },
            {
                id: '3',
                Title: 'Pen lag',
                Priority: 'High',
                Done: false,
                Score: 2,
            },
        ],
    },
    p1: {
        title: 'People',
        columns: [{ label: 'Name', type: 'text' }],
        rows: [{ id: '7', Name: 'Ada' }],
    },
};

const item = (
    id: string,
    kind: string,
    x: number,
    y: number,
    w: number,
    h: number,
    more: object = {},
): Item =>
    ({
        ...makeItem('rect', x, y),
        id,
        kind,
        width: w,
        height: h,
        ...more,
    }) as Item;

describe('words from the tables', () => {
    const data = pick(pick(freshData(TABLES), { table: 'p1', row: '7' }), {
        table: 't1',
        row: '2',
    });

    it('a column of the current row, by its label in any case', () => {
        expect(resolve('title', {}, data)).toBe('Dark board');
        expect(fill('{TITLE} is {Priority}', {}, data)).toBe(
            'Dark board is Low',
        );
    });

    it('a value typed wins over a column', () => {
        expect(resolve('Title', { Title: 'Typed' }, data)).toBe('Typed');
    });

    it('inside a row card, the row in hand wins over a value typed under its column', () => {
        expect(
            resolve('Title', { Title: 'Typed' }, data, {
                table: 't1',
                row: '3',
            }),
        ).toBe('Pen lag');
    });

    it('the row in hand wins over the current one', () => {
        expect(resolve('Title', {}, data, { table: 't1', row: '3' })).toBe(
            'Pen lag',
        );
    });

    it('another table by its title, or any current row with the column', () => {
        expect(resolve('People.Name', {}, data)).toBe('Ada');
        expect(resolve('Name', {}, data)).toBe('Ada');
    });

    it('nothing by that name says nothing', () => {
        expect(resolve('Nope', {}, data)).toBeNull();
        expect(fill('[{Nope}]', {}, data)).toBe('[]');
    });

    it('lists, yes and no, and places read as words', () => {
        expect(said(['A', 'B'])).toBe('A, B');
        expect(said(true)).toBe('Yes');
        expect(said(null)).toBe('');
        expect(said({ lat: 1, lng: 2, label: 'Bangkok' })).toBe('Bangkok');
    });
});

describe('the rows a card shows', () => {
    const data = freshData(TABLES);

    it('only those that match, in order, so many', () => {
        const rows = rowsFor(
            {
                table: 't1',
                where: [{ column: 'priority', is: 'high' }],
                sort: { column: 'Score', desc: true },
                limit: 1,
            },
            {},
            data,
        );

        expect(rows.map((row) => row.Title)).toEqual(['Menu shakes']);
    });

    it('a filter may say {name}', () => {
        const rows = rowsFor(
            { table: 't1', where: [{ column: 'Priority', is: '{want}' }] },
            { want: 'Low' },
            data,
        );

        expect(rows.map((row) => row.id)).toEqual(['2']);
    });

    it('numbers sort as numbers', () => {
        expect(
            rowsFor({ table: 't1', sort: { column: 'Score' } }, {}, data).map(
                (row) => row.Score,
            ),
        ).toEqual([2, 9, 10]);
    });

    it('a table it has not got shows none', () => {
        expect(rowsFor({ table: 'gone' }, {}, data)).toEqual([]);
    });
});

describe('a row card on a screen', () => {
    const items = [
        item('home', 'frame', 0, 0, 390, 844),
        item('card', 'card', 16, 100, 358, 80, {
            repeat: { table: 't1', gap: 10 },
            link: { to: 'detail' },
        }),
        item('title', 'text', 32, 110, 200, 30, { text: '{Title}' }),
        item('below', 'button', 16, 200, 358, 48),
        item('tabs', 'tab_bar', 0, 760, 390, 84, { fixed: 'bottom' }),
    ];
    const drawn = withRows(items, {}, freshData(TABLES));
    const byId = new Map(drawn.items.map((one) => [one.id, one]));

    it('is drawn once for each row, a step apart', () => {
        expect(
            ['card::1', 'card::2', 'card::3'].map((id) => byId.get(id)?.y),
        ).toEqual([100, 190, 280]);
        expect(byId.has('card')).toBe(false);
        expect(byId.get('title::2')!.y).toBe(200);
    });

    it('knows which row each copy is for', () => {
        expect(drawn.rows.get('title::3')).toEqual({ table: 't1', row: '3' });
        expect(drawn.copies.get('title::3')).toBe('title');
    });

    it('moves what lies below it down, and the screen grows; what is fixed to the foot stays at the foot', () => {
        expect(byId.get('below')!.y).toBe(200 + 180);
        expect(byId.get('home')!.height).toBe(844 + 180);
        // As far from the grown screen's foot as it was from the foot before
        expect(byId.get('tabs')!.y).toBe(760 + 180);
    });

    it('with no rows, what is fixed to the foot stays where it was', () => {
        const none = withRows(items, {}, freshData({}));

        expect(none.items.find((one) => one.id === 'tabs')!.y).toBe(760);
    });

    it('each copy says its own row once drawn', () => {
        const shown = drawnOf(items, freshState(TABLES));
        const words = new Map(shown.items.map((one) => [one.id, one.text]));

        expect(words.get('title::1')).toBe('Menu shakes');
        expect(words.get('title::2')).toBe('Dark board');
    });

    it('with no rows, it goes, and what was below comes up', () => {
        const none = withRows(items, {}, freshData({}));
        const after = new Map(none.items.map((one) => [one.id, one]));

        expect([...after.keys()].some((id) => id.startsWith('card'))).toBe(
            false,
        );
        expect(after.get('below')!.y).toBe(200 - 90);
    });
});

describe('saving', () => {
    it('a new row from the values said, and the fields named like its columns', () => {
        const { data, write } = saveRow(
            freshData(TABLES),
            't1',
            'new',
            { priority: 'High' },
            {
                title: 'From a field',
                Label: 'never saved: worked out by the table',
            },
        );
        const rows = data.tables.t1.rows;
        const added = rows[rows.length - 1];

        expect(added).toMatchObject({
            Title: 'From a field',
            Priority: 'High',
        });
        expect(added.Label).toBeUndefined();
        expect(data.current.t1).toBe(added.id);
        expect(write).toMatchObject({ kind: 'save', table: 't1', isNew: true });
    });

    it('only from the fields on the screen it is saved from', () => {
        const start = pick(freshData(TABLES), { table: 't1', row: '1' });
        // Score was typed on another screen's form: this one shows only Title
        const { data } = saveRow(
            start,
            't1',
            'current',
            undefined,
            { Title: 'Edited', Score: '99' },
            new Set(['Title']),
        );

        expect(data.tables.t1.rows[0]).toMatchObject({
            Title: 'Edited',
            Score: 9,
        });
    });

    it('the current row changed, by what is said', () => {
        const start = pick(freshData(TABLES), { table: 't1', row: '2' });
        const { data, write } = saveRow(
            start,
            't1',
            'current',
            { Title: '{Title}!' },
            {},
        );

        expect(data.tables.t1.rows.find((row) => row.id === '2')!.Title).toBe(
            'Dark board!',
        );
        expect(write).toMatchObject({ isNew: false, row: { id: '2' } });
    });

    it('the current row deleted, none current after', () => {
        const start = pick(freshData(TABLES), { table: 't1', row: '1' });
        const { data, write } = removeRow(start, 't1');

        expect(data.tables.t1.rows.map((row) => row.id)).toEqual(['2', '3']);
        expect(data.current.t1).toBeUndefined();
        expect(write).toEqual({ kind: 'remove', table: 't1', row: '1' });
    });

    it('a play copies the tables: saving never changes what it was given', () => {
        saveRow(freshData(TABLES), 't1', 'new', { Title: 'x' }, {});

        expect(TABLES.t1.rows).toHaveLength(3);
    });

    it('the row the table gives back takes the place of the one Play made', () => {
        const { data } = saveRow(
            freshData(TABLES),
            't1',
            'new',
            { Title: 'x' },
            {},
        );
        const made = data.current.t1;
        const now = settle(data, 't1', made, {
            id: '42',
            Title: 'x',
            Score: 0,
        });

        expect(now.current.t1).toBe('42');
        expect(now.tables.t1.rows.some((row) => row.id === made)).toBe(false);
    });

    it('as actions: save, then go on; a condition reads a column', () => {
        const start = {
            ...freshState(TABLES),
            data: pick(freshData(TABLES), { table: 't1', row: '2' }),
        };
        const screens = new Set(['detail', 'done']);
        const check: Action = {
            if: 'Done',
            equals: 'Yes',
            // The contract names it so; nothing here is ever awaited
            // oxlint-disable-next-line unicorn/no-thenable
            then: { to: 'done' },
        };

        expect(perform(check, start, [], screens).moves).toEqual([
            { kind: 'to', screen: 'done', transition: 'slide' },
        ]);

        const saved = perform(
            {
                all: [
                    { save: 't1', values: { Title: 'New' } },
                    { to: 'detail' },
                ],
            },
            freshState(TABLES),
            [],
            screens,
        );

        expect(saved.writes).toHaveLength(1);
        expect(saved.moves).toHaveLength(1);
        expect(saved.state.data.tables.t1.rows).toHaveLength(4);
    });
});
