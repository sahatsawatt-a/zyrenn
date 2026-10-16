import Konva from 'konva';
import type { InjectionKey, Ref } from 'vue';
import { computed, inject, ref } from 'vue';
import type { Item } from '../model/items';
import type { BuildStep, Timed } from './build';
import { groupsOf, hiddenAfter, lengthOf } from './build';

/**
 * The items hidden on the slide being shown, while presenting: each item's
 * group on the canvas reads it (BoardNode). Empty the rest of the time.
 */
export const BUILD_HIDDEN: InjectionKey<Ref<Set<string>>> =
    Symbol('build-hidden');

const nothingHidden = ref(new Set<string>());

/** The items hidden by the show just now, where an item is drawn. */
export const useBuildHidden = () => inject(BUILD_HIDDEN, nothingHidden);

type Player = {
    items: Ref<Item[]>;
    stage: () => Konva.Stage | undefined;
};

type Ease = (t: number, from: number, by: number, duration: number) => number;

/** How a step speeds up and slows down (Konva's easings, called as functions). */
const EASE: Record<'inOut' | 'out' | 'in' | 'linear', Ease> = {
    inOut: (t, from, by, duration) =>
        Konva.Easings.EaseInOut(t, from, by, duration),
    out: (t, from, by, duration) =>
        Konva.Easings.EaseOut(t, from, by, duration),
    in: (t, from, by, duration) => Konva.Easings.EaseIn(t, from, by, duration),
    linear: (t, from, by, duration) =>
        Konva.Easings.Linear(t, from, by, duration),
};

/** How far a fly comes from outside the slide, beyond its edge. */
const BEYOND = 40;

/**
 * A slide's animations played while presenting, as PowerPoint plays them:
 * what goes before the first click as the slide comes up, then a group of
 * steps for each click. A click while a group plays finishes it at once;
 * going back undoes a click's group at once. Each step moves the item's own
 * group on the canvas for as long as it plays, then gives it back as the
 * board has it -- hidden or shown -- so nothing of the show is ever saved.
 */
export function useBuild({ items, stage }: Player) {
    const frame = ref<Item | null>(null);
    // How many of the slide's groups have played (group 0: as it comes up)
    const played = ref(0);
    const playing = ref(false);
    // The slide's opening steps, waiting for the slide to land: a click
    // meanwhile plays them at once rather than the click after them
    const waiting = ref(false);
    let opening: ReturnType<typeof setTimeout> | undefined;

    const startOpening = () => {
        clearTimeout(opening);
        waiting.value = false;
        play();
    };
    let timers: ReturnType<typeof setTimeout>[] = [];
    let tweens: Konva.Tween[] = [];
    // The group under way, to finish at once when asked
    let current: Timed[] = [];

    const groups = computed(() => groupsOf(frame.value?.build));

    const hidden = computed(() =>
        frame.value
            ? hiddenAfter(frame.value.build, played.value)
            : new Set<string>(),
    );

    const itemOf = (id: string) =>
        items.value.find((item) => item.id === id) ?? null;

    const nodeOf = (id: string) =>
        stage()?.findOne<Konva.Group>(
            (node: Konva.Node) =>
                node.id() === id && node.getClassName() === 'Group',
        ) ?? null;

    /** The item's group as the board draws it: no part of an effect left on it. */
    const rest = (node: Konva.Group, item: Item) => {
        node.setAttrs({
            x: item.x,
            y: item.y,
            offsetX: 0,
            offsetY: 0,
            scaleX: 1,
            scaleY: 1,
            rotation: item.rotation ?? 0,
            opacity: item.opacity ?? 1,
            clipX: undefined,
            clipY: undefined,
            clipWidth: undefined,
            clipHeight: undefined,
        });
    };

    /** Turning and scaling about the item's middle, wherever it is turned to. */
    const aboutMiddle = (node: Konva.Group, item: Item) => {
        const turn = ((item.rotation ?? 0) * Math.PI) / 180;
        const half = { x: item.width / 2, y: item.height / 2 };

        node.setAttrs({
            offsetX: half.x,
            offsetY: half.y,
            x: item.x + half.x * Math.cos(turn) - half.y * Math.sin(turn),
            y: item.y + half.x * Math.sin(turn) + half.y * Math.cos(turn),
        });

        return { x: node.x(), y: node.y() };
    };

    /** Where an item flies from (or to): just past the slide's edge on a side. */
    const offSlide = (item: Item, side: BuildStep['from']) => {
        const slide = frame.value ?? item;

        return {
            left: { x: slide.x - item.width - BEYOND, y: item.y },
            right: { x: slide.x + slide.width + BEYOND, y: item.y },
            top: { x: item.x, y: slide.y - item.height - BEYOND },
            bottom: { x: item.x, y: slide.y + slide.height + BEYOND },
        }[side ?? 'bottom'];
    };

    const tween = (
        node: Konva.Group,
        ms: number,
        to: Konva.NodeConfig,
        ease: keyof typeof EASE = 'inOut',
    ) =>
        new Promise<void>((done) => {
            const one = new Konva.Tween({
                node,
                duration: Math.max(0.01, ms / 1000),
                easing: EASE[ease],
                ...to,
                onFinish: () => done(),
            });

            tweens.push(one);
            one.play();
        });

    /** One step, from its start: its item moved, then given back. */
    const run = async (step: BuildStep) => {
        const item = itemOf(step.item);
        const node = item ? nodeOf(item.id) : null;

        if (!item || !node) {
            return;
        }

        const opacity = item.opacity ?? 1;
        const ms = step.duration;

        if (step.kind === 'in') {
            rest(node, item);

            if (step.effect === 'fade') {
                node.opacity(0);
            } else if (step.effect === 'fly') {
                node.position(offSlide(item, step.from));
            } else if (step.effect === 'zoom') {
                aboutMiddle(node, item);
                node.setAttrs({ scaleX: 0.05, scaleY: 0.05, opacity: 0 });
            } else if (step.effect === 'wipe') {
                node.setAttrs(wipeFrom(item, step.from, 0));
            }

            node.visible(true);

            if (step.effect === 'fade') {
                await tween(node, ms, { opacity });
            } else if (step.effect === 'fly') {
                await tween(node, ms, { x: item.x, y: item.y }, 'out');
            } else if (step.effect === 'zoom') {
                await tween(node, ms, { scaleX: 1, scaleY: 1, opacity }, 'out');
            } else if (step.effect === 'wipe') {
                await tween(node, ms, wipeFrom(item, step.from, 1), 'linear');
            }

            rest(node, item);

            return;
        }

        if (step.kind === 'emphasis') {
            if (step.effect === 'pulse') {
                aboutMiddle(node, item);
                await tween(node, ms / 2, { scaleX: 1.12, scaleY: 1.12 });
                await tween(node, ms / 2, { scaleX: 1, scaleY: 1 });
            } else if (step.effect === 'spin') {
                aboutMiddle(node, item);
                await tween(node, ms, { rotation: (item.rotation ?? 0) + 360 });
            } else if (step.effect === 'blink') {
                await tween(node, ms / 4, { opacity: 0.15 });
                await tween(node, ms / 4, { opacity });
                await tween(node, ms / 4, { opacity: 0.15 });
                await tween(node, ms / 4, { opacity });
            }

            rest(node, item);

            return;
        }

        if (step.effect === 'fade') {
            await tween(node, ms, { opacity: 0 });
        } else if (step.effect === 'fly') {
            await tween(node, ms, offSlide(item, step.from), 'in');
        } else if (step.effect === 'zoom') {
            aboutMiddle(node, item);
            await tween(
                node,
                ms,
                { scaleX: 0.05, scaleY: 0.05, opacity: 0 },
                'in',
            );
        }

        node.visible(false);
        rest(node, item);
    };

    /** The part of a wiped item showing, `shown` of the way across (0 to 1). */
    const wipeFrom = (
        item: Item,
        side: BuildStep['from'],
        shown: number,
    ): Konva.NodeConfig => {
        // Room past the box for what is drawn beyond it: a shadow, a glow
        const room = 60;
        const across = side === 'left' || side === 'right';
        const width = item.width + room * 2;
        const height = item.height + room * 2;

        return across
            ? {
                  clipY: -room,
                  clipHeight: height,
                  clipX: side === 'left' ? -room : -room + width * (1 - shown),
                  clipWidth: width * shown,
              }
            : {
                  clipX: -room,
                  clipWidth: width,
                  clipY: side === 'top' ? -room : -room + height * (1 - shown),
                  clipHeight: height * shown,
              };
    };

    /** Every step of the group under way at its end, at once. */
    const finish = () => {
        timers.forEach(clearTimeout);
        timers = [];
        tweens.forEach((one) => one.destroy());
        tweens = [];

        for (const { step } of current) {
            const item = itemOf(step.item);
            const node = item ? nodeOf(item.id) : null;

            if (item && node) {
                rest(node, item);
            }
        }

        current = [];

        if (playing.value) {
            playing.value = false;
            played.value += 1;
        }

        // What each item's group shows, as the board has it after this group
        for (const item of items.value) {
            nodeOf(item.id)?.visible(
                !item.hidden && !hidden.value.has(item.id),
            );
        }
    };

    /** Play the slide's next group. */
    const play = () => {
        const group = groups.value[played.value];

        if (!group) {
            return;
        }

        current = group;
        playing.value = true;
        timers = group.map(({ step, at }) =>
            setTimeout(() => void run(step), at),
        );
        timers.push(setTimeout(finish, lengthOf(group) + 30));
    };

    /**
     * A slide coming up: as it first shows, its opening steps to play once
     * it has landed (`after` ms); or, come back to from the slide after, as
     * it ended.
     */
    const enter = (slide: Item | null, built = false, after = 0) => {
        clearTimeout(opening);
        waiting.value = false;
        finish();
        frame.value = slide;
        played.value = built ? groups.value.length : 0;
        finish();

        if (!built && slide) {
            if (groups.value[0]?.length) {
                waiting.value = true;
                opening = setTimeout(startOpening, after);
            } else {
                played.value = 1;
            }
        }
    };

    /** The next click: a group finished, or played; false when the slide has none left. */
    const next = () => {
        if (waiting.value) {
            startOpening();

            return true;
        }

        if (playing.value) {
            finish();

            return true;
        }

        if (frame.value && played.value < groups.value.length) {
            play();

            return true;
        }

        return false;
    };

    /** Back a click: its group undone; false when the slide is as it came up. */
    const back = () => {
        clearTimeout(opening);
        waiting.value = false;

        if (playing.value) {
            finish();
        }

        if (played.value > 1) {
            played.value -= 1;
            finish();

            return true;
        }

        return false;
    };

    /** The show over: every item given back as the board has it. */
    const stop = () => {
        clearTimeout(previewing);
        clearTimeout(opening);
        waiting.value = false;
        finish();
        frame.value = null;
        played.value = 0;
        finish();
    };

    // The preview's own clock: finishing a group clears the steps' timers
    let previewing: ReturnType<typeof setTimeout> | undefined;

    /** A slide's whole build played through, a click's worth at a time. */
    const preview = (slide: Item) => {
        clearTimeout(previewing);
        enter(slide);

        const onward = () => {
            if (!frame.value) {
                return;
            }

            if (
                !playing.value &&
                !waiting.value &&
                played.value < groups.value.length
            ) {
                play();
            }

            previewing =
                playing.value ||
                waiting.value ||
                played.value < groups.value.length
                    ? setTimeout(onward, playing.value ? 100 : 400)
                    : setTimeout(stop, 800);
        };

        previewing = setTimeout(onward, 50);
    };

    const clicksLeft = computed(() =>
        Math.max(0, groups.value.length - played.value),
    );

    return {
        hidden,
        played,
        playing,
        /** Playing, or about to: what a click now would only hurry along. */
        busy: computed(() => playing.value || waiting.value),
        clicksLeft,
        clicks: computed(() => Math.max(0, groups.value.length - 1)),
        enter,
        next,
        back,
        stop,
        /** Play a slide's whole build through, for its author: the editor's preview. */
        preview,
    };
}
