<script setup lang="ts">
import { useElementSize } from '@vueuse/core';
import { computed, useTemplateRef } from 'vue';
import { boundsOf } from '@/features/boards/model/geometry';
import type { Item } from '@/features/boards/model/items';
import { DEVICES } from '@/features/prototypes/devices';
import type { Device } from '@/features/prototypes/devices';
import type {
    OverlayPlace,
    Transition,
    Trigger,
} from '@/features/prototypes/interactions';
import { viewOf } from './engine';
import type { PlayState } from './engine';
import ScreenView from './ScreenView.vue';

// The prototype as it is played: the screen it is on, inside the device it
// is made for -- a phone or tablet's rounded glass, a desktop's browser
// window -- or, on a phone, alone and filling it; and over it, dimmed behind,
// whatever screens were opened over it, the last on top. Each move looks as
// its link says: a slide, a push, a fade, up from below, or none.
export type Overlay = {
    screen: string;
    place: OverlayPlace;
    transition: Transition;
};

const props = defineProps<{
    items: Item[];
    state: PlayState;
    screen: string;
    overlays: Overlay[];
    device: Device;
    // In the device it is made for; off, the screen alone (on a phone)
    framed: boolean;
    // How the last move looks, and which way it went
    transition: Transition;
    direction: 'on' | 'back';
    // Said in the desktop window's address bar
    title: string;
    // Taps are for comments for now
    quiet?: boolean;
}>();

const emit = defineEmits<{
    touch: [item: Item, trigger: Trigger | 'tap'];
    type: [name: string, value: string];
    missed: [screen: string, x: number, y: number];
    // The dim behind the top overlay was tapped
    dismiss: [];
}>();

// ------------------------------------------------------------------ Sizing
const stage = useTemplateRef<HTMLDivElement>('stage');
const { width, height } = useElementSize(stage);

const sizeOf = (id: string) => {
    const frame = props.items.find((item) => item.id === id);
    const device = DEVICES[props.device];

    return frame
        ? boundsOf(frame)
        : { x: 0, y: 0, width: device.width, height: device.height };
};

// What the device shows of the screen, in board units: as tall as the device
// at the screen's width, the rest scrolled to
const view = computed(() => {
    const box = sizeOf(props.screen);

    return { width: box.width, height: viewOf(box, props.device).height };
});

// What the device adds round the screen, in pixels on the page
const BEZEL = 10;
const WINDOW_BAR = 34;

const shell = computed(() => {
    if (!props.framed) {
        return { x: 0, y: 0 };
    }

    return props.device === 'desktop'
        ? { x: 2, y: WINDOW_BAR + 2 }
        : { x: BEZEL * 2, y: BEZEL * 2 };
});

const scale = computed(() => {
    const room = props.framed ? 32 : 0;

    return Math.max(
        0.05,
        Math.min(
            (width.value - room - shell.value.x) / view.value.width,
            (height.value - room - shell.value.y) / view.value.height,
        ),
    );
});

const glass = computed(() => ({
    width: Math.round(view.value.width * scale.value),
    height: Math.round(view.value.height * scale.value),
}));

// The device's corners, as big on the page as they are on the device
const radius = computed(() =>
    props.framed ? DEVICES[props.device].radius * scale.value : 0,
);

// An overlay, at the screen's scale: no taller than the glass shows
const overlayView = (id: string) =>
    Math.min(sizeOf(id).height, view.value.height);

// ------------------------------------------------------------- Transitions
const base = computed(() =>
    props.transition === 'none'
        ? 'play-none'
        : `play-${props.transition}-${props.direction}`,
);

const overlayMotion = (overlay: Overlay) =>
    overlay.transition === 'none'
        ? 'play-none'
        : overlay.transition === 'fade'
          ? 'play-fade-on'
          : `play-over-${overlay.place}`;
</script>

<template>
    <div
        ref="stage"
        class="play-stage"
        :class="{ 'is-framed': framed }"
        data-test="play-stage"
    >
        <div
            class="play-device"
            :class="[`is-${device}`, { 'is-framed': framed }]"
            :style="
                framed && device !== 'desktop'
                    ? { borderRadius: `${radius + BEZEL}px` }
                    : undefined
            "
        >
            <!-- A desktop's browser window: its bar, with where it is -->
            <div v-if="framed && device === 'desktop'" class="play-window-bar">
                <span class="play-window-dots" aria-hidden="true">
                    <i /><i /><i />
                </span>
                <span class="play-window-address">{{ title }}</span>
            </div>

            <div
                class="play-glass"
                :style="{
                    width: `${glass.width}px`,
                    height: `${glass.height}px`,
                    borderRadius: `${radius}px`,
                }"
                data-test="play-screen"
                :data-screen="screen"
            >
                <Transition :name="base">
                    <div :key="screen" class="play-layer">
                        <ScreenView
                            :items="items"
                            :state="state"
                            :screen="screen"
                            :scale="scale"
                            :view-height="view.height"
                            :quiet="quiet || overlays.length > 0"
                            @touch="
                                (item, trigger) => emit('touch', item, trigger)
                            "
                            @type="(name, value) => emit('type', name, value)"
                            @missed="(x, y) => emit('missed', screen, x, y)"
                        >
                            <template #content>
                                <slot name="comments" />
                            </template>
                        </ScreenView>
                    </div>
                </Transition>

                <!-- Screens opened over this one, dimmed behind the top one -->
                <TransitionGroup name="play-dim">
                    <div
                        v-for="(overlay, depth) in overlays"
                        :key="`${depth}-${overlay.screen}`"
                        class="play-overlay"
                        :class="`is-${overlay.place}`"
                        data-test="play-overlay"
                        :data-overlay="overlay.screen"
                    >
                        <button
                            type="button"
                            class="play-dim"
                            aria-label="Close"
                            :tabindex="depth === overlays.length - 1 ? 0 : -1"
                            data-test="play-dim"
                            @click="
                                depth === overlays.length - 1 && emit('dismiss')
                            "
                        />
                        <Transition :name="overlayMotion(overlay)" appear>
                            <ScreenView
                                class="play-overlay-screen"
                                :items="items"
                                :state="state"
                                :screen="overlay.screen"
                                :scale="scale"
                                :view-height="overlayView(overlay.screen)"
                                :quiet="quiet || depth < overlays.length - 1"
                                @touch="
                                    (item, trigger) =>
                                        emit('touch', item, trigger)
                                "
                                @type="
                                    (name, value) => emit('type', name, value)
                                "
                                @missed="
                                    (x, y) =>
                                        emit('missed', overlay.screen, x, y)
                                "
                            />
                        </Transition>
                    </div>
                </TransitionGroup>
            </div>
        </div>
    </div>
</template>

<style scoped>
.play-stage {
    position: relative;
    display: flex;
    width: 100%;
    height: 100%;
    min-height: 0;
    align-items: center;
    justify-content: center;
}

/* ------------------------------------------------------------- The device */
.play-device.is-framed {
    position: relative;
    box-shadow:
        0 24px 60px rgb(15 23 42 / 0.28),
        0 2px 6px rgb(15 23 42 / 0.18);
}

.play-device.is-framed.is-phone,
.play-device.is-framed.is-tablet {
    padding: 10px;
    background: #0f1115;
    outline: 1px solid rgb(255 255 255 / 0.08);
}

.play-device.is-framed.is-desktop {
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--card);
}

.play-window-bar {
    display: flex;
    height: 34px;
    align-items: center;
    gap: 12px;
    padding: 0 12px;
    border-bottom: 1px solid var(--border);
    background: var(--muted);
}

.play-window-dots {
    display: inline-flex;
    gap: 6px;
}

.play-window-dots i {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--border);
}

.play-window-address {
    min-width: 0;
    flex: 1;
    max-width: 28rem;
    margin: 0 auto;
    overflow: hidden;
    padding: 3px 12px;
    border-radius: 999px;
    background: var(--background);
    color: var(--muted-foreground);
    font-size: 12px;
    text-align: center;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* ---------------------------------------------------- The screen, glassed */
.play-glass {
    position: relative;
    overflow: hidden;
    background: var(--background);
    /* Corners clip what slides through them */
    isolation: isolate;
}

.play-layer {
    position: absolute;
    inset: 0;
}

/* ---------------------------------------------------------------- Overlays */
.play-overlay {
    position: absolute;
    inset: 0;
    z-index: 5;
    display: flex;
    align-items: center;
    justify-content: center;
}

.play-overlay.is-bottom {
    align-items: flex-end;
}

.play-overlay.is-top {
    align-items: flex-start;
}

.play-overlay.is-left {
    justify-content: flex-start;
}

.play-overlay.is-right {
    justify-content: flex-end;
}

.play-dim {
    position: absolute;
    inset: 0;
    background: rgb(15 23 42 / 0.45);
    cursor: default;
}

.play-overlay-screen {
    position: relative;
    box-shadow: 0 12px 40px rgb(15 23 42 / 0.3);
}

.play-overlay.is-center .play-overlay-screen {
    border-radius: 14px;
}

.play-overlay.is-bottom .play-overlay-screen {
    border-radius: 16px 16px 0 0;
}

.play-dim-enter-active,
.play-dim-leave-active {
    transition: opacity 0.2s;
}

.play-dim-enter-from,
.play-dim-leave-to {
    opacity: 0;
}

/* ------------------------------------------------------- Between screens */
.play-slide-on-enter-active,
.play-slide-on-leave-active,
.play-slide-back-enter-active,
.play-slide-back-leave-active,
.play-push-on-enter-active,
.play-push-on-leave-active,
.play-push-back-enter-active,
.play-push-back-leave-active,
.play-up-on-enter-active,
.play-up-on-leave-active,
.play-up-back-enter-active,
.play-up-back-leave-active,
.play-fade-on-enter-active,
.play-fade-on-leave-active,
.play-fade-back-enter-active,
.play-fade-back-leave-active,
.play-over-bottom-enter-active,
.play-over-bottom-leave-active,
.play-over-top-enter-active,
.play-over-top-leave-active,
.play-over-left-enter-active,
.play-over-left-leave-active,
.play-over-right-enter-active,
.play-over-right-leave-active,
.play-over-center-enter-active,
.play-over-center-leave-active {
    transition:
        transform 0.24s cubic-bezier(0.2, 0.7, 0.2, 1),
        opacity 0.24s;
}

/* Slide: in a little way, as it fades in */
.play-slide-on-enter-from,
.play-slide-back-leave-to {
    opacity: 0;
    transform: translateX(14%);
}

.play-slide-on-leave-to,
.play-slide-back-enter-from {
    opacity: 0;
    transform: translateX(-8%);
}

/* Push: the new one pushes the old one out, the whole way */
.play-push-on-enter-from,
.play-push-back-leave-to {
    transform: translateX(100%);
}

.play-push-on-leave-to,
.play-push-back-enter-from {
    transform: translateX(-100%);
}

/* Up: the new one rises over the old, and goes back down */
.play-up-on-enter-from,
.play-up-back-leave-to {
    transform: translateY(100%);
}

.play-up-on-enter-active,
.play-up-back-leave-active {
    z-index: 1;
}

.play-fade-on-enter-from,
.play-fade-on-leave-to,
.play-fade-back-enter-from,
.play-fade-back-leave-to {
    opacity: 0;
}

/* An overlay comes in from where it sits */
.play-over-bottom-enter-from,
.play-over-bottom-leave-to {
    transform: translateY(100%);
}

.play-over-top-enter-from,
.play-over-top-leave-to {
    transform: translateY(-100%);
}

.play-over-left-enter-from,
.play-over-left-leave-to {
    transform: translateX(-100%);
}

.play-over-right-enter-from,
.play-over-right-leave-to {
    transform: translateX(100%);
}

.play-over-center-enter-from,
.play-over-center-leave-to {
    opacity: 0;
    transform: scale(0.96);
}

/* Less movement asked for: every move a quick fade, nothing travels */
@media (prefers-reduced-motion: reduce) {
    .play-slide-on-enter-active,
    .play-slide-on-leave-active,
    .play-slide-back-enter-active,
    .play-slide-back-leave-active,
    .play-push-on-enter-active,
    .play-push-on-leave-active,
    .play-push-back-enter-active,
    .play-push-back-leave-active,
    .play-up-on-enter-active,
    .play-up-on-leave-active,
    .play-up-back-enter-active,
    .play-up-back-leave-active,
    .play-over-bottom-enter-active,
    .play-over-bottom-leave-active,
    .play-over-top-enter-active,
    .play-over-top-leave-active,
    .play-over-left-enter-active,
    .play-over-left-leave-active,
    .play-over-right-enter-active,
    .play-over-right-leave-active,
    .play-over-center-enter-active,
    .play-over-center-leave-active {
        transition: opacity 0.12s;
    }

    .play-slide-on-enter-from,
    .play-slide-on-leave-to,
    .play-slide-back-enter-from,
    .play-slide-back-leave-to,
    .play-push-on-enter-from,
    .play-push-on-leave-to,
    .play-push-back-enter-from,
    .play-push-back-leave-to,
    .play-up-on-enter-from,
    .play-up-back-leave-to,
    .play-over-bottom-enter-from,
    .play-over-bottom-leave-to,
    .play-over-top-enter-from,
    .play-over-top-leave-to,
    .play-over-left-enter-from,
    .play-over-left-leave-to,
    .play-over-right-enter-from,
    .play-over-right-leave-to,
    .play-over-center-enter-from,
    .play-over-center-leave-to {
        opacity: 0;
        transform: none;
    }
}
</style>
