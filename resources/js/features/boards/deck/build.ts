import animations from '../lib/animations.json';

// A slide's animations, as PowerPoint's Animation Pane has them: one list on
// the frame, in the order they play. Each step names an item on the slide,
// what it does (comes in, draws the eye, goes out), how it starts -- on a
// click, with the step before, or after it -- and for how long. Items with
// an entrance are hidden until it plays. The names and limits are shared
// with the server (lib/animations.json, App\Support\Board\Animations).

export type BuildKind = 'in' | 'emphasis' | 'out';
export type BuildStart = 'click' | 'with' | 'after';
export type BuildFrom = 'left' | 'right' | 'top' | 'bottom';

export type BuildStep = {
    item: string;
    kind: BuildKind;
    effect: string;
    start: BuildStart;
    /** How long it plays, in ms. */
    duration: number;
    /** How long it waits once its turn comes, in ms. */
    delay: number;
    /** Where a fly or a wipe comes from (or goes to). */
    from?: BuildFrom;
};

/** How the show moves on to a slide: the camera gliding, a fade, or a cut. */
export type Transition = 'glide' | 'fade' | 'cut';

export const EFFECTS = animations.effects as Record<
    BuildKind,
    { id: string; label: string; from?: boolean }[]
>;
export const KINDS = animations.kinds as { id: BuildKind; label: string }[];
export const STARTS = animations.starts as { id: BuildStart; label: string }[];
export const TRANSITIONS = animations.transitions as {
    id: Transition;
    label: string;
}[];
export const SPEEDS = animations.speeds as { ms: number; label: string }[];
export const FROMS = animations.froms as BuildFrom[];
export const DEFAULT_DURATION = animations.duration.default;

/** Whether an effect of a kind comes from (or goes to) a side. */
export const hasFrom = (kind: BuildKind, effect: string) =>
    !!EFFECTS[kind]?.find((one) => one.id === effect)?.from;

/** A new step for an item: the kind's first effect, on a click. */
export const stepFor = (item: string, kind: BuildKind): BuildStep => {
    const effect = EFFECTS[kind][kind === 'in' ? 1 : 0].id;

    return {
        item,
        kind,
        effect,
        start: 'click',
        duration: DEFAULT_DURATION,
        delay: 0,
        ...(hasFrom(kind, effect) ? { from: 'bottom' as const } : {}),
    };
};

/** A step as it plays: its place in the slide's list, and when it starts. */
export type Timed = { step: BuildStep; index: number; at: number };

/**
 * The slide's steps in the groups the clicks play: each click starts a
 * group, and steps "with" or "after" one go with the click before them.
 * Steps before the first click play as the slide comes up: group 0, which
 * is empty when the list starts with a click. Within a group, each step's
 * start is worked out as PowerPoint does: "with" as the step before it
 * begins, "after" once that step ends.
 */
export function groupsOf(build: BuildStep[] | undefined): Timed[][] {
    const groups: Timed[][] = [[]];
    // When the step before began and ended, in its group
    let begun = 0;
    let ended = 0;

    for (const [index, step] of (build ?? []).entries()) {
        if (step.start === 'click') {
            groups.push([]);
            begun = 0;
            ended = 0;
        }

        const from = step.start === 'after' ? ended : begun;
        const at = from + step.delay;

        groups[groups.length - 1].push({ step, index, at });
        begun = at;
        ended = at + step.duration;
    }

    return groups;
}

/** How many clicks the slide waits for before the next slide comes. */
export const clicksOf = (build: BuildStep[] | undefined) =>
    groupsOf(build).length - 1;

/** How long a group takes to play, ms. */
export const lengthOf = (group: Timed[]) =>
    Math.max(0, ...group.map(({ step, at }) => at + step.duration));

/**
 * The items hidden once `played` groups have played (0: as the slide first
 * shows, before even group 0's steps): an item is hidden before its first
 * entrance, and after an exit until it comes in again.
 */
export function hiddenAfter(
    build: BuildStep[] | undefined,
    played: number,
): Set<string> {
    const hidden = new Set<string>();
    const seen = new Set<string>();

    for (const { step } of groupsOf(build).flat()) {
        if (!seen.has(step.item)) {
            seen.add(step.item);

            if (step.kind === 'in') {
                hidden.add(step.item);
            }
        }
    }

    for (const group of groupsOf(build).slice(0, played)) {
        for (const { step } of group) {
            if (step.kind === 'in') {
                hidden.delete(step.item);
            } else if (step.kind === 'out') {
                hidden.add(step.item);
            }
        }
    }

    return hidden;
}

/** The steps left when items are taken off the board: those of items still there. */
export const withItems = (
    build: BuildStep[] | undefined,
    ids: Set<string>,
): BuildStep[] => (build ?? []).filter((step) => ids.has(step.item));
