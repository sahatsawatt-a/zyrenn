import { describe, expect, it } from 'vitest';
import {
    HEAD_TYPES,
    connectorPoints,
    connectorTypeOf,
    headParts,
    heldFor,
} from './connectors';
import { PATHS, hydrate, isPath, makeItem, nameOf } from './items';
import type { Item } from './items';
import { labelBox } from '../drawing/labels';
import {
    laidRows,
    makeRow,
    rowAnchor,
    rowsFromText,
    rowsHeight,
    rowsToText,
} from './rows';
import { schemaAsMermaid, schemaAsSql } from './schema';
import { SHAPE_GROUPS, labelledOutside, shapeOf } from './shapes';
import { BOARD_TEMPLATES, itemsOfTemplate } from './templates';

const table = (
    id: string,
    text: string,
    columns: string,
    x = 0,
    y = 0,
): Item => ({
    ...makeItem('table', x, y),
    id,
    text,
    rows: rowsFromText('table', columns),
});

describe('the shape library', () => {
    it('lists every shape under a group, with an outline to draw', () => {
        const shapes = SHAPE_GROUPS.flatMap((group) => group.shapes);

        expect(shapes.length).toBeGreaterThan(40);

        for (const shape of shapes) {
            expect(shape.path, shape.id).toMatch(/^M/);
            expect(isPath(shape.id), shape.id).toBe(true);
            expect(PATHS[shape.id], shape.id).toBe(shape.path);
        }
    });

    it('starts a shape as the library says, and names it by its label', () => {
        const heart = makeItem('heart', 0, 0);

        expect([heart.width, heart.height, heart.fill]).toEqual([
            180,
            170,
            '#fecdd3',
        ]);
        expect(nameOf({ ...heart, text: '' })).toBe('Heart');
        // The colours last chosen win over the library's
        expect(makeItem('heart', 0, 0, { fill: '#fff' }).fill).toBe('#fff');
    });

    it("writes a figure's name under it, and a folder's clear of its tab", () => {
        const actor = makeItem('actor', 0, 0);
        const folder = makeItem('package', 0, 0);

        expect(labelledOutside('actor')).toBe(true);
        expect(labelBox(actor).y).toBeGreaterThanOrEqual(actor.height);
        expect(labelledOutside('package')).toBe(false);
        expect(labelBox(folder).y).toBeCloseTo(folder.height * 0.16 + 12);
    });

    it('still draws a shape saved before it had every field', () => {
        const old = hydrate({ id: 'a', kind: 'cylinder', x: 1, y: 2 });

        expect([old.width, old.height, old.rows]).toEqual([
            180,
            200,
            undefined,
        ]);
    });
});

describe('a table or a class', () => {
    it('starts with rows of its own and as tall as they need', () => {
        const made = makeItem('table', 0, 0);
        const again = makeItem('table', 0, 0);

        expect(made.rows?.map((row) => [row.name, row.type, row.mark])).toEqual(
            [
                ['id', 'int', 'pk'],
                ['name', 'varchar', ''],
            ],
        );
        expect(made.height).toBe(rowsHeight(made));
        expect(made.rows?.[0].id).not.toBe(again.rows?.[0].id);
    });

    // The server lays rows out by the same sums (BoardRows); its test has
    // these numbers too
    it('lays its rows out to the numbers the server uses', () => {
        const three: Item = {
            ...makeItem('table', 100, 50),
            fontSize: 13,
            text: 'orders',
            rows: rowsFromText(
                'table',
                'id int pk\ncustomer_id int fk\nnote text?',
            ),
        };

        expect(rowsHeight(three)).toBe(109);
        expect(laidRows(three).map(({ y, height }) => [y, height])).toEqual([
            [34, 23],
            [57, 23],
            [80, 23],
        ]);
        expect(rowAnchor(three, three.rows![1], 'right')).toMatchObject({
            x: 100 + three.width,
            y: 50 + 68.5,
        });

        const klass: Item = {
            ...makeItem('class', 0, 0),
            fontSize: 13,
            text: 'Shape',
            rows: rowsFromText(
                'class',
                '+ x: number\n+ area(): number\n- y: number',
            ),
        };

        // Fields first, then the methods under a rule
        expect(laidRows(klass).map(({ row, y }) => [row.name, y])).toEqual([
            ['x', 34],
            ['y', 57],
            ['area()', 88],
        ]);
        expect(rowsHeight(klass)).toBe(117);
    });

    it('is written as text and read back, a row keeping its id by its name', () => {
        const before = [
            makeRow('table', { id: 'a', name: 'id', type: 'int', mark: 'pk' }),
            makeRow('table', {
                id: 'b',
                name: 'note',
                type: 'text',
                nullable: true,
            }),
        ];

        expect(rowsToText('table', before)).toBe('id int pk\nnote text?');

        const after = rowsFromText(
            'table',
            'note text?\nid bigint pk\nemail varchar(255) uk',
            before,
        );

        expect(
            after.map((row) => [
                row.id === 'b' || row.id === 'a',
                row.name,
                row.type,
                row.mark,
                row.nullable,
            ]),
        ).toEqual([
            [true, 'note', 'text', '', true],
            [true, 'id', 'bigint', 'pk', false],
            [false, 'email', 'varchar(255)', 'uk', false],
        ]);
        expect(after[0].id).toBe('b');

        const members = rowsFromText(
            'class',
            '+ name: string\n- total(): number\n# draw()',
        );

        expect(
            members.map((row) => [row.mark, row.name, row.type, row.method]),
        ).toEqual([
            ['+', 'name', 'string', false],
            ['-', 'total()', 'number', true],
            ['#', 'draw()', '', true],
        ]);
        expect(rowsToText('class', members)).toBe(
            '+ name: string\n- total(): number\n# draw()',
        );
    });

    it('is named in its band', () => {
        const made = makeItem('table', 0, 0);

        expect(labelBox(made)).toMatchObject({ y: 0, height: 32 });
        expect(
            labelBox({ ...makeItem('lane', 0, 0), variant: 'left' }),
        ).toMatchObject({
            x: 0,
            width: 34,
            height: 420,
        });
    });
});

describe('a connector', () => {
    it('has pieces to draw for every cap but none', () => {
        for (const { value } of HEAD_TYPES) {
            expect(headParts(value, 10).length > 0, value).toBe(
                value !== 'none',
            );
        }

        expect(headParts('zero_many', 10).map((part) => !!part.circle)).toEqual(
            [false, true],
        );
        expect(headParts('triangle', 10)[0]).toMatchObject({
            closed: true,
            fill: 'paper',
        });
    });

    it("is of a named kind when its caps and dash are that kind's", () => {
        expect(
            connectorTypeOf({
                startHead: 'none',
                endHead: 'triangle',
                lineStyle: 'dashed',
            })?.label,
        ).toBe('Implements');
        expect(
            connectorTypeOf({
                startHead: 'circle',
                endHead: 'bar',
                lineStyle: 'solid',
            }),
        ).toBeUndefined();
    });

    it('holds on to the row it names, on the edge facing the other table', () => {
        const orders = table(
            'orders',
            'orders',
            'id int pk\ncustomer_id int fk',
            400,
            0,
        );
        const customers = table(
            'customers',
            'customers',
            'id int pk\nname varchar',
            0,
            0,
        );
        const line: Item = {
            ...makeItem('arrow', 0, 0),
            from: {
                item: 'orders',
                side: null,
                pin: 'customer_id',
                x: 0,
                y: 0,
            },
            to: {
                item: 'customers',
                side: null,
                pin: customers.rows![0].id,
                x: 0,
                y: 0,
            },
        };
        const byId = new Map(
            [orders, customers].map((item) => [item.id, item]),
        );
        const points = connectorPoints(line, byId);

        // Out of the left of orders at its second row, into the right of customers at its first
        expect(points.slice(0, 2)).toEqual([400, laidRows(orders)[1].y + 11.5]);
        expect(points.slice(-2)).toEqual([
            customers.width,
            laidRows(customers)[0].y + 11.5,
        ]);

        // Moved to the far side, it comes out of the other edge
        const moved = new Map(byId).set('orders', { ...orders, x: -600 });

        expect(connectorPoints(line, moved)[0]).toBe(-600 + orders.width);
    });

    it('stays as far down a lifeline as it was dropped', () => {
        const who = { ...makeItem('lifeline', 100, 0), id: 'who' };
        const held = heldFor(
            { item: 'who', side: null, along: 0.25, x: 0, y: 0 },
            who,
            { x: 900, y: 0 },
        );

        expect(held).toMatchObject({
            x: 100 + who.width / 2,
            y: who.height * 0.25,
            side: 'right',
        });
        // Taller, the message is still a quarter of the way down
        expect(
            heldFor(
                { item: 'who', side: null, along: 0.25, x: 0, y: 0 },
                { ...who, height: 800 },
                { x: 0, y: 0 },
            ),
        ).toMatchObject({ y: 200, side: 'left' });
    });
});

describe('a template', () => {
    it('is made of shapes the canvas can draw, joined to one another', () => {
        expect(BOARD_TEMPLATES.length).toBeGreaterThanOrEqual(10);

        for (const template of BOARD_TEMPLATES) {
            const items = itemsOfTemplate(template);
            const ids = new Set(items.map((item) => item.id));

            expect(ids.size, template.id).toBe(items.length);

            for (const item of items) {
                expect(
                    isPath(item.kind) || !shapeOf(item.kind),
                    `${template.id}: ${item.kind}`,
                ).toBe(true);

                for (const end of [item.from, item.to]) {
                    if (end?.item) {
                        expect(
                            ids.has(end.item),
                            `${template.id}: ${end.item}`,
                        ).toBe(true);
                    }
                }
            }
        }
    });

    it('is made afresh each time, so it can go on a board twice', () => {
        const once = itemsOfTemplate(BOARD_TEMPLATES[0]).map((item) => item.id);
        const twice = itemsOfTemplate(BOARD_TEMPLATES[0]).map(
            (item) => item.id,
        );

        expect(once.filter((id) => twice.includes(id))).toEqual([]);
    });
});

describe('a schema drawn on a board', () => {
    const drawn = itemsOfTemplate(
        BOARD_TEMPLATES.find((each) => each.id === 'data-model')!,
    );

    it('is read back as a Mermaid erDiagram', () => {
        const text = schemaAsMermaid(drawn);

        expect(text).toContain('erDiagram');
        expect(text).toContain(
            'int customer_id FK "not null, references customers(id)"',
        );
        expect(text).toContain('text note "nullable"');
        expect(text).toContain('orders }o--|| customers : ""');
        expect(text).toContain('order_lines }|--|| orders : ""');
    });

    it('is written out as SQL, each foreign key pointing where its line does', () => {
        const sql = schemaAsSql(drawn, 'postgres');

        expect(sql).toContain('CREATE TABLE orders (');
        expect(sql).toContain(
            'FOREIGN KEY (customer_id) REFERENCES customers (id)',
        );
        expect(sql).toContain(
            'FOREIGN KEY (product_id) REFERENCES products (id)',
        );
        expect(sql).toMatch(/total NUMERIC NOT NULL/);
        expect(sql).toMatch(/note TEXT,?\n/);
        // A table is made before the one that points at it
        expect(sql.indexOf('CREATE TABLE customers')).toBeLessThan(
            sql.indexOf('CREATE TABLE orders'),
        );
    });

    it('names a table and a type so SQL will take them', () => {
        const text = schemaAsMermaid([
            table('t', 'Order lines!', 'unit price decimal(10, 2)\n2nd int'),
        ]);

        expect(text).toContain('Order_lines {');
        expect(text).toContain('decimal(10) unit');
    });
});
