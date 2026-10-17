import { describe, expect, it } from 'vitest';
import type { Item } from '@/features/boards/model/items';
import { makeItem } from '@/features/boards/model/items';
import { partsOn, screenBeside, screenInHash, startOf } from './navigation';

const frame = (id: string, x: number): Item => ({
    ...makeItem('frame', x, 0),
    id,
    width: 390,
    height: 844,
});

const button = (id: string, x: number, y: number, link?: unknown): Item =>
    ({
        ...makeItem('rect', x, y),
        id,
        width: 195,
        height: 84,
        ...(link === undefined ? {} : { link }),
    }) as Item;

const screens = [
    { id: 'home', title: 'Home' },
    { id: 'menu', title: 'Menu' },
    { id: 'cart', title: 'Cart' },
];

describe('where Play starts', () => {
    it('opens on the screen asked for', () => {
        expect(startOf(screens, 'menu')).toBe('menu');
    });

    it('opens on the first when none is asked for, or one that is gone', () => {
        expect(startOf(screens, null)).toBe('home');
        expect(startOf(screens, 'deleted')).toBe('home');
        expect(startOf([], null)).toBeNull();
    });
});

describe('the screens beside one', () => {
    it('are the next and the one before, in order', () => {
        expect(screenBeside(screens, 'menu', 1)).toBe('cart');
        expect(screenBeside(screens, 'menu', -1)).toBe('home');
    });

    it('stop at either end', () => {
        expect(screenBeside(screens, 'cart', 1)).toBeNull();
        expect(screenBeside(screens, 'home', -1)).toBeNull();
    });
});

describe('the screen in the address', () => {
    it('is the one after the #, when the prototype has it', () => {
        expect(screenInHash('#menu', screens)).toBe('menu');
        expect(screenInHash('#nope', screens)).toBeNull();
        expect(screenInHash('', screens)).toBeNull();
    });
});

describe('the parts of a screen laid over', () => {
    const items = [
        frame('home', 0),
        frame('menu', 500),
        // Halfway across and a tenth down the home screen
        button('go', 195, 84.4, { to: 'menu' }),
        button('plain', 0, 400),
        {
            ...button('email', 20, 200),
            kind: 'text_field',
            field: 'email',
        } as Item,
        { ...button('wifi', 20, 300), kind: 'toggle' } as Item,
        { ...button('tabs', 0, 760, { to: 'menu' }), fixed: 'bottom' } as Item,
        { ...button('gone', 0, 600, { to: 'menu' }), hidden: true } as Item,
        button('elsewhere', 600, 100, { back: true }),
    ];
    const parts = partsOn(items, 'home');
    const part = (id: string) => parts.find((one) => one.item.id === id);

    it('are what answers a touch and the fields, on that screen alone', () => {
        expect(parts.map((one) => [one.item.id, one.kind])).toEqual([
            ['go', 'live'],
            ['email', 'field'],
            ['wifi', 'live'],
            ['tabs', 'live'],
        ]);
    });

    it('sit where they are on the screen', () => {
        expect(part('go')).toMatchObject({
            x: 195,
            y: 84.4,
            width: 195,
            height: 84,
            fixed: null,
        });
    });

    it('one fixed to the bottom is measured from the bottom', () => {
        expect(part('tabs')).toMatchObject({ fixed: 'bottom', y: 0 });
    });

    it('are none for a screen the board has not got', () => {
        expect(partsOn(items, 'cart')).toEqual([]);
    });
});
