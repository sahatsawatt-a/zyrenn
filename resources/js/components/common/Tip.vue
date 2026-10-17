<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

// A short word on what a button does, the app's own way: at once, in the
// app's look, on keyboard focus too -- where the browser's title waits a
// second, in the system's box, and never shows on a phone. Wrap an
// icon-only button in it; the button still needs its own aria-label.

withDefaults(
    defineProps<{
        text?: string;
        side?: 'top' | 'right' | 'bottom' | 'left';
        // Off: the button is shown with no word, as when its words are on it already
        disabled?: boolean;
        // Where it is drawn, for a button inside something shown fullscreen
        to?: string | HTMLElement;
    }>(),
    { side: 'top' },
);

// ------------------------------------------------------------------- Touch
// Reka's tooltip leaves touch alone; on a phone a finger held on the button
// shows it, and lifting the finger then is not a tap
const HOLD = 500;
// A finger that slides this far is scrolling, not holding
const SLIDE = 10;

const open = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let from = { x: 0, y: 0 };
let held = false;

const letGo = () => {
    clearTimeout(timer);
    timer = undefined;
    open.value = false;
};

const press = (event: PointerEvent) => {
    if (event.pointerType !== 'touch') {
        return;
    }

    held = false;
    from = { x: event.clientX, y: event.clientY };
    clearTimeout(timer);
    timer = setTimeout(() => {
        held = true;
        open.value = true;
    }, HOLD);
};

const slide = (event: PointerEvent) => {
    if (
        timer !== undefined &&
        Math.hypot(event.clientX - from.x, event.clientY - from.y) > SLIDE
    ) {
        letGo();
    }
};

// What a finger held to read the tip lets go of is not a tap
const tapAfterHold = (event: MouseEvent) => {
    if (held) {
        held = false;
        event.preventDefault();
        event.stopPropagation();
    }
};

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <slot v-if="disabled || !text" />
    <TooltipProvider v-else :delay-duration="150" :skip-delay-duration="300">
        <!-- Focus shows it only from the keyboard: a menu or dialog that puts
             the focus on its first button opens no tip -- which would then
             take the Escape meant for the menu -->
        <Tooltip v-model:open="open" ignore-non-keyboard-focus>
            <TooltipTrigger
                as-child
                @pointerdown="press"
                @pointermove="slide"
                @pointerup="letGo"
                @pointercancel="letGo"
                @contextmenu="timer !== undefined && $event.preventDefault()"
                @click.capture="tapAfterHold"
            >
                <slot />
            </TooltipTrigger>
            <!-- Above the media viewer and anything else laid over the page -->
            <TooltipContent :side="side" :to="to" class="z-[150] text-xs">{{
                text
            }}</TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
