// What people make of a prototype: comments pinned to spots on its screens,
// and each time it was played. Read and written over JSON
// (Board/PrototypeCommentController, PrototypePlayController); a comment
// made or changed anywhere is heard live on the board's channel, so Play and
// the editor show the same pins.
import { echo, echoIsConfigured } from '@laravel/echo-vue';
import { computed, getCurrentScope, onScopeDispose, reactive, ref } from 'vue';
import type { Ref } from 'vue';
import { socketHeaders } from '@/lib/live';
import { xsrfToken } from '@/lib/utils';
import prototypeComments from '@/routes/prototype-comments';
import boardComments from '@/routes/prototypes/comments';
import boardPlays from '@/routes/prototypes/plays';

export type PrototypeComment = {
    ref_id: string;
    // The screen, by its frame's id, and where on it: 0 to 1 across and down
    screen: string;
    x: number;
    y: number;
    body: string;
    by: { id: number; name: string } | null;
    resolved: boolean;
    ticket: { number: number; status: string } | null;
    created_at: string | null;
};

/** A screen gone to, `at` seconds from the start of the play. */
export type PlayStep = { screen: string; at: number };

/** A tap that found nothing to follow, where on its screen (0 to 1). */
export type PlayMiss = PlayStep & { x: number; y: number };

export type PrototypePlay = {
    ref_id: string;
    by: { id: number; name: string } | null;
    started_at: string;
    seconds: number;
    path: PlayStep[];
    misses: PlayMiss[];
};

/**
 * What the board shows over a prototype's screens: which comment pins (the
 * open ones, all, or none), and -- while the Plays panel is open -- the
 * plays read, and the one picked, whose missed taps show (all of them when
 * none is picked). One board page at a time, so one of these.
 */
export const onTheBoard = reactive<{
    pins: 'open' | 'all' | 'none';
    plays: PrototypePlay[] | null;
    play: string | null;
}>({ pins: 'open', plays: null, play: null });

// ------------------------------------------------------------------ Talking

export async function send<T>(
    url: string,
    method: 'GET' | 'POST' | 'PATCH',
    body?: unknown,
    keepalive = false,
): Promise<T> {
    const init: RequestInit = {
        method,
        credentials: 'same-origin',
        // Sent as the page closes, it still gets there
        keepalive,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
            // What this page did isn't told back to it
            ...socketHeaders(),
        },
    };

    if (body !== undefined) {
        init.body = JSON.stringify(body);
    }

    const response = await fetch(url, init);

    if (!response.ok) {
        const said = (await response.json().catch(() => null)) as {
            message?: string;
        } | null;

        throw new Error(said?.message || `The server said ${response.status}`);
    }

    return response.status === 204
        ? (undefined as T)
        : ((await response.json()) as T);
}

export const playsOf = async (board: string): Promise<PrototypePlay[]> =>
    (await send<{ plays: PrototypePlay[] }>(boardPlays.index.url(board), 'GET'))
        .plays;

// ---------------------------------------------------------------- Comments

type Kept = {
    comments: Ref<PrototypeComment[]>;
    loaded: Ref<boolean>;
    users: number;
    stop: () => void;
};

// One list a prototype, however many parts of the page show it
const kept = new Map<string, Kept>();

/** In the list, in place of the one with its id; or added at its end. */
const put = (list: Ref<PrototypeComment[]>, comment: PrototypeComment) => {
    const at = list.value.findIndex((each) => each.ref_id === comment.ref_id);

    list.value =
        at === -1
            ? [...list.value, comment]
            : list.value.map((each, index) => (index === at ? comment : each));
};

/**
 * Heard on the board's channel. The editor is on that channel already (for
 * who is there), and only listens; Play joins it, and leaves it again when
 * the last part of it showing comments goes.
 */
const hear = (board: string, list: Ref<PrototypeComment[]>) => {
    if (!echoIsConfigured()) {
        return () => {};
    }

    const name = `boards.${board}`;
    const channels = (
        echo().connector as unknown as { channels?: Record<string, unknown> }
    ).channels;
    const joinedAlready = !!channels?.[`presence-${name}`];
    const heard = (payload: { comment: PrototypeComment }) =>
        put(list, payload.comment);
    const channel = echo().join(name);

    channel.listen('.prototype.comment', heard);

    return () => {
        channel.stopListening('.prototype.comment', heard);

        if (!joinedAlready) {
            echo().leave(name);
        }
    };
};

/**
 * A prototype's comments, kept live: all of them, the open ones, and what
 * can be done to one. Shared by everything on the page that shows them.
 */
export function usePrototypeComments(board: string) {
    let store = kept.get(board);

    if (!store) {
        const comments = ref<PrototypeComment[]>([]);
        const loaded = ref(false);

        store = { comments, loaded, users: 0, stop: () => {} };
        kept.set(board, store);

        send<{ comments: PrototypeComment[] }>(
            boardComments.index.url(board),
            'GET',
        )
            .then((answer) => {
                // Anything heard while it was asked for stays
                for (const comment of answer.comments) {
                    if (
                        !comments.value.some((c) => c.ref_id === comment.ref_id)
                    ) {
                        comments.value = [...comments.value, comment];
                    }
                }
            })
            .catch(() => {})
            .finally(() => (loaded.value = true));

        store.stop = hear(board, comments);
    }

    const own = store;
    own.users++;

    if (getCurrentScope()) {
        onScopeDispose(() => {
            own.users--;

            if (own.users === 0) {
                own.stop();
                kept.delete(board);
            }
        });
    }

    const list = own.comments;
    const open = computed(() => list.value.filter((c) => !c.resolved));

    const answered = async (
        pending: Promise<{ comment: PrototypeComment }>,
    ) => {
        const { comment } = await pending;
        put(list, comment);

        return comment;
    };

    return {
        comments: list,
        open,
        count: computed(() => open.value.length),
        loaded: own.loaded,
        add: (screen: string, x: number, y: number, body: string) =>
            answered(
                send(boardComments.store.url(board), 'POST', {
                    screen,
                    x,
                    y,
                    body,
                }),
            ),
        resolve: (comment: string, resolved: boolean) =>
            answered(
                send(prototypeComments.update.url(comment), 'PATCH', {
                    resolved,
                }),
            ),
        edit: (comment: string, body: string) =>
            answered(
                send(prototypeComments.update.url(comment), 'PATCH', { body }),
            ),
        ticket: (comment: string) =>
            answered(send(prototypeComments.ticket.url(comment), 'POST')),
    };
}
