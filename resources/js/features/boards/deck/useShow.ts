import { useEventListener, useLocalStorage } from '@vueuse/core';
import type Konva from 'konva';
import type { ComputedRef, Ref } from 'vue';
import { ref, watch } from 'vue';
import type { useBoard } from '../canvas/useBoard';
import type { useCamera } from '../canvas/useCamera';
import { boundsOf } from '../model/geometry';
import type { Item, Tool } from '../model/items';
import { groupKeys } from '../model/layers';
import type { Deck, SlideDecor, SlideLayout } from './deck';
import { nextSlideAt, slideOf } from './deck';
import type { PresenterState } from './usePresenterLink';
import { useShowLink } from './usePresenterLink';
import { useBuild } from './useBuild';
import { usePresenting } from './usePresenting';

/** How much of the screen the speaker notes take while presenting. */
export const NOTES_ROOM = 0.28;

type Show = {
    board: ReturnType<typeof useBoard>;
    camera: ReturnType<typeof useCamera>;
    width: Ref<number>;
    height: Ref<number>;
    /** What fills the screen while presenting: the board, without the app. */
    screen: () => HTMLElement | null;
    /** Everything on the board in view, once the show is over. */
    overview: (options: { animate: boolean }) => void;
    /** The part of the canvas the panels leave uncovered. */
    uncovered: () => { left: number; right: number; bottom: number };
    tool: Ref<Tool>;
    title: () => string;
    deck: () => Deck | null;
    /** The presenter's window: where it opens, and the channel it hears on. */
    presenter: () => { url: string; channel: string } | null;
    /** What each slide has written on it, as the board works it out. */
    slideDecor: ComputedRef<Map<string, SlideDecor>>;
    /** The canvas, whose items a slide's animations move. */
    stage: () => Konva.Stage | undefined;
};

/** How long the camera takes to glide to a slide (useCamera), ms. */
const GLIDE = 450;

/** How long a fade through black takes each way, ms. */
export const FADE = 300;

/**
 * The board's frames shown as slides: presenting them a screen at a time,
 * the speaker notes under the slide (N) for a single screen, the presenter's
 * own window for a second one, and a new slide laid out after the last.
 */
export function useShow({
    board,
    camera,
    width,
    height,
    screen,
    overview,
    uncovered,
    tool,
    title,
    deck,
    presenter,
    slideDecor,
    stage,
}: Show) {
    // The speaker notes under the slide while presenting, for one screen: the
    // presenter's own window is for a second
    const notesShown = useLocalStorage('board.present-notes', false);

    const presenting = usePresenting({
        board,
        camera,
        width,
        height,
        screen,
        overview,
        below: () => (notesShown.value ? height.value * NOTES_ROOM : 0),
    });

    const { frameIndex, showFrame, stopPresenting } = presenting;

    watch(notesShown, presenting.refit);

    // The slide's animations (useBuild), and the black a fade goes through
    const build = useBuild({ items: board.items, stage });
    const curtain = ref(0);
    let fading: ReturnType<typeof setTimeout> | undefined;

    /**
     * To a slide, the way it asks to be come to -- gliding, through black,
     * or at once -- and its opening animations once it is there; or, come
     * back to from the slide after it, as it ended.
     */
    const goTo = (index: number, built = false) => {
        const frames = board.frames.value;
        const to = Math.min(frames.length - 1, Math.max(0, index));
        const slide = frames[to];

        if (!slide || (to === frameIndex.value && !built)) {
            return;
        }

        clearTimeout(fading);
        build.stop();

        const transition = slide.transition ?? 'glide';

        if (transition === 'fade') {
            curtain.value = 1;
            fading = setTimeout(() => {
                showFrame(to, false);
                curtain.value = 0;
                // Its opening steps once the black has lifted
                build.enter(slide, built, FADE);
            }, FADE);

            return;
        }

        showFrame(to, transition === 'glide');
        build.enter(slide, built, transition === 'glide' ? GLIDE : 0);
    };

    /** The next click: the slide's next animations, or the next slide. */
    const advance = () => {
        if (!build.next()) {
            goTo(frameIndex.value + 1);
        }
    };

    /** Back a click: the slide's last animations undone, or the slide before as it ended. */
    const retreat = () => {
        if (!build.back() && frameIndex.value > 0) {
            goTo(frameIndex.value - 1, true);
        }
    };

    // The show's first slide comes up with its own animations; when it ends,
    // every item is given back as the board has it
    watch(presenting.presenting, (on) => {
        clearTimeout(fading);
        curtain.value = 0;

        if (on) {
            build.enter(presenting.currentFrame.value, false, GLIDE);
        } else {
            build.stop();
        }
    });

    useEventListener(window, 'keydown', (event: KeyboardEvent) => {
        const typing = (event.target as HTMLElement | null)?.closest?.(
            'input, textarea, [contenteditable="true"]',
        );

        if (
            presenting.presenting.value &&
            !typing &&
            event.key.toLowerCase() === 'n'
        ) {
            notesShown.value = !notesShown.value;
        }
    });

    /** A slide as the presenter's window draws it: its frame and what is on it. */
    const presenterSlide = (
        frame: Item | undefined,
        homes: Map<string, string>,
    ) =>
        frame
            ? {
                  id: frame.id,
                  title: frame.text,
                  notes: frame.notes ?? '',
                  // As the slide stands: what its animations have yet to
                  // bring in, or have taken off, left out
                  items: board.items.value.filter(
                      (item) =>
                          (item.id === frame.id ||
                              homes.get(item.id) === frame.id) &&
                          !(
                              frame.id === presenting.currentFrame.value?.id &&
                              build.hidden.value.has(item.id)
                          ),
                  ),
                  decor: slideDecor.value.get(frame.id) ?? null,
              }
            : null;

    const presenterState = (): PresenterState => {
        if (!presenting.presenting.value) {
            return {
                presenting: false,
                index: 0,
                count: 0,
                current: null,
                next: null,
                deck: null,
                title: title(),
                startedAt: 0,
                clicksLeft: 0,
                clicksDone: 0,
            };
        }

        const frames = board.frames.value;
        const homes = groupKeys(board.items.value);

        return {
            presenting: true,
            index: frameIndex.value,
            count: frames.length,
            current: presenterSlide(frames[frameIndex.value], homes),
            next: presenterSlide(frames[frameIndex.value + 1], homes),
            deck: deck(),
            title: title(),
            startedAt: presenting.startedAt.value,
            clicksLeft: build.clicksLeft.value,
            clicksDone: Math.max(0, build.played.value - 1),
        };
    };

    const presenterLink = useShowLink(
        () => presenter()?.channel ?? null,
        presenterState,
        {
            go: (index) => goTo(index),
            next: advance,
            back: retreat,
            stop: () => stopPresenting(),
        },
    );

    const openPresenter = () => {
        const window = presenter();

        if (window) {
            presenting.keepShowing();
            presenterLink.open(window.url);
        }
    };

    /** A new slide after the last one, laid out, in the deck's look. */
    const addSlide = (layout: SlideLayout) => {
        const slide = slideOf(layout, nextSlideAt(board.items.value), deck());

        board.place(slide);
        board.select([slide[0].id]);
        tool.value = 'select';
        camera.reveal(boundsOf(slide[0]), { inset: uncovered() });
    };

    return {
        ...presenting,
        notesShown,
        openPresenter,
        addSlide,
        build,
        curtain,
        goTo,
        advance,
        retreat,
    };
}
