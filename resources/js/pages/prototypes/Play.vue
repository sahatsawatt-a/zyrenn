<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Menu, X } from '@lucide/vue';
import { useEventListener, useMediaQuery } from '@vueuse/core';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Tip from '@/components/common/Tip.vue';
import { Button } from '@/components/ui/button';
import type { Item } from '@/features/boards/model/items';
import type { PrototypeSettings } from '@/features/prototypes/devices';
import type {
    Action,
    Transition,
    Trigger,
} from '@/features/prototypes/interactions';
import {
    afterOf,
    drawnOf,
    fieldOf,
    flip,
    freshState,
    isSwitch,
    perform,
    tapOf,
    triggerOf,
    typed,
} from '@/features/prototypes/play/engine';
import type { Move, PlayState } from '@/features/prototypes/play/engine';
import { pick, settle } from '@/features/prototypes/play/data';
import type { Row, Tables, Write } from '@/features/prototypes/play/data';
import { ask } from '@/features/browser/http';
import rows from '@/routes/prototypes/rows';
import PlayBar from '@/features/prototypes/play/PlayBar.vue';
import PlayScreen from '@/features/prototypes/play/PlayScreen.vue';
import type { Overlay } from '@/features/prototypes/play/PlayScreen.vue';
import {
    screenBeside,
    screenInHash,
    screensOf,
    startOf,
} from '@/features/prototypes/play/navigation';
import type { Screen } from '@/features/prototypes/play/navigation';
import { usePrototypeComments } from '@/features/prototypes/feedback';
import PlayComments from '@/features/prototypes/play/PlayComments.vue';
import { usePlayRecord } from '@/features/prototypes/play/usePlayRecord';
import { takePageSteps } from '@/lib/pageSteps';

// A prototype played: its screens one at a time, as the customer it is made
// for would go through them -- tapping, swiping, holding, typing -- nothing
// on them to change. Which screen it is on is in the address after the #, so
// it can be passed on, and each move is a step the browser's Back goes back
// over; what is opened over it, and what was typed or set, is the play's
// alone, and From the start clears it.
const props = defineProps<{
    board: { ref_id: string; title: string };
    prototype: PrototypeSettings;
    items: Item[];
    // In order: left to right, then top to bottom
    screens: Screen[];
    // The editor, for whoever may change it
    edit: string | null;
    // The project it is in; none, for one in a person's own space
    project: { ref_id: string; name: string } | null;
    // The project's tables it uses, as they are (at most 200 rows each), and
    // whether what is saved in Play goes to them too
    tables?: Tables;
    can_save?: boolean;
}>();

const title = computed(() => props.board.title || 'Untitled prototype');
const known = computed(() => new Set(props.screens.map((one) => one.id)));

// --------------------------------------------------------------- Where it is
// The trail of screens behind the one shown, kept in each step of the
// browser's history beside Inertia's own, so its Back and ours agree
type Kept = { prototypeTrail?: string[] } | null;

const first = startOf(props.screens, props.prototype.start);
const screen = ref(
    screenInHash(window.location.hash, props.screens) ?? first ?? '',
);
const trail = ref<string[]>(
    (window.history.state as Kept)?.prototypeTrail ?? [],
);
const overlays = ref<Overlay[]>([]);
const state = ref<PlayState>(freshState(props.tables));

// How the last move looks, and which way it went
const transition = ref<Transition>('slide');
const direction = ref<'on' | 'back'>('on');
// How the move back the browser is making should look, when Play asked for it
let backLooks: Transition | null = null;

const go = (next: string, behind: string[], looks: Transition = 'slide') => {
    overlays.value = [];

    if (next === screen.value && behind.length === trail.value.length) {
        return;
    }

    transition.value = looks;
    direction.value = 'on';
    screen.value = next;
    trail.value = behind;
    window.history.pushState(
        { ...window.history.state, prototypeTrail: behind },
        '',
        `#${encodeURIComponent(next)}`,
    );
};

// Going back is the browser's own Back, so a step back there and here are one
const back = (looks: Transition = 'slide') => {
    overlays.value = [];

    if (trail.value.length) {
        backLooks = looks;
        window.history.back();
    }
};

const jump = (id: string) => go(id, [...trail.value, screen.value]);

const restart = () => {
    state.value = freshState(props.tables);
    overlays.value = [];

    if (first) {
        go(first, []);
    }
};

// ------------------------------------------------------------ Doing things
const make = (move: Move) => {
    switch (move.kind) {
        case 'to':
            go(move.screen, [...trail.value, screen.value], move.transition);
            break;
        case 'back':
            back(move.transition);
            break;
        case 'overlay':
            overlays.value = [
                ...overlays.value,
                {
                    screen: move.screen,
                    place: move.place,
                    transition: move.transition,
                },
            ];
            break;
        case 'close':
            overlays.value = overlays.value.slice(0, -1);
            break;
    }
};

// The board as it is drawn now: each row card shown for its rows
const drawn = computed(() => drawnOf(props.items, state.value));
const drawnHomes = computed(() => screensOf(drawn.value.items));

// The fields on the screen shown and anything open over it, by name: all a
// save reads for the columns it does not say
const fieldsInReach = () => {
    const here = new Set([
        screen.value,
        ...overlays.value.map((overlay) => overlay.screen),
    ]);

    return new Set(
        drawn.value.items
            .filter((item) => here.has(drawnHomes.value.get(item.id) ?? ''))
            .map(fieldOf)
            .filter((name): name is string => !!name),
    );
};

const act = (action: Action) => {
    const done = perform(
        action,
        state.value,
        props.items,
        known.value,
        fieldsInReach(),
    );
    state.value = done.state;
    done.moves.forEach(make);
    done.writes.forEach(keep);
};

const touch = (item: Item, trigger: Trigger | 'tap') => {
    const action = trigger === 'tap' ? tapOf(item) : triggerOf(item, trigger);
    // Touched inside a row card: that row is its table's current one first
    const inRow = drawn.value.rows.get(item.id);

    if (inRow && (action || trigger === 'tap')) {
        state.value = { ...state.value, data: pick(state.value.data, inRow) };
    }

    if (action) {
        act(action);
    } else if (trigger === 'tap' && isSwitch(item)) {
        state.value = flip(item, state.value, drawn.value.items, (one) =>
            drawnHomes.value.get(one.id),
        );
    }
};

// ------------------------------------------------------------ The tables
// Saving to the tables themselves, for whoever may: each change sent, and
// the row the table keeps put in place of the one Play made
const saving = computed(
    () => props.prototype.data === 'tables' && props.can_save === true,
);

// How many rows each table has in this play (for the page's tests)
const rowCounts = computed(() =>
    JSON.stringify(
        Object.fromEntries(
            Object.entries(state.value.data.tables).map(([ref, table]) => [
                ref,
                table.rows.length,
            ]),
        ),
    ),
);

// A row's id back as the table gave it: a number when it is one
const idOf = (id: string) => (/^\d+$/.test(id) ? Number(id) : id);

const keep = async (write: Write) => {
    if (!saving.value) {
        return;
    }

    const board = props.board.ref_id;
    // Play's copy as it was before, should the table say no
    const before = state.value.data;

    try {
        if (write.kind === 'remove') {
            await ask(
                { url: rows.destroy.url(board), method: 'delete' },
                { table: write.table, row: idOf(write.row) },
            );

            return;
        }

        const { id, ...values } = write.row;
        const answer = await ask<{ row: Row }>(
            { url: rows.store.url(board), method: 'post' },
            {
                table: write.table,
                values,
                ...(write.isNew ? {} : { row: idOf(id) }),
            },
        );

        state.value = {
            ...state.value,
            data: settle(state.value.data, write.table, id, answer.row),
        };
    } catch (error) {
        toast.error((error as Error).message);
        state.value = { ...state.value, data: before };
    }
};

const type = (name: string, value: string) =>
    (state.value = typed(state.value, name, value));

// ------------------------------------------------------ By themselves, after
// Each screen shown -- the one it is on and those opened over it -- may move
// on by itself after a while; leaving it before then, it does not
const commenting = ref(false);
const timers = new Map<string, ReturnType<typeof setTimeout>>();

const shown = computed(() =>
    commenting.value
        ? []
        : [screen.value, ...overlays.value.map((one) => one.screen)].map(
              (id, depth) => `${depth}:${id}`,
          ),
);

watch(
    shown,
    (now) => {
        for (const [key, timer] of timers) {
            if (!now.includes(key)) {
                clearTimeout(timer);
                timers.delete(key);
            }
        }

        for (const key of now) {
            const id = key.slice(key.indexOf(':') + 1);
            const after = afterOf(props.items.find((item) => item.id === id));

            if (after && !timers.has(key)) {
                timers.set(
                    key,
                    setTimeout(() => {
                        timers.delete(key);
                        act(after.action);
                    }, after.ms),
                );
            }
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => timers.forEach((timer) => clearTimeout(timer)));

// A step of the browser's history within Play is Play's to show: Inertia
// would draw the page afresh for it, and what was typed would be gone
const path = window.location.pathname;

onBeforeUnmount(
    takePageSteps((event) => {
        if (window.location.pathname !== path) {
            return false;
        }

        overlays.value = [];
        transition.value = backLooks ?? 'slide';
        backLooks = null;
        direction.value = 'back';
        screen.value =
            screenInHash(window.location.hash, props.screens) ?? first ?? '';
        trail.value = (event.state as Kept)?.prototypeTrail ?? [];

        return true;
    }),
);

// On a desktop the arrow keys go through the screens in order, and Escape
// closes what is open over the screen
useEventListener(window, 'keydown', (event: KeyboardEvent) => {
    const typing = (event.target as HTMLElement).closest(
        'input, textarea, [contenteditable], [role="menu"]',
    );

    if (typing || event.altKey || event.ctrlKey || event.metaKey) {
        return;
    }

    if (event.key === 'Escape' && overlays.value.length) {
        overlays.value = overlays.value.slice(0, -1);

        return;
    }

    const by =
        event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
    const next = by ? screenBeside(props.screens, screen.value, by) : null;

    if (next) {
        event.preventDefault();
        jump(next);
    }
});

// ------------------------------------------------------------- Who saw what
const record = usePlayRecord(props.board.ref_id, screen);

// What people said about it, pinned where it is about
const pinsShown = ref(true);
const { count: comments } = usePrototypeComments(props.board.ref_id);

// ---------------------------------------------------------------- On a phone
// The screen fills it, with no device round it, and the bar folds away
const phone = useMediaQuery('(max-width: 640px)');
const barOpen = ref(false);
</script>

<template>
    <Head :title="`${title} · Play`" />

    <div
        class="play-page"
        :class="{ 'is-phone': phone }"
        :data-device="prototype.device"
        :data-values="JSON.stringify(state.values)"
        :data-overlays="overlays.length"
        :data-rows="rowCounts"
        data-test="play"
    >
        <template v-if="screens.length">
            <div v-if="!phone" class="play-top">
                <PlayBar
                    :title="title"
                    :screens="screens"
                    :screen="screen"
                    :can-go-back="trail.length > 0"
                    :edit="edit"
                    :comments="comments"
                    v-model:commenting="commenting"
                    v-model:pins-shown="pinsShown"
                    @jump="jump"
                    @back="back"
                    @restart="restart"
                />
            </div>

            <PlayScreen
                :items="drawn.items"
                :state="state"
                :screen="screen"
                :overlays="overlays"
                :device="prototype.device"
                :framed="!phone"
                :transition="transition"
                :direction="direction"
                :title="title"
                :quiet="commenting"
                class="min-h-0 flex-1"
                @touch="touch"
                @type="type"
                @missed="record.miss"
                @dismiss="overlays = overlays.slice(0, -1)"
            >
                <template #comments>
                    <PlayComments
                        :board="board.ref_id"
                        :screen="screen"
                        :can-change="edit !== null"
                        v-model:commenting="commenting"
                        v-model:shown="pinsShown"
                    />
                </template>
            </PlayScreen>

            <p v-if="!phone" class="play-keys">
                Tap what leads on · ← → go through the screens
            </p>

            <!-- On a phone: the bar only when asked for -->
            <template v-if="phone">
                <Tip :text="barOpen ? 'Hide the bar' : 'Show the bar'">
                    <Button
                        variant="secondary"
                        size="icon-sm"
                        class="play-bar-toggle"
                        :aria-label="barOpen ? 'Hide the bar' : 'Show the bar'"
                        :aria-expanded="barOpen"
                        data-test="play-bar-toggle"
                        @click="barOpen = !barOpen"
                    >
                        <X v-if="barOpen" />
                        <Menu v-else />
                    </Button>
                </Tip>
                <div v-if="barOpen" class="play-top is-floating">
                    <PlayBar
                        :title="title"
                        :screens="screens"
                        :screen="screen"
                        :can-go-back="trail.length > 0"
                        :edit="edit"
                        :comments="comments"
                        v-model:commenting="commenting"
                        v-model:pins-shown="pinsShown"
                        @jump="jump"
                        @back="back"
                        @restart="restart"
                    />
                </div>
            </template>
        </template>

        <div v-else class="play-empty" data-test="play-empty">
            <p class="text-base font-medium">No screens yet</p>
            <p class="text-muted-foreground text-sm">
                A screen is a frame on the prototype's board.
            </p>
            <Button v-if="edit" as-child size="sm" class="mt-3">
                <a :href="edit">Add a screen</a>
            </Button>
        </div>
    </div>
</template>

<style scoped>
.play-page {
    display: flex;
    height: 100dvh;
    flex-direction: column;
    gap: 12px;
    padding: 12px 16px 10px;
    background: var(--muted);
    color: var(--foreground);
}

.play-page.is-phone {
    position: relative;
    gap: 0;
    padding: 0;
    background: var(--background);
}

.play-top {
    width: 100%;
    max-width: 44rem;
    margin: 0 auto;
}

.play-top.is-floating {
    position: absolute;
    top: 8px;
    right: 8px;
    left: 8px;
    z-index: 20;
    width: auto;
    padding-right: 44px;
}

.play-bar-toggle {
    position: absolute;
    top: 8px;
    right: 8px;
    z-index: 21;
    box-shadow: 0 2px 8px rgb(15 23 42 / 0.18);
}

.play-keys {
    color: var(--muted-foreground);
    font-size: 12px;
    text-align: center;
}

.play-empty {
    display: flex;
    flex: 1;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    text-align: center;
}
</style>
