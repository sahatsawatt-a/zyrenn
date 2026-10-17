import { useEventListener, useIntervalFn } from '@vueuse/core';
import { getCurrentScope, onScopeDispose, watch } from 'vue';
import type { Ref } from 'vue';
import type { PlayMiss, PlayStep } from '@/features/prototypes/feedback';
import { send } from '@/features/prototypes/feedback';
import prototypePlays from '@/routes/prototype-plays';
import boardPlays from '@/routes/prototypes/plays';

// What is kept of a play gets there at least this often
const EVERY = 15_000;

/**
 * Who saw what: this play of the prototype, kept as it goes -- each screen
 * gone to, and each tap that found nothing to follow, `at` seconds from the
 * start. Sent now and then, and once more as the page closes or is hidden
 * (with keepalive, so it gets there as the tab goes).
 *
 * `miss` takes the screen the tap was on and where, 0 to 1 of the whole
 * frame across and down.
 */
export function usePlayRecord(board: string, screen: Ref<string>) {
    const began = performance.now();
    const at = () => Math.round((performance.now() - began) / 100) / 10;

    let path: PlayStep[] = [];
    let misses: PlayMiss[] = [];
    let last = screen.value;
    let ended = false;

    // Started with the screen it opens on; what happens before the answer
    // waits for it
    const play = send<{ play: string }>(boardPlays.store.url(board), 'POST', {
        screen: screen.value || null,
        width: Math.round(window.innerWidth),
        height: Math.round(window.innerHeight),
    })
        .then((answer) => answer.play)
        .catch(() => null);

    const flush = async (end = false) => {
        if (ended || (!path.length && !misses.length && !end)) {
            return;
        }

        const sent = { path, misses, ...(end ? { ended: true } : {}) };
        path = [];
        misses = [];
        ended ||= end;

        const id = await play;

        if (id) {
            await send(prototypePlays.append.url(id), 'POST', sent, true).catch(
                () => {},
            );
        }
    };

    watch(screen, (now) => {
        if (now && now !== last) {
            last = now;
            path.push({ screen: now, at: at() });
        }
    });

    useIntervalFn(() => void flush(), EVERY);

    // Hidden, it may never come back: what there is goes now. Gone, it ends.
    useEventListener(document, 'visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            void flush();
        }
    });
    useEventListener(window, 'pagehide', () => void flush(true));

    if (getCurrentScope()) {
        onScopeDispose(() => void flush(true));
    }

    const clamp = (value: number) =>
        Math.round(Math.min(1, Math.max(0, value)) * 10_000) / 10_000;

    return {
        miss: (on: string, x: number, y: number) => {
            misses.push({ screen: on, x: clamp(x), y: clamp(y), at: at() });
        },
    };
}
