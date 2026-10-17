import { describe, expect, it } from 'vitest';
import type { Item } from '@/features/boards/model/items';
import { makeItem } from '@/features/boards/model/items';
import type { Action } from '@/features/prototypes/interactions';
import {
    afterOf,
    fieldOf,
    filled,
    flip,
    freshState,
    isHidden,
    isLive,
    isOn,
    isSwitch,
    perform,
    playedItems,
    swipeOf,
    viewOf,
} from './engine';

const part = (id: string, kind: string, more: object = {}): Item =>
    ({ ...makeItem('rect', 0, 0), id, kind, on: true, ...more }) as Item;

const screens = new Set(['home', 'menu', 'sheet']);
const items = [
    part('home', 'frame'),
    part('banner', 'rect', { startsHidden: true }),
    part('wifi', 'toggle'),
    part('small', 'radio', { choice: 'size', on: true }),
    part('large', 'radio', { choice: 'size', on: false }),
    part('elsewhere', 'radio', { choice: 'size', on: true }),
    part('email', 'text_field', { field: 'email', text: 'Email' }),
    part('go', 'button', { link: { to: 'menu' } }),
];
// The first five on the home screen; the last radio on another
const screenOf = (item: Item) => (item.id === 'elsewhere' ? 'menu' : 'home');

const run = (action: Action, state = freshState()) =>
    perform(action, state, items, screens);

// An `if`, written as the board writes one
const when = (
    name: string,
    then: Action,
    more: { equals?: string | boolean; otherwise?: Action } = {},
): Action => ({
    if: name,
    // The contract names it so; nothing here is ever awaited
    // oxlint-disable-next-line unicorn/no-thenable
    then,
    ...(more.equals === undefined ? {} : { equals: more.equals }),
    ...(more.otherwise ? { else: more.otherwise } : {}),
});

describe('moves', () => {
    it('go to a screen, with how it looks', () => {
        expect(run({ to: 'menu', transition: 'push' }).moves).toEqual([
            { kind: 'to', screen: 'menu', transition: 'push' },
        ]);
    });

    it('slide unless told otherwise', () => {
        expect(run({ to: 'menu' }).moves[0]).toMatchObject({
            transition: 'slide',
        });
        expect(run({ back: true }).moves).toEqual([
            { kind: 'back', transition: 'slide' },
        ]);
    });

    it('open a screen over this one, and close it', () => {
        expect(run({ to: 'sheet', overlay: 'bottom' }).moves).toEqual([
            {
                kind: 'overlay',
                screen: 'sheet',
                place: 'bottom',
                transition: 'slide',
            },
        ]);
        expect(run({ close: true }).moves).toEqual([{ kind: 'close' }]);
    });

    it('go nowhere for a screen that is gone', () => {
        expect(run({ to: 'deleted' }).moves).toEqual([]);
    });
});

describe('showing and hiding', () => {
    it('an item that starts hidden is hidden until shown', () => {
        const [banner] = items.filter((item) => item.id === 'banner');

        expect(isHidden(banner, freshState())).toBe(true);
        expect(isHidden(banner, run({ show: ['banner'] }).state)).toBe(false);
    });

    it('toggles each one the other way', () => {
        const [banner, wifi] = items.filter((item) =>
            ['banner', 'wifi'].includes(item.id),
        );
        const { state } = run({ toggle: ['banner', 'wifi'] });

        expect(isHidden(banner, state)).toBe(false);
        expect(isHidden(wifi, state)).toBe(true);
    });

    it('never shows what the board keeps hidden', () => {
        const kept = part('kept', 'rect', { hidden: true });

        expect(isHidden(kept, run({ show: ['kept'] }).state)).toBe(true);
    });
});

describe('values and conditions', () => {
    it('set a value, then go by it', () => {
        const { state } = run({ set: 'signedIn', value: true });
        const check = when(
            'signedIn',
            { to: 'menu' },
            { otherwise: { to: 'home' } },
        );

        expect(run(check, state).moves[0]).toMatchObject({ screen: 'menu' });
        expect(run(check).moves[0]).toMatchObject({ screen: 'home' });
    });

    it('compare a value when told what to compare it with', () => {
        const { state } = run({ set: 'size', value: 'large' });

        expect(
            run(when('size', { to: 'menu' }, { equals: 'large' }), state).moves,
        ).toHaveLength(1);
        expect(
            run(when('size', { to: 'menu' }, { equals: 'small' }), state).moves,
        ).toHaveLength(0);
    });

    it('take nothing and false and empty words as no', () => {
        const check = when('x', { to: 'menu' });

        for (const value of [false, '']) {
            const { state } = run({ set: 'x', value });

            expect(run(check, state).moves).toEqual([]);
        }
    });

    it('do several in order, the move last', () => {
        const { state, moves } = run({
            all: [
                { set: 'ordered', value: true },
                { show: ['banner'] },
                { to: 'menu', transition: 'fade' },
            ],
        });

        expect(state.values.ordered).toBe(true);
        expect(state.hidden.banner).toBe(false);
        expect(moves).toEqual([
            { kind: 'to', screen: 'menu', transition: 'fade' },
        ]);
    });

    it('stop at an action that calls itself too deep', () => {
        const deep: Action = { all: [] };
        let at: Action = deep;

        for (let i = 0; i < 80; i++) {
            const next: Action = { all: [] };
            (at as { all: Action[] }).all.push(
                { set: `v${i}`, value: true },
                next,
            );
            at = next;
        }

        expect(() => run(deep)).not.toThrow();
    });
});

describe('switches', () => {
    it('are the kit parts that lead nowhere', () => {
        expect(isSwitch(part('a', 'toggle'))).toBe(true);
        expect(isSwitch(part('b', 'checkbox', { link: { to: 'menu' } }))).toBe(
            false,
        );
        expect(isSwitch(part('c', 'button'))).toBe(false);
        expect(isLive(part('d', 'button'))).toBe(false);
        expect(
            isLive(part('e', 'rect', { when: { hover: { close: true } } })),
        ).toBe(true);
    });

    it('flip on a tap', () => {
        const wifi = items.find((item) => item.id === 'wifi')!;
        const state = flip(wifi, freshState(), items, screenOf);

        expect(isOn(wifi, state)).toBe(false);
        expect(isOn(wifi, flip(wifi, state, items, screenOf))).toBe(true);
    });

    it('of one choice: on, and the rest of it on that screen off', () => {
        const large = items.find((item) => item.id === 'large')!;
        const state = flip(large, freshState(), items, screenOf);

        expect(state.on).toEqual({ small: false, large: true });
        // Tapped again, a radio stays on
        expect(flip(large, state, items, screenOf).on.large).toBe(true);
    });
});

describe('fields and words', () => {
    it('a kit text field with a name is typed into', () => {
        expect(fieldOf(items.find((item) => item.id === 'email')!)).toBe(
            'email',
        );
        expect(fieldOf(part('x', 'text_field'))).toBeNull();
        expect(fieldOf(part('y', 'rect', { field: 'name' }))).toBeNull();
    });

    it('{name} in words is what is kept under it; nothing when there is none', () => {
        expect(filled('Hi {name}!', { values: { name: 'Ada' } })).toBe(
            'Hi Ada!',
        );
        expect(filled('Hi {name}!', { values: {} })).toBe('Hi !');
        expect(filled('Hi {name}!', { values: { name: true } })).toBe('Hi !');
        expect(filled('No braces', { values: { name: 'Ada' } })).toBe(
            'No braces',
        );
    });

    it('the items are drawn as played: words filled, a field keeping its words for its hint', () => {
        const state = {
            ...freshState(),
            values: { email: 'a@b.co' },
            on: { wifi: false },
        };
        const drawn = new Map(
            playedItems(
                [...items, part('hello', 'text', { text: 'Sent to {email}' })],
                state,
            ).map((item) => [item.id, item]),
        );

        expect(drawn.get('hello')!.text).toBe('Sent to a@b.co');
        expect(drawn.get('email')!.text).toBe('Email');
        expect(drawn.get('wifi')!.on).toBe(false);
        expect(drawn.get('banner')!.hidden).toBe(true);
        // Unchanged items are the same items, so nothing is drawn again
        expect(drawn.get('go')).toBe(items.find((item) => item.id === 'go'));
    });
});

describe('screens', () => {
    it('a screen taller than its device scrolls', () => {
        expect(viewOf({ width: 390, height: 1600 }, 'phone')).toEqual({
            height: 844,
            scrolls: true,
        });
        expect(viewOf({ width: 390, height: 845 }, 'phone').scrolls).toBe(
            false,
        );
    });

    it('a screen moves on by itself after so many seconds, kept within reason', () => {
        const frame = part('splash', 'frame', {
            screen: { after: { seconds: 0.1, do: { to: 'home' } } },
        });

        expect(afterOf(frame)).toEqual({ ms: 500, action: { to: 'home' } });
        expect(afterOf(part('plain', 'frame'))).toBeNull();
    });
});

describe('swipes', () => {
    it('are quick and far enough, the way they went most', () => {
        expect(swipeOf(-120, 10, 200)).toBe('swipe-left');
        expect(swipeOf(5, 90, 200)).toBe('swipe-down');
        expect(swipeOf(-120, 10, 2000)).toBeNull();
        expect(swipeOf(10, 12, 100)).toBeNull();
    });
});
