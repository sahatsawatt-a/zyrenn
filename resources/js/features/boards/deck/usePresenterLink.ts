import { useEventListener } from '@vueuse/core';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Deck, SlideDecor } from './deck';
import type { Item } from '../model/items';

/**
 * A slide as the presenter's window shows it: its title and notes, and what
 * is on it, to draw it small.
 */
export type PresenterSlide = {
    id: string;
    title: string;
    notes: string;
    items: Item[];
    /** What the deck writes on it, as numbered among the whole deck. */
    decor: SlideDecor | null;
};

/** Where the show is, as the presenter's window is told it. */
export type PresenterState = {
    presenting: boolean;
    index: number;
    count: number;
    current: PresenterSlide | null;
    next: PresenterSlide | null;
    deck: Deck | null;
    title: string;
    /** When the show began, in ms since 1970: for the clock. */
    startedAt: number;
    /** The clicks this slide's animations still wait for before the next slide. */
    clicksLeft: number;
    /** The clicks of this slide's animations played so far. */
    clicksDone: number;
};

type Message =
    | { type: 'state'; state: PresenterState }
    | { type: 'hello' }
    | { type: 'go'; index: number }
    | { type: 'next' }
    | { type: 'back' }
    | { type: 'stop' };

/** The one channel a board's show and its presenter's window talk on. */
export const presenterChannel = (board: string) => `zyrenn.present.${board}`;

// Sent as plain data: what Vue keeps reactive can't be posted
const plain = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T;

/**
 * The show's side: tells the presenter's window where the show is whenever
 * that changes (and when a window opens and asks), and does what it asks --
 * the next click or the one before (a slide's animations, then the slides),
 * a slide of its choosing, the end of the show. Nothing is sent
 * between browsers: the window is the presenter's own, beside the show.
 */
export function useShowLink(
    channel: () => string | null,
    state: () => PresenterState,
    act: {
        go: (index: number) => void;
        next: () => void;
        back: () => void;
        stop: () => void;
    },
) {
    let port: BroadcastChannel | null = null;

    const post = (message: Message) => port?.postMessage(plain(message));
    const sent = computed(() => JSON.stringify(state()));

    onMounted(() => {
        const name = channel();

        if (!name || typeof BroadcastChannel === 'undefined') {
            return;
        }

        port = new BroadcastChannel(name);
        port.onmessage = ({ data }: MessageEvent<Message>) => {
            if (data.type === 'hello') {
                post({ type: 'state', state: state() });
            } else if (data.type === 'go') {
                act.go(data.index);
            } else if (data.type === 'next') {
                act.next();
            } else if (data.type === 'back') {
                act.back();
            } else if (data.type === 'stop') {
                act.stop();
            }
        };
    });

    watch(sent, () => post({ type: 'state', state: state() }));

    onBeforeUnmount(() => {
        post({ type: 'state', state: { ...state(), presenting: false } });
        port?.close();
        port = null;
    });

    /** Opens the presenter's window, or brings it forward. */
    const open = (url: string) =>
        window.open(
            url,
            channel() ?? 'presenter',
            'popup,width=1100,height=720',
        );

    return { open };
}

/**
 * The presenter's window's side: where the show is, and asking it to move.
 */
export function usePresenterView(channel: string) {
    const state = ref<PresenterState | null>(null);
    let port: BroadcastChannel | null = null;

    const post = (message: Message) => port?.postMessage(message);

    onMounted(() => {
        if (typeof BroadcastChannel === 'undefined') {
            return;
        }

        port = new BroadcastChannel(channel);
        port.onmessage = ({ data }: MessageEvent<Message>) => {
            if (data.type === 'state') {
                state.value = data.state;
            }
        };
        post({ type: 'hello' });
    });

    onBeforeUnmount(() => port?.close());

    const go = (index: number) => post({ type: 'go', index });
    const next = () => post({ type: 'next' });
    const back = () => post({ type: 'back' });
    const stop = () => post({ type: 'stop' });

    // The keys a presenter's clicker sends, as in the show itself: a click
    // at a time, through each slide's animations
    useEventListener(window, 'keydown', (event: KeyboardEvent) => {
        if (['ArrowRight', 'ArrowDown', 'PageDown', ' '].includes(event.key)) {
            event.preventDefault();
            next();
        } else if (['ArrowLeft', 'ArrowUp', 'PageUp'].includes(event.key)) {
            event.preventDefault();
            back();
        }
    });

    return { state, go, next, back, stop };
}
