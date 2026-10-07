// The colours a select's choices come in. A choice keeps the tone's name, and
// the classes are worked out here, so a choice reads well in light and dark
// alike. The first tables kept whole class strings -- "bg-amber-950/60 ..." --
// and those are still understood, by the colour named in them.
export const TONES = [
    'amber',
    'sky',
    'purple',
    'emerald',
    'fuchsia',
    'indigo',
    'rose',
    'cyan',
] as const;

export type Tone = (typeof TONES)[number] | 'slate';

// Written out in full, so Tailwind finds every class
const CHIP: Record<Tone, string> = {
    amber: 'bg-amber-100 text-amber-900 dark:bg-amber-400/15 dark:text-amber-200',
    sky: 'bg-sky-100 text-sky-900 dark:bg-sky-400/15 dark:text-sky-200',
    purple: 'bg-purple-100 text-purple-900 dark:bg-purple-400/15 dark:text-purple-200',
    emerald:
        'bg-emerald-100 text-emerald-900 dark:bg-emerald-400/15 dark:text-emerald-200',
    fuchsia:
        'bg-fuchsia-100 text-fuchsia-900 dark:bg-fuchsia-400/15 dark:text-fuchsia-200',
    indigo: 'bg-indigo-100 text-indigo-900 dark:bg-indigo-400/15 dark:text-indigo-200',
    rose: 'bg-rose-100 text-rose-900 dark:bg-rose-400/15 dark:text-rose-200',
    cyan: 'bg-cyan-100 text-cyan-900 dark:bg-cyan-400/15 dark:text-cyan-200',
    slate: 'bg-muted text-foreground',
};

const SWATCH: Record<Tone, string> = {
    amber: 'bg-amber-400',
    sky: 'bg-sky-400',
    purple: 'bg-purple-400',
    emerald: 'bg-emerald-400',
    fuchsia: 'bg-fuchsia-400',
    indigo: 'bg-indigo-400',
    rose: 'bg-rose-400',
    cyan: 'bg-cyan-400',
    slate: 'bg-slate-400',
};

/** The tone a stored colour means, whether a name or an old class string. */
export function toneOf(color?: string | null): Tone {
    const name = color?.match(/^[a-z]+$/)
        ? color
        : color?.match(/bg-([a-z]+)-/)?.[1];

    return name && name in CHIP ? (name as Tone) : 'slate';
}

/** The classes for a choice's chip. */
export const chipClass = (color?: string | null): string => CHIP[toneOf(color)];

/** The classes for a round sample of the tone, as the colour picker shows it. */
export const swatchClass = (tone: Tone): string => SWATCH[tone];

/** The tone after this one, so new choices don't all come out the same. */
export const nextTone = (tone: Tone): Tone =>
    TONES[(TONES.indexOf(tone as (typeof TONES)[number]) + 1) % TONES.length];

/** Any tone, for a choice made on the spot from inside a cell. */
export const randomTone = (): Tone =>
    TONES[Math.floor(Math.random() * TONES.length)];
