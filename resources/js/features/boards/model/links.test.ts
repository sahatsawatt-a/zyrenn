/* oxlint-disable unicorn/no-thenable -- an Action's `if` has a `then`, as the
   contract (features/prototypes/interactions.ts) names it; nothing awaits one */
import { describe, expect, it } from 'vitest';
import type { Action } from '@/features/prototypes/interactions';
import type { Item } from './items';
import { makeItem } from './items';
import { itemsOfShape } from './myShapes';
import type { MyShape } from './myShapes';
import {
    cleanAction,
    cleanRepeat,
    rowCardOf,
    rowContents,
    tablesNamed,
    itemsNamed,
    linkOf,
    linkWords,
    relinked,
    screenOf,
    screensNamed,
    targetOf,
    triggersOf,
    unlinkedFrom,
    valueNames,
    whenOf,
} from './links';
import { BOARD_TEMPLATES, itemsOfTemplate } from './templates';

const screen = (
    id: string,
    text: string,
    fields: Partial<Item> = {},
): Item => ({
    ...makeItem('frame', 0, 0),
    id,
    text,
    ...fields,
});

const button = (
    id: string,
    link: unknown = null,
    fields: Partial<Item> = {},
): Item => ({
    ...makeItem('rect', 0, 0),
    id,
    link: link as Item['link'],
    ...fields,
});

describe('an action', () => {
    it('is read only as far as it is sound', () => {
        expect(cleanAction({ to: 'home' })).toEqual({ to: 'home' });
        expect(cleanAction({ back: true, transition: 'fade' })).toEqual({
            back: true,
            transition: 'fade',
        });
        expect(
            cleanAction({ to: 'menu', overlay: 'bottom', transition: 'warp' }),
        ).toEqual({ to: 'menu', overlay: 'bottom' });
        expect(cleanAction({ toggle: ['a', 'a', '', 4, 'b'] })).toEqual({
            toggle: ['a', 'b'],
        });
        expect(cleanAction({ set: 'paid', value: true })).toEqual({
            set: 'paid',
            value: true,
        });
        expect(cleanAction({ set: 'paid', value: 3 })).toBeNull();
        expect(cleanAction({ if: 'paid', then: { nothing: 1 } })).toBeNull();
        expect(cleanAction({ all: [{ close: true }, { junk: true }] })).toEqual(
            { close: true },
        );
        expect(cleanAction(null)).toBeNull();
        expect(cleanAction({ back: false })).toBeNull();
    });

    it('is not followed deeper than a person could mean', () => {
        let deep: Record<string, unknown> = { to: 'home' };

        for (let level = 0; level < 10; level++) {
            deep = { if: 'x', then: deep };
        }

        expect(cleanAction(deep)).toBeNull();
    });

    it('names the screens and items it would reach, each once', () => {
        const action: Action = {
            all: [
                { toggle: ['a', 'b'] },
                {
                    if: 'paid',
                    then: { to: 'thanks' },
                    else: { all: [{ show: ['b', 'c'] }, { to: 'pay' }] },
                },
            ],
        };

        expect(screensNamed(action)).toEqual(['thanks', 'pay']);
        expect(itemsNamed(action)).toEqual(['a', 'b', 'c']);
    });
});

describe("an item's actions", () => {
    const home = screen('home', 'Home');
    const list = screen('list', '');
    const byId = new Map([home, list].map((item) => [item.id, item]));

    it('are its tap, then its other triggers', () => {
        const item = button(
            'a',
            { to: 'home' },
            {
                when: {
                    'long-press': { toggle: ['b'] },
                    'swipe-left': { back: true },
                    junk: { to: 'x' },
                } as Item['when'],
            },
        );

        expect(triggersOf(item).map(({ trigger }) => trigger)).toEqual([
            'tap',
            'long-press',
            'swipe-left',
        ]);
        expect(Object.keys(whenOf(item))).toEqual(['long-press', 'swipe-left']);
        expect(linkWords(item, byId)).toBe(
            'Tap: Go to Home\nLong press: Flip 1 thing\nSwipe left: Back',
        );
    });

    it('find the screen a tap goes to', () => {
        expect(targetOf(button('a', { to: 'home' }), byId)).toBe(home);
        expect(
            targetOf(button('a', { if: 'x', then: { to: 'list' } }), byId),
        ).toBe(list);
        expect(targetOf(button('a', { to: 'gone' }), byId)).toBeNull();
        expect(linkOf(button('a', { to: '' }))).toBeNull();
    });

    it("keep a screen's own wait within its bounds", () => {
        expect(
            screenOf(
                screen('s', 'Splash', {
                    screen: { after: { seconds: 99, do: { to: 'home' } } },
                }),
            ),
        ).toEqual({ after: { seconds: 30, do: { to: 'home' } } });
        expect(
            screenOf(
                screen('s', 'Splash', {
                    screen: { after: { seconds: 2, do: {} as Action } },
                }),
            ),
        ).toEqual({});
    });
});

describe('the values a board names', () => {
    it('are its fields, and what its actions set or ask about, A to Z', () => {
        const items = [
            button('name', null, { field: 'name' }),
            button('pay', { all: [{ set: 'paid', value: true }, { to: 'x' }] }),
            button('check', null, {
                when: {
                    hover: {
                        if: 'agreed',
                        then: { set: 'name', value: 'Ada' },
                    },
                },
            }),
            screen('splash', 'Splash', {
                screen: {
                    after: { seconds: 1, do: { set: 'seen', value: true } },
                },
            }),
        ];

        expect(valueNames(items)).toEqual(['agreed', 'name', 'paid', 'seen']);
    });
});

describe('copying and deleting', () => {
    const copyOf = new Map([
        ['home', 'home-2'],
        ['tip', 'tip-2'],
    ]);

    it('points everything copied together at the copies, and keeps the rest', () => {
        const item = button(
            'a',
            { all: [{ toggle: ['tip', 'kept'] }, { to: 'home' }] },
            {
                when: {
                    'long-press': { to: 'list' },
                    hover: { show: ['tip'] },
                },
            },
        );
        const copied = relinked(item, copyOf);

        expect(copied.link).toEqual({
            all: [{ toggle: ['tip-2', 'kept'] }, { to: 'home-2' }],
        });
        expect(copied.when).toEqual({
            'long-press': { to: 'list' },
            hover: { show: ['tip-2'] },
        });

        // Nothing named a copy: the very same item back
        const untouched = button('b', { back: true });

        expect(relinked(untouched, copyOf)).toBe(untouched);
    });

    it("follows a screen's own action to the copy too", () => {
        const splash = screen('splash', 'Splash', {
            screen: { after: { seconds: 2, do: { to: 'home' } } },
        });

        expect(relinked(splash, copyOf).screen).toEqual({
            after: { seconds: 2, do: { to: 'home-2' } },
        });
    });

    it('takes off what named something deleted, and says how many lost something', () => {
        const items = [
            screen('home', 'Home'),
            screen('list', 'List'),
            button('tip'),
            button('a', { to: 'home' }),
            button('b', { all: [{ toggle: ['tip', 'kept'] }, { to: 'list' }] }),
            button('c', { back: true }),
            screen('splash', 'Splash', {
                screen: { after: { seconds: 2, do: { to: 'home' } } },
            }),
        ];
        const { items: left, cleared } = unlinkedFrom(
            items,
            new Set(['home', 'tip']),
        );
        const find = (id: string) => left.find((item) => item.id === id)!;

        expect(cleared).toBe(3);
        expect(find('a').link).toBeNull();
        expect(find('b').link).toEqual({
            all: [{ toggle: ['kept'] }, { to: 'list' }],
        });
        expect(find('c').link).toEqual({ back: true });
        expect(find('splash').screen).toEqual({ after: null });

        // Nothing went: the very same list back
        expect(unlinkedFrom(items, new Set(['nothing'])).items).toBe(items);
    });

    it("goes to its own screen's copy when a shape of one's own is put down", () => {
        const shape = {
            id: 1,
            name: 'Two screens',
            items: [screen('s1', 'One'), button('b1', { to: 's1' })],
        } as unknown as MyShape;
        const [copied, link] = itemsOfShape(shape);

        expect(copied.id).not.toBe('s1');
        expect(link.link).toEqual({ to: copied.id });
    });

    it("goes to its template screen's copy too", () => {
        const template = {
            ...BOARD_TEMPLATES[0],
            items: [
                { id: 'one', kind: 'frame' as const, text: 'One' },
                {
                    id: 'go',
                    kind: 'rect' as const,
                    link: { to: 'one' },
                },
            ],
        };
        const [frame, go] = itemsOfTemplate(template);

        expect(go.link).toEqual({ to: frame.id });
    });
});

describe('a table as the database', () => {
    it('saves a row, new or the current one, and deletes the current', () => {
        expect(
            cleanAction({
                save: 'tickets',
                row: 'new',
                values: { Title: '{title}', ' ': 'x', Status: 3 },
            }),
        ).toEqual({ save: 'tickets', values: { Title: '{title}' } });
        expect(cleanAction({ save: 'tickets', row: 'current' })).toEqual({
            save: 'tickets',
            row: 'current',
        });
        expect(cleanAction({ remove: 'tickets' })).toEqual({
            remove: 'tickets',
        });
        expect(cleanAction({ save: '' })).toBeNull();
    });

    it('repeats a card for each row, within its bounds', () => {
        expect(
            cleanRepeat({
                table: 'tickets',
                where: [{ column: 'Status', is: 'Open' }, { column: '' }],
                sort: { column: 'Title', desc: true },
                limit: 999,
                gap: -4,
                across: 'yes',
            }),
        ).toEqual({
            table: 'tickets',
            where: [{ column: 'Status', is: 'Open' }],
            sort: { column: 'Title', desc: true },
            limit: 200,
            gap: 0,
        });
        expect(cleanRepeat({ table: '' })).toBeNull();
        expect(cleanRepeat({ table: 't' })).toEqual({ table: 't' });
    });

    it('names every table a prototype uses, once', () => {
        const items = [
            button('row', null, { repeat: { table: 'tickets' } }),
            button('add', {
                all: [
                    { save: 'tickets' },
                    { if: 'x', then: { remove: 'people' } },
                ],
            }),
            screen('splash', 'Splash', {
                screen: { after: { seconds: 1, do: { save: 'log' } } },
            }),
        ];

        expect(tablesNamed(items)).toEqual(['tickets', 'people', 'log']);
    });

    it("holds what lies inside a row card's box on its screen", () => {
        const home = screen('home', 'Home', {
            x: 0,
            y: 0,
            width: 390,
            height: 844,
        });
        const elsewhere = screen('far', 'Far', {
            x: 600,
            y: 0,
            width: 390,
            height: 844,
        });
        const card: Item = {
            ...button('card', null, { repeat: { table: 'tickets' } }),
            x: 16,
            y: 100,
            width: 358,
            height: 80,
        };
        const title: Item = {
            ...button('title'),
            x: 32,
            y: 110,
            width: 200,
            height: 24,
        };
        const half: Item = {
            ...button('half'),
            x: 300,
            y: 150,
            width: 200,
            height: 24,
        };
        const away: Item = {
            ...button('away'),
            x: 616,
            y: 110,
            width: 100,
            height: 24,
        };
        const items = [home, elsewhere, card, title, half, away];

        expect(rowContents(card, items).map((item) => item.id)).toEqual([
            'title',
        ]);
        expect(rowCardOf(title, items)?.id).toBe('card');
        expect(rowCardOf(half, items)).toBeNull();
    });
});
