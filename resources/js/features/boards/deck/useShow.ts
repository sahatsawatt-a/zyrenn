import { useEventListener, useLocalStorage } from '@vueuse/core';
import type { ComputedRef, Ref } from 'vue';
import { watch } from 'vue';
import type { useBoard } from '../canvas/useBoard';
import type { useCamera } from '../canvas/useCamera';
import { boundsOf } from '../model/geometry';
import type { Item, Tool } from '../model/items';
import { groupKeys } from '../model/layers';
import type { Deck, SlideDecor, SlideLayout } from './deck';
import { nextSlideAt, slideOf } from './deck';
import type { PresenterState } from './usePresenterLink';
import { useShowLink } from './usePresenterLink';
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
};

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
                  items: board.items.value.filter(
                      (item) =>
                          item.id === frame.id ||
                          homes.get(item.id) === frame.id,
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
        };
    };

    const presenterLink = useShowLink(
        () => presenter()?.channel ?? null,
        presenterState,
        { go: (index) => showFrame(index), stop: () => stopPresenting() },
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

    return { ...presenting, notesShown, openPresenter, addSlide };
}
