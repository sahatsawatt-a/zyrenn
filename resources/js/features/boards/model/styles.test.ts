import { describe, expect, it } from 'vitest';
import { anchorAt, isFlatSide } from './geometry';
import { makeItem } from './items';
import type { Item } from './items';
import { labelBox } from '../drawing/labels';
import { designOf, designsOf, labelledOutside, lookOf } from './shapes';
import {
    COLOUR_SETS,
    COLOURS,
    STYLES,
    familyOf,
    iconPath,
    restyled,
    styled,
} from './styles';

const at = (kind: Item['kind'], extra: Partial<Item> = {}): Item => ({
    ...makeItem(kind, 0, 0),
    ...extra,
});

describe('a ready-made style', () => {
    it('puts a colour on a shape as fill, line and words', () => {
        expect(styled({ kind: 'rect' }, 'soft', 'indigo')).toEqual({
            fill: '#e0e7ff',
            stroke: '#6366f1',
            color: '#312e81',
            lineWidth: 1.5,
        });
        // Solid takes the shade white words read on, and a darker line so
        // what is drawn inside a shape still shows
        expect(styled({ kind: 'rect' }, 'solid', 'amber')).toMatchObject({
            fill: '#b45309',
            stroke: '#92400e',
            color: '#ffffff',
        });
        expect(styled({ kind: 'rect' }, 'outline', 'emerald')).toMatchObject({
            fill: '#ffffff',
            stroke: '#059669',
        });
    });

    it('colours only the line of a connector and only the words of a text', () => {
        expect(styled({ kind: 'arrow' }, 'solid', 'rose')).toEqual({
            stroke: '#9f1239',
        });
        expect(styled({ kind: 'text' }, 'bold', 'sky')).toEqual({
            color: '#0369a1',
        });
        // A picture, a formula or a circuit's part is left as it is
        expect(styled({ kind: 'image' }, 'solid', 'rose')).toEqual({});
        expect(styled({ kind: 'symbol' }, 'solid', 'rose')).toEqual({});
    });

    it('has white words only on shades they can be read on', () => {
        const lightness = (hex: string) => {
            const value = parseInt(hex.slice(1), 16);
            const channel = (shift: number) => {
                const c = ((value >> shift) & 255) / 255;

                return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
            };

            return (
                0.2126 * channel(16) + 0.7152 * channel(8) + 0.0722 * channel(0)
            );
        };

        for (const [name, family] of Object.entries(COLOURS)) {
            const contrast =
                1.05 / (lightness(family.shades[family.solid]) + 0.05);

            expect(contrast, name).toBeGreaterThanOrEqual(4.5);
        }
    });

    it('offers sets made of colours there are, each ending in a grey', () => {
        for (const set of COLOUR_SETS) {
            expect(set.colours.every((colour) => colour in COLOURS)).toBe(true);
            expect(['slate', 'stone']).toContain(
                set.colours[set.colours.length - 1],
            );
        }

        expect(STYLES.map((style) => style.id)).toEqual([
            'soft',
            'solid',
            'outline',
            'bold',
            'light',
        ]);
    });
});

describe('a whole board restyled', () => {
    const board = () => [
        at('rect', { id: 'a', fill: '#bbf7d0', stroke: '#16a34a' }),
        at('rect', { id: 'b', fill: '#ffffff', stroke: '#0f172a' }),
        at('ellipse', { id: 'c', fill: '#fecaca', stroke: '#ef4444' }),
        at('rect', { id: 'd', fill: '#bbf7d0', stroke: '#16a34a' }),
        at('arrow', { id: 'e', stroke: '#ef4444' }),
        at('frame', { id: 'f', fill: '#ffffff' }),
        at('image', { id: 'g' }),
    ];

    it('knows a colour by its family, and a grey as no colour at all', () => {
        expect(familyOf('#bbf7d0')).toBe('green');
        expect(familyOf('#3b82f6')).toBe('blue');
        expect(familyOf('#ffffff')).toBeNull();
        expect(familyOf('#475569')).toBeNull();
        expect(familyOf('transparent')).toBeNull();
    });

    it('keeps colours that went together going together', () => {
        const changes = restyled(board(), 'classic', 'soft');

        // The two greens become one colour of the set, the red another
        expect(changes.get('a')).toEqual(changes.get('d'));
        expect(changes.get('a')?.fill).not.toBe(changes.get('c')?.fill);
        // A blank shape and a connector take the set's grey
        expect(changes.get('b')?.fill).toBe(COLOURS.slate.shades['100']);
        expect(changes.get('e')).toEqual({
            stroke: COLOURS.slate.shades['500'],
        });
        // A frame is the slide everything is on, a picture is a picture
        expect(changes.has('f')).toBe(false);
        expect(changes.has('g')).toBe(false);
    });

    it('changes nothing more when done again', () => {
        const items = board();
        const apply = (style: string) =>
            restyled(items, 'ocean', style).forEach((change, id) =>
                Object.assign(
                    items.find((item) => item.id === id)!,
                    change,
                ),
            );

        apply('soft');
        const once = JSON.stringify(items);

        apply('soft');
        expect(JSON.stringify(items)).toBe(once);

        // ...and a colour the set has keeps itself in another style
        const teal = items.find((item) => item.id === 'a')!.fill;

        apply('solid');
        expect(familyOf(items.find((item) => item.id === 'a')!.fill)).toBe(
            familyOf(teal),
        );
    });
});

describe('a design', () => {
    it('gives a database ways of its own and the icon ones', () => {
        expect(designsOf('cylinder').map((design) => design.id)).toEqual([
            'classic',
            'stacked',
            'shaded',
            'card',
            'badge',
            'icon',
        ]);
        expect(designsOf('rect')).toEqual([]);
    });

    it('has an icon to draw for every design that names one', () => {
        for (const kind of ['cylinder', 'server', 'user', 'globe', 'queue']) {
            for (const design of designsOf(kind)) {
                if (design.icon) {
                    expect(
                        iconPath(design.icon),
                        `${kind} ${design.id}`,
                    ).toMatch(/^M/);
                }
            }
        }
    });

    it('is drawn and labelled as it says, and connectors meet its edge', () => {
        const card = at('cylinder', {
            variant: 'card',
            width: 200,
            height: 100,
        });

        expect(designOf(card)?.frame).toBe('card');
        expect(lookOf(at('cylinder')).frame).toBeUndefined();
        expect(lookOf(card).icon).toBe('database');

        // A card's words are under its icon, inside it
        expect(labelledOutside('cylinder', 'card')).toBe(false);
        expect(labelBox(card).y).toBeGreaterThan(50);
        // A badge, like the figures it stands for, is named under it
        expect(labelledOutside('server', 'badge')).toBe(true);
        expect(isFlatSide(at('server', { variant: 'badge' }), 'top')).toBe(
            false,
        );

        // A card is a box: a connector stops on its edge, anywhere along it
        expect(anchorAt(card, 'top').y).toBe(0);
        expect(isFlatSide(card, 'top')).toBe(true);
        expect(isFlatSide(at('cylinder'), 'top')).toBe(false);
    });

    it('draws a stack of discs and a shaded lid on a database', () => {
        const stacked = lookOf(at('cylinder', { variant: 'stacked' }));
        const shaded = lookOf(at('cylinder', { variant: 'shaded' }));

        expect(stacked.path?.match(/a50,14/g)?.length).toBeGreaterThan(3);
        expect(shaded.shade).toMatch(/^M0,14/);
        // An unknown design is the usual one
        expect(
            lookOf(at('cylinder', { variant: 'glossy' })).path,
        ).toBeUndefined();
    });
});
