import { describe, expect, it } from 'vitest';
import {
    SLIDE_LAYOUTS,
    deckLook,
    decorOf,
    filledIn,
    nextSlideAt,
    slideOf,
    slidesRestyled,
} from './deck';
import { makeItem } from '../model/items';
import type { Item } from '../model/items';

const frame = (x: number, extra: Partial<Item> = {}): Item => ({
    ...makeItem('frame', x, 0),
    ...extra,
});

const layout = (id: string) => SLIDE_LAYOUTS.find((one) => one.id === id)!;

describe("a deck's look", () => {
    it('is its theme, with what it says over it', () => {
        expect(deckLook(null).id).toBe('paper');
        expect(deckLook({ theme: 'night' }).background).toBe('#0f172a');
        expect(deckLook({ theme: 'night', accent: '#ff0000' })).toMatchObject({
            background: '#0f172a',
            accent: '#ff0000',
        });
        // A theme that is not one is the first
        expect(deckLook({ theme: 'neon' }).id).toBe('paper');
    });
});

describe('what every slide has written on it', () => {
    it('fills in the names, a slide at a time', () => {
        expect(
            filledIn('{title} · {n} of {count} · {nope}', {
                title: 'Q3',
                n: '2',
                count: '5',
            }),
        ).toBe('Q3 · 2 of 5 · {nope}');
    });

    it('is nothing when the deck writes nothing', () => {
        expect(decorOf([frame(0)], { theme: 'night' }, 'Q3').size).toBe(0);
    });

    it('counts every slide, carries a section on, and leaves a bare one be', () => {
        const items = [
            frame(0, { id: 'cover', bare: true, text: 'Cover' }),
            frame(1000, { id: 'a', section: 'Results', text: 'Up' }),
            frame(2000, { id: 'b', text: 'Down' }),
            frame(3000, { id: 'gone', hidden: true }),
        ];
        const decor = decorOf(
            items,
            { header: '{section}', footer: '{title}: {slide}', numbers: true },
            'Q3',
        );

        expect(decor.has('cover')).toBe(false);
        expect(decor.get('a')).toMatchObject({
            header: 'Results',
            footer: 'Q3: Up',
            number: '2',
        });
        expect(decor.get('b')).toMatchObject({
            header: 'Results',
            number: '3',
        });
        expect(decor.has('gone')).toBe(false);
    });

    it("grows with the slide, in the deck's quiet colour", () => {
        const [decor] = decorOf(
            [frame(0, { id: 'big', width: 1920, height: 1080 })],
            { theme: 'ivory', numbers: true },
            '',
        ).values();

        expect(decor).toMatchObject({
            scale: 2,
            margin: 112,
            color: '#78716c',
        });
    });
});

describe('a new slide', () => {
    it('goes to the right of the last, level with it', () => {
        expect(nextSlideAt([])).toEqual({ x: 0, y: 0 });
        expect(
            nextSlideAt([
                frame(0),
                { ...frame(1200), y: 300 },
                makeItem('rect', 5000, 0),
            ]),
        ).toEqual({ x: 1200 + 960 + 80, y: 300 });
    });

    it("is laid out in the deck's look, with words to type over", () => {
        const [slide, kicker, title, subtitle] = slideOf(
            layout('title'),
            { x: 100, y: 50 },
            { theme: 'ivory' },
            { title: 'Q3 in review' },
        );

        expect(slide).toMatchObject({
            kind: 'frame',
            x: 100,
            y: 50,
            width: 960,
            height: 540,
            fill: '#faf7f0',
            text: 'Q3 in review',
            bare: true,
        });
        expect(kicker).toMatchObject({
            kind: 'text',
            x: 156,
            caps: true,
            color: '#b45309',
            text: 'Kicker',
        });
        expect(title).toMatchObject({
            x: 156,
            y: 256,
            fontSize: 44,
            fontFamily: 'serif',
            color: '#1c1917',
            text: 'Q3 in review',
        });
        expect(subtitle).toMatchObject({ fontFamily: 'sans', rich: true });
    });

    it('begins a section with its title, and keeps room for a picture', () => {
        const [section] = slideOf(layout('section'), { x: 0, y: 0 }, null, {
            title: 'Results',
        });
        expect(section.section).toBe('Results');

        const picture = slideOf(
            layout('picture-right'),
            { x: 0, y: 0 },
            null,
        ).find((item) => item.kind === 'rect');
        expect(picture).toMatchObject({
            lineStyle: 'dashed',
            fill: 'transparent',
        });
    });
});

describe('every slide put in the look', () => {
    it('recolours frames and the words on them, and nothing else', () => {
        const items = [
            frame(0, { id: 'f', fill: '#ffffff', gradient: '#000000' }),
            { ...makeItem('text', 100, 100), id: 'title', fontSize: 40 },
            {
                ...makeItem('text', 100, 200),
                id: 'kick',
                fontSize: 14,
                caps: true,
            },
            { ...makeItem('text', 100, 300), id: 'body', fontSize: 20 },
            { ...makeItem('rect', 100, 300), id: 'shape', fill: '#fde68a' },
            { ...makeItem('text', 5000, 5000), id: 'off', fontSize: 20 },
        ];

        const changes = slidesRestyled(items, { theme: 'forest' });

        expect(changes.get('f')).toMatchObject({
            fill: '#052e2b',
            gradient: '',
        });
        expect(changes.get('title')).toMatchObject({
            fontFamily: 'serif',
            color: '#ecfdf5',
        });
        expect(changes.get('kick')).toMatchObject({ color: '#fbbf24' });
        expect(changes.get('body')).toMatchObject({ fontFamily: 'sans' });
        expect(changes.has('shape')).toBe(false);
        expect(changes.has('off')).toBe(false);
    });
});
