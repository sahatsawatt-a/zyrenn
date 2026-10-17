<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import BoardView from '@/features/boards/drawing/BoardView.vue';
import { boundsOf } from '@/features/boards/model/geometry';
import type { Item } from '@/features/boards/model/items';
import type { Trigger } from '@/features/prototypes/interactions';
import { fieldOf, gesturesOf, swipeOf, tapOf } from './engine';
import type { PlayState } from './engine';
import { partsOn, screensOf } from './navigation';
import type { Part } from './navigation';

// One screen of a prototype, played: the frame drawn as the board draws it,
// at `scale` pixels to a board unit, as tall as its device shows -- and, when
// the screen is taller, scrolling, with what is fixed to its top or bottom
// staying put. Over what answers a touch lie buttons that hear a tap, a
// swipe, a long press or the pointer coming over it; over a field, a real
// input. A tap that finds none of them lights them up for a moment.
const props = defineProps<{
    // The prototype's items as kept, and what has changed as it is played
    // The prototype's items as drawn now (drawnOf): rows repeated, switches
    // flipped, words filled in -- and what has changed as it is played
    items: Item[];
    state: PlayState;
    screen: string;
    scale: number;
    // How much of the screen shows at once, in board units
    viewHeight: number;
    // Taps are someone's comments' for now: nothing answers them here
    quiet?: boolean;
}>();

const emit = defineEmits<{
    touch: [item: Item, trigger: Trigger | 'tap'];
    type: [name: string, value: string];
    // Where a tap found nothing, 0 to 1 of the whole screen, scroll and all
    missed: [x: number, y: number];
}>();

// ------------------------------------------------------------------ Drawing
const played = computed(() => props.items);
const homes = computed(() => screensOf(props.items));
const frame = computed(() =>
    played.value.find((item) => item.id === props.screen),
);
const box = computed(() =>
    frame.value
        ? boundsOf(frame.value)
        : { x: 0, y: 0, width: 1, height: props.viewHeight },
);

const scrolls = computed(() => box.value.height > props.viewHeight + 2);
const px = (units: number) => `${units * props.scale}px`;

const parts = computed(() => partsOn(played.value, props.screen, homes.value));

// Only a scrolling screen keeps anything still: on one that fits, all of it is
const fixedOf = (item: Item) =>
    scrolls.value && (item.fixed === 'top' || item.fixed === 'bottom')
        ? item.fixed
        : null;

// What scrolls: everything but what is fixed -- and a field without its
// words, as its input shows them (as a hint, or what was typed)
const content = computed(() =>
    played.value.map((item) => {
        const pinnedHere =
            scrolls.value &&
            fixedOf(item) &&
            homes.value.get(item.id) === props.screen;

        if (pinnedHere) {
            return { ...item, hidden: true };
        }

        return fieldOf(item) && item.text ? { ...item, text: '' } : item;
    }),
);

/**
 * What is fixed to one edge, drawn over a stand-in frame as tall as the view
 * at that edge -- clear, so the screen shows through round it.
 */
const pinned = (edge: 'top' | 'bottom') => {
    const kept = played.value.filter(
        (item) =>
            !item.hidden &&
            homes.value.get(item.id) === props.screen &&
            fixedOf(item) === edge,
    );

    if (!kept.length) {
        return null;
    }

    const { x, y, width, height } = box.value;
    const stand: Item = {
        ...frame.value!,
        id: `${props.screen}-${edge}`,
        x,
        y: edge === 'top' ? y : y + height - props.viewHeight,
        width,
        height: props.viewHeight,
        fill: 'transparent',
        stroke: 'transparent',
        text: '',
    };

    return [
        stand,
        ...kept.map((item) =>
            fieldOf(item) && item.text ? { ...item, text: '' } : item,
        ),
    ];
};

const top = computed(() => pinned('top'));
const bottom = computed(() => pinned('bottom'));

const where = (part: Part) => ({
    left: px(part.x),
    width: px(part.width),
    height: px(part.height),
    ...(fixedOf(part.item) === 'bottom'
        ? { bottom: px(part.y) }
        : { top: px(part.y) }),
});

const loose = computed(() => parts.value.filter((part) => !fixedOf(part.item)));
const fixedParts = computed(() =>
    parts.value.filter((part) => fixedOf(part.item)),
);

// ------------------------------------------------------------------- Fields
// A field's own words are its hint; what is typed is kept under its name
const hintOf = (part: Part) => part.item.text;
const valueOf = (part: Part) => {
    const value = props.state.values[fieldOf(part.item) ?? ''];

    return typeof value === 'string' ? value : '';
};
// What is typed is written dark on a light field and light on a dark one; the
// field's own colour, which the kit gives its hint, stays the hint's
const DARK_INK = '#0f172a';
const LIGHT_INK = '#f8fafc';

const isDark = (colour: string) => {
    const hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(colour.trim())?.[1];

    if (!hex) {
        return false;
    }

    const full = hex.length === 3 ? [...hex].map((c) => c + c).join('') : hex;
    const [r, g, b] = [0, 2, 4].map((at) =>
        parseInt(full.slice(at, at + 2), 16),
    );

    // Perceived lightness, 0 to 255
    return 0.299 * r + 0.587 * g + 0.114 * b < 140;
};

const fieldStyle = (part: Part) => ({
    ...where(part),
    fontSize: px(part.item.fontSize || 16),
    paddingLeft: px(Math.max(12, part.item.padding || 0)),
    paddingRight: px(Math.max(12, part.item.padding || 0)),
    color: isDark(part.item.fill || '') ? LIGHT_INK : DARK_INK,
    '--hint': part.item.color || undefined,
});

// ------------------------------------------------------------------ Touches
// A tap that missed lights up what can be touched, for a moment
const FLASH = 1400;
const flashing = ref(false);
let flashTimer: ReturnType<typeof setTimeout> | undefined;

const missed = (event: MouseEvent) => {
    const target = event.target as HTMLElement;

    // The click a swipe or long press ends in -- which can land off its part
    // once what it did has changed the screen -- is no tap that missed
    if (spent) {
        spent = false;

        return;
    }

    if (props.quiet || target.closest('[data-part]')) {
        return;
    }

    const area = (event.currentTarget as HTMLElement).getBoundingClientRect();
    emit(
        'missed',
        Math.min(1, Math.max(0, (event.clientX - area.left) / area.width)),
        Math.min(1, Math.max(0, (event.clientY - area.top) / area.height)),
    );

    flashing.value = true;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => (flashing.value = false), FLASH);
};

// Held, slid or let go: a finger (or a mouse) on a part
const HOLD = 500;
let press: {
    id: string;
    x: number;
    y: number;
    at: number;
    held: boolean;
    timer?: ReturnType<typeof setTimeout>;
} | null = null;
// A swipe or a long press is not also a tap
let spent = false;

const down = (part: Part, event: PointerEvent) => {
    spent = false;

    // The pointer stays the part's until it is let go, as a finger's does --
    // so a mouse dragged off it still ends its swipe here
    try {
        (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
    } catch {
        // A pointer already gone: nothing to hold on to
    }

    press = {
        id: part.item.id,
        x: event.clientX,
        y: event.clientY,
        at: performance.now(),
        held: false,
    };

    if (gesturesOf(part.item).includes('long-press')) {
        const mine = press;
        mine.timer = setTimeout(() => {
            mine.held = true;
            spent = true;
            emit('touch', part.item, 'long-press');
        }, HOLD);
    }
};

const move = (event: PointerEvent) => {
    if (
        press?.timer &&
        Math.hypot(event.clientX - press.x, event.clientY - press.y) > 10
    ) {
        clearTimeout(press.timer);
        press.timer = undefined;
    }
};

const up = (part: Part, event: PointerEvent) => {
    if (!press || press.id !== part.item.id) {
        return;
    }

    clearTimeout(press.timer);

    const swipe = swipeOf(
        event.clientX - press.x,
        event.clientY - press.y,
        performance.now() - press.at,
    );

    if (swipe && gesturesOf(part.item).includes(swipe)) {
        spent = true;
        emit('touch', part.item, swipe);
    }

    press = null;
};

const tap = (part: Part) => {
    if (spent) {
        spent = false;

        return;
    }

    emit('touch', part.item, 'tap');
};

// The pointer over it: a mouse's only, as a finger has no hover
const over = (part: Part, event: PointerEvent) => {
    if (
        event.pointerType === 'mouse' &&
        gesturesOf(part.item).includes('hover')
    ) {
        emit('touch', part.item, 'hover');
    }
};

// A part that answers a swipe keeps the finger from scrolling the screen
const holdsFinger = (part: Part) =>
    gesturesOf(part.item).some((trigger) => trigger.startsWith('swipe'));

// What a part is called: its words, or what it does
const nameOf = (part: Part) => {
    const words = part.item.text.trim().split('\n')[0];

    if (words) {
        return words;
    }

    const tap = tapOf(part.item);

    return tap ? 'Go on' : part.item.kind.replace('_', ' ');
};

onBeforeUnmount(() => {
    clearTimeout(flashTimer);
    clearTimeout(press?.timer);
});
</script>

<template>
    <div
        class="screen-view"
        :class="{ 'is-lit': flashing, 'is-quiet': quiet }"
        :style="{
            width: px(box.width),
            height: px(Math.min(viewHeight, box.height)),
        }"
        :data-screen="screen"
        data-test="play-view"
    >
        <div class="screen-scroll" :class="{ 'is-scrolling': scrolls }">
            <div
                class="screen-content"
                :style="{ width: px(box.width), height: px(box.height) }"
                data-test="play-content"
                @click="missed"
            >
                <BoardView :items="content" :frame="screen" :padding="0" bare />

                <template v-for="part in loose" :key="part.item.id">
                    <input
                        v-if="part.kind === 'field'"
                        class="screen-field"
                        :style="fieldStyle(part)"
                        :value="valueOf(part)"
                        :placeholder="hintOf(part)"
                        :aria-label="
                            hintOf(part) || fieldOf(part.item) || 'Field'
                        "
                        :disabled="quiet"
                        data-part
                        :data-field="fieldOf(part.item)"
                        @input="
                            emit(
                                'type',
                                fieldOf(part.item)!,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <button
                        v-else
                        type="button"
                        class="screen-part"
                        :class="{ 'holds-finger': holdsFinger(part) }"
                        :style="where(part)"
                        :aria-label="nameOf(part)"
                        :aria-pressed="
                            ['toggle', 'checkbox', 'radio', 'tab'].includes(
                                part.item.kind,
                            ) && !tapOf(part.item)
                                ? part.item.on
                                : undefined
                        "
                        data-part
                        :data-hotspot="part.item.id"
                        data-test="play-hotspot"
                        @pointerdown="down(part, $event)"
                        @pointermove="move"
                        @pointerup="up(part, $event)"
                        @pointerenter="over(part, $event)"
                        @click="tap(part)"
                    />
                </template>

                <!-- Someone's comments on it, scrolling with it -->
                <slot name="content" />
            </div>
        </div>

        <!-- What stays put while the screen scrolls -->
        <div
            v-for="layer in [
                { edge: 'top', items: top },
                { edge: 'bottom', items: bottom },
            ].filter((one) => one.items)"
            :key="layer.edge"
            class="screen-pinned"
            :data-pinned="layer.edge"
        >
            <BoardView
                :items="layer.items!"
                :frame="`${screen}-${layer.edge}`"
                :padding="0"
                bare
            />
        </div>
        <template v-for="part in fixedParts" :key="part.item.id">
            <input
                v-if="part.kind === 'field'"
                class="screen-field"
                :style="fieldStyle(part)"
                :value="valueOf(part)"
                :placeholder="hintOf(part)"
                :aria-label="hintOf(part) || fieldOf(part.item) || 'Field'"
                :disabled="quiet"
                data-part
                :data-field="fieldOf(part.item)"
                @input="
                    emit(
                        'type',
                        fieldOf(part.item)!,
                        ($event.target as HTMLInputElement).value,
                    )
                "
            />
            <button
                v-else
                type="button"
                class="screen-part"
                :style="where(part)"
                :aria-label="nameOf(part)"
                data-part
                :data-hotspot="part.item.id"
                data-test="play-hotspot"
                @pointerdown="down(part, $event)"
                @pointermove="move"
                @pointerup="up(part, $event)"
                @pointerenter="over(part, $event)"
                @click="tap(part)"
            />
        </template>

        <Transition name="screen-hint">
            <p
                v-if="flashing"
                class="screen-hint"
                role="status"
                data-test="play-hint"
            >
                {{
                    parts.some((part) => part.kind === 'live')
                        ? 'Tap the highlighted parts'
                        : 'Nothing on this screen leads anywhere yet'
                }}
            </p>
        </Transition>
    </div>
</template>

<style scoped>
.screen-view {
    position: relative;
    overflow: hidden;
    background: var(--background);
}

.screen-scroll {
    position: absolute;
    inset: 0;
    overflow: hidden;
}

.screen-scroll.is-scrolling {
    overflow-x: hidden;
    overflow-y: auto;
    overscroll-behavior: contain;
    scrollbar-width: none;
}

.screen-scroll.is-scrolling::-webkit-scrollbar {
    display: none;
}

.screen-content {
    position: relative;
}

/* What is fixed lies over the screen, which shows through round it */
.screen-pinned {
    position: absolute;
    inset: 0;
    pointer-events: none;
}

.screen-pinned :deep(.board-view) {
    background: transparent;
}

.screen-part {
    position: absolute;
    z-index: 1;
    border-radius: 6px;
    cursor: pointer;
    transition:
        background-color 0.2s,
        box-shadow 0.2s;
}

.screen-part.holds-finger {
    touch-action: none;
}

.screen-part:focus-visible,
.screen-field:focus-visible {
    outline: 2px solid var(--ring);
    outline-offset: 2px;
}

.is-lit .screen-part {
    background: rgb(56 189 248 / 0.22);
    box-shadow:
        inset 0 0 0 2px rgb(14 165 233 / 0.9),
        0 0 0 4px rgb(56 189 248 / 0.35);
}

.screen-hint {
    position: absolute;
    bottom: 16px;
    left: 50%;
    z-index: 3;
    max-width: calc(100% - 32px);
    padding: 6px 12px;
    border-radius: 999px;
    background: rgb(15 23 42 / 0.85);
    color: white;
    font-size: 13px;
    pointer-events: none;
    transform: translateX(-50%);
    white-space: nowrap;
}

.screen-hint-enter-active,
.screen-hint-leave-active {
    transition: opacity 0.15s;
}

.screen-hint-enter-from,
.screen-hint-leave-to {
    opacity: 0;
}

/* A field typed into: clear over the field the board draws */
.screen-field {
    position: absolute;
    z-index: 1;
    border: 0;
    background: transparent;
    font-family: inherit;
    outline: none;
}

.screen-field::placeholder {
    color: var(--hint, #64748b);
    opacity: 1;
}

.is-quiet .screen-part,
.is-quiet .screen-field {
    pointer-events: none;
}
</style>
