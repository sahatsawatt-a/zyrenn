// A board's frames made into a deck: one look for every slide (a theme of a
// few colours and two typefaces), a header and footer written on each, the
// slide's number, and slides laid out from a layout. One list
// (lib/deck.json) holds the themes and layouts, read here and by the server
// (App\Support\Board\Deck), so a slide is laid out the same wherever it is
// asked for.
import type { ComputedRef, InjectionKey } from 'vue';
import { computed, inject, provide } from 'vue';
import library from '@/features/boards/lib/deck.json';
import { boundsOf } from './geometry';
import type { FontFamily, Item } from './items';
import { makeItem } from './items';

export type DeckTheme = {
    id: string;
    label: string;
    background: string;
    ink: string;
    muted: string;
    accent: string;
    font: FontFamily;
    headingFont: FontFamily;
};

/**
 * What the board keeps (content.deck): a theme by its id, any of its colours
 * and typefaces said otherwise, and what is written on every slide. In a
 * header or footer {n} is the slide's number, {count} how many there are,
 * {section} the part of the deck it is in, {title} the board's title and
 * {slide} the slide's own.
 */
export type Deck = Partial<Omit<DeckTheme, 'id' | 'label'>> & {
    theme?: string;
    header?: string;
    footer?: string;
    numbers?: boolean;
};

type Role = {
    fontSize?: number;
    font?: 'body' | 'heading';
    color?: 'ink' | 'muted' | 'accent';
    caps?: boolean;
    letterSpacing?: number;
    rich?: boolean;
};

type Part = {
    slot: string;
    role: string;
    x: number;
    y: number;
    width: number;
    height: number;
};

export type SlideLayout = {
    id: string;
    label: string;
    about: string;
    /** No header, footer or number on it: a title slide. */
    bare?: boolean;
    /** The slot whose words name the part of the deck it begins. */
    section?: string;
    parts: Part[];
};

export const DECK_THEMES = library.themes as DeckTheme[];
export const SLIDE_LAYOUTS = library.layouts as SlideLayout[];
const ROLES = library.roles as Record<string, Role>;
const PLACEHOLDERS = library.placeholders as Record<string, string>;
const FRAME = library.frame;

/** The words a slot is filled with when nothing is said for it. */
export const placeholderOf = (slot: string): string => PLACEHOLDERS[slot] ?? '';

/** A deck's colours and typefaces: its theme's, with what it says over them. */
export const deckLook = (deck: Deck | null | undefined): DeckTheme => {
    const theme =
        DECK_THEMES.find((one) => one.id === deck?.theme) ?? DECK_THEMES[0];
    const look = { ...theme };

    for (const key of [
        'background',
        'ink',
        'muted',
        'accent',
        'font',
        'headingFont',
    ] as const) {
        const value = deck?.[key];

        if (value) {
            (look as Record<string, string>)[key] = value;
        }
    }

    return look;
};

/** Whether a deck has anything written on its slides. */
export const writesOnSlides = (deck: Deck | null | undefined): boolean =>
    !!(deck?.header?.trim() || deck?.footer?.trim() || deck?.numbers);

/** The frames in the order they are presented: the board's order. */
const slidesOf = (items: Item[]) =>
    items.filter((item) => item.kind === 'frame' && !item.hidden);

/** {name}s in a header or footer, filled in; an unknown one is left be. */
export const filledIn = (template: string, values: Record<string, string>) =>
    template.replace(/\{(\w+)\}/g, (whole, name: string) =>
        name in values ? values[name] : whole,
    );

/** What is written on one slide, where its frame is. */
export type SlideDecor = {
    header: string;
    footer: string;
    number: string;
    /** A unit of the header's type, 1 on a 960 x 540 frame. */
    scale: number;
    /** The colour it is written in: the deck's muted one. */
    color: string;
    font: FontFamily;
    margin: number;
};

/**
 * What each slide has written on it, by its frame's id: the header and
 * footer with their {names} filled in, and its number. A bare slide (a
 * title slide) has none, but is still counted.
 */
export const decorOf = (
    items: Item[],
    deck: Deck | null | undefined,
    title: string,
): Map<string, SlideDecor> => {
    const decor = new Map<string, SlideDecor>();

    if (!writesOnSlides(deck)) {
        return decor;
    }

    const look = deckLook(deck);
    const slides = slidesOf(items);
    let section = '';

    slides.forEach((frame, index) => {
        section = frame.section?.trim() || section;

        if (frame.bare) {
            return;
        }

        const values = {
            n: String(index + 1),
            count: String(slides.length),
            section,
            title,
            slide: frame.text?.trim() ?? '',
        };
        const scale = frame.height / FRAME.height;

        decor.set(frame.id, {
            header: filledIn(deck?.header ?? '', values).trim(),
            footer: filledIn(deck?.footer ?? '', values).trim(),
            number: deck?.numbers ? values.n : '',
            scale,
            color: look.muted,
            font: look.font,
            margin: FRAME.margin * (frame.width / FRAME.width),
        });
    });

    return decor;
};

/** Where the next slide goes: to the right of the last, level with it. */
export const nextSlideAt = (items: Item[]): { x: number; y: number } => {
    const slides = items.filter((item) => item.kind === 'frame');

    if (!slides.length) {
        return { x: 0, y: 0 };
    }

    const last = slides.reduce((right, frame) =>
        boundsOf(frame).x + boundsOf(frame).width >
        boundsOf(right).x + boundsOf(right).width
            ? frame
            : right,
    );
    const box = boundsOf(last);

    return { x: box.x + box.width + 80, y: box.y };
};

/** How a part's words are set: its role's size, typeface and colour. */
const setIn = (role: Role, look: DeckTheme): Partial<Item> => ({
    fontSize: role.fontSize ?? 20,
    fontFamily: role.font === 'heading' ? look.headingFont : look.font,
    color: look[role.color ?? 'ink'],
    ...(role.caps ? { caps: true } : {}),
    ...(role.letterSpacing ? { letterSpacing: role.letterSpacing } : {}),
    rich: !!role.rich,
});

/**
 * A new slide laid out: its frame, in the deck's background, and an item
 * for each of the layout's parts, filled with what `slots` says for it or
 * with words to type over. A picture's part is a placeholder to put one in.
 */
export const slideOf = (
    layout: SlideLayout,
    at: { x: number; y: number },
    deck: Deck | null | undefined,
    slots: Record<string, string> = {},
): Item[] => {
    const look = deckLook(deck);
    const words = (slot: string) => slots[slot] ?? placeholderOf(slot);
    const named = slots.title ?? (layout.parts.length ? '' : layout.label);
    const frame: Item = {
        ...makeItem('frame', at.x, at.y),
        width: FRAME.width,
        height: FRAME.height,
        fill: look.background,
        text: named || layout.label,
        ...(layout.bare ? { bare: true } : {}),
        ...(layout.section ? { section: words(layout.section) } : {}),
    };

    const parts = layout.parts.map((part): Item => {
        const box = {
            x: at.x + part.x,
            y: at.y + part.y,
            width: part.width,
            height: part.height,
        };

        if (part.role === 'picture') {
            return {
                ...makeItem('rect', box.x, box.y),
                ...box,
                fill: 'transparent',
                stroke: look.muted,
                lineStyle: 'dashed',
                lineWidth: 1.5,
                text: words(part.slot),
                color: look.muted,
            };
        }

        return {
            ...makeItem('text', box.x, box.y),
            ...box,
            text: words(part.slot),
            ...setIn(ROLES[part.role] ?? {}, look),
        };
    });

    return [frame, ...parts];
};

/** Whether an item's middle is on a frame. */
const onFrame = (item: Item, frame: Item) => {
    const box = boundsOf(item);
    const slide = boundsOf(frame);
    const x = box.x + box.width / 2;
    const y = box.y + box.height / 2;

    return (
        x >= slide.x &&
        x <= slide.x + slide.width &&
        y >= slide.y &&
        y <= slide.y + slide.height
    );
};

/**
 * Every slide in the deck's look, as changes by item id: each frame in its
 * background, and the words written straight on it in its typefaces and
 * colours -- a title (big words) in the heading typeface, a kicker (words
 * set in capitals) in the accent, the rest in the body typeface and ink.
 * Shapes, stickies and pictures keep their own colours: what was put on a
 * slide on purpose stays as it was.
 */
export const slidesRestyled = (
    items: Item[],
    deck: Deck | null | undefined,
): Map<string, Partial<Item>> => {
    const look = deckLook(deck);
    const frames = items.filter((item) => item.kind === 'frame');
    const changes = new Map<string, Partial<Item>>();

    for (const frame of frames) {
        changes.set(frame.id, {
            fill: look.background,
            gradient: '',
            gradientStops: undefined,
        });
    }

    for (const item of items) {
        if (item.kind !== 'text' || !frames.some((f) => onFrame(item, f))) {
            continue;
        }

        const heading = item.fontSize >= 30;

        changes.set(item.id, {
            fontFamily: heading ? look.headingFont : look.font,
            color: item.caps ? look.accent : look.ink,
            colorTo: undefined,
        });
    }

    return changes;
};

const KEY: InjectionKey<ComputedRef<Map<string, SlideDecor>>> =
    Symbol('slide-decor');

/**
 * Gives every frame drawn below what is written on it (decorOf), for the
 * board whose items and deck these are -- or, for a slide drawn away from
 * the rest of its deck (the presenter's window), what the show worked out.
 */
export function provideDeck(
    items: () => Item[],
    deck: () => Deck | null | undefined,
    title: () => string,
    given: () => Record<string, SlideDecor> | null = () => null,
) {
    const decor = computed(() => {
        const worked = given();

        return worked
            ? new Map(Object.entries(worked))
            : decorOf(items(), deck(), title());
    });

    provide(KEY, decor);

    return decor;
}

/** What the slides of the board this is drawn on have written on them. */
export const useSlideDecor = () =>
    inject(
        KEY,
        computed(() => new Map<string, SlideDecor>()),
    );
