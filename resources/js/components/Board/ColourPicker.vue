<script setup lang="ts">
import { Pipette } from '@lucide/vue';
import { useEventListener } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { PALETTE } from '../../composables/board/items';

// A saturation/value square over a hue slider: the picker Figma, Miro and
// friends use. Hand-built rather than pulled from a package -- the well-known
// wheel library has not shipped since 2022, and this way it takes the app's
// own tokens and needs no wrapper to talk to Vue.
const props = defineProps<{ modelValue: string; label: string }>();
const emit = defineEmits<{ 'update:modelValue': [string] }>();

type Hsv = { h: number; s: number; v: number };

const toHsv = (hex: string): Hsv => {
    const value = hex.replace('#', '');
    const r = parseInt(value.slice(0, 2), 16) / 255;
    const g = parseInt(value.slice(2, 4), 16) / 255;
    const b = parseInt(value.slice(4, 6), 16) / 255;

    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const span = max - min;

    let h = 0;
    if (span) {
        if (max === r) h = ((g - b) / span) % 6;
        else if (max === g) h = (b - r) / span + 2;
        else h = (r - g) / span + 4;
    }

    return {
        h: (Math.round(h * 60) + 360) % 360,
        s: max ? span / max : 0,
        v: max,
    };
};

const toHex = ({ h, s, v }: Hsv): string => {
    const c = v * s;
    const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
    const m = v - c;

    const [r, g, b] = (
        h < 60
            ? [c, x, 0]
            : h < 120
              ? [x, c, 0]
              : h < 180
                ? [0, c, x]
                : h < 240
                  ? [0, x, c]
                  : h < 300
                    ? [x, 0, c]
                    : [c, 0, x]
    ).map((channel) =>
        Math.round((channel + m) * 255)
            .toString(16)
            .padStart(2, '0'),
    );

    return `#${r}${g}${b}`;
};

const hsv = ref<Hsv>(toHsv(props.modelValue));
const hex = ref(props.modelValue);

// Follow the outside value unless it is the one we just emitted, so dragging
// does not fight the parent.
watch(
    () => props.modelValue,
    (value) => {
        if (value.toLowerCase() !== hex.value.toLowerCase()) {
            hsv.value = toHsv(value);
            hex.value = value;
        }
    },
);

const push = () => {
    hex.value = toHex(hsv.value);
    emit('update:modelValue', hex.value);
};

const onHexInput = (event: Event) => {
    const value = (event.target as HTMLInputElement).value.trim();

    if (/^#[0-9a-f]{6}$/i.test(value)) {
        hsv.value = toHsv(value);
        hex.value = value;
        emit('update:modelValue', value);
    }
};

// ------------------------------------------------------------- dragging
const area = ref<HTMLElement | null>(null);
const hue = ref<HTMLElement | null>(null);
const dragging = ref<'area' | 'hue' | null>(null);

const clamp01 = (value: number) => Math.min(1, Math.max(0, value));

const applyPointer = (event: PointerEvent) => {
    if (dragging.value === 'area' && area.value) {
        const box = area.value.getBoundingClientRect();
        hsv.value.s = clamp01((event.clientX - box.left) / box.width);
        hsv.value.v = 1 - clamp01((event.clientY - box.top) / box.height);
        push();
    }

    if (dragging.value === 'hue' && hue.value) {
        const box = hue.value.getBoundingClientRect();
        hsv.value.h = Math.round(
            clamp01((event.clientX - box.left) / box.width) * 360,
        );
        push();
    }
};

// Takes the event rather than returning a handler: an inline @pointerdown
// discards the returned function, so a curried version silently did nothing.
const start = (which: 'area' | 'hue', event: PointerEvent) => {
    dragging.value = which;
    applyPointer(event);
};

useEventListener(window, 'pointermove', applyPointer);
useEventListener(window, 'pointerup', () => (dragging.value = null));

const hueColour = computed(() => toHex({ h: hsv.value.h, s: 1, v: 1 }));

// Chrome hands the real screen colour back; other browsers have no such thing
const canPick = typeof window !== 'undefined' && 'EyeDropper' in window;

const pickFromScreen = async () => {
    try {
        const dropper = new (
            window as unknown as {
                EyeDropper: new () => {
                    open: () => Promise<{ sRGBHex: string }>;
                };
            }
        ).EyeDropper();
        const result = await dropper.open();

        hsv.value = toHsv(result.sRGBHex);
        push();
    } catch {
        // the picker was dismissed
    }
};
</script>

<template>
    <div class="picker" data-test="colour-picker">
        <p class="picker-label">{{ label }}</p>

        <div
            ref="area"
            class="picker-area"
            :style="{ backgroundColor: hueColour }"
            data-test="picker-area"
            @pointerdown="start('area', $event)"
        >
            <span
                class="picker-knob"
                :style="{
                    left: `${hsv.s * 100}%`,
                    top: `${(1 - hsv.v) * 100}%`,
                    backgroundColor: hex,
                }"
            />
        </div>

        <div
            ref="hue"
            class="picker-hue"
            data-test="picker-hue"
            @pointerdown="start('hue', $event)"
        >
            <span
                class="picker-knob"
                :style="{
                    left: `${(hsv.h / 360) * 100}%`,
                    top: '50%',
                    backgroundColor: hueColour,
                }"
            />
        </div>

        <div class="picker-row">
            <span class="picker-chip" :style="{ backgroundColor: hex }" />
            <input
                class="picker-hex"
                :value="hex"
                spellcheck="false"
                data-test="picker-hex"
                @input="onHexInput"
            />
            <button
                v-if="canPick"
                type="button"
                class="picker-drop"
                title="Pick a colour from the screen"
                @click="pickFromScreen"
            >
                <Pipette class="size-4" />
            </button>
        </div>

        <div class="picker-presets">
            <button
                v-for="colour in PALETTE"
                :key="colour"
                type="button"
                class="picker-preset"
                :style="{ backgroundColor: colour }"
                :title="colour"
                @click="
                    hsv = toHsv(colour);
                    push();
                "
            />
        </div>
    </div>
</template>

<style scoped>
.picker {
    width: 232px;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.picker-label {
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted-foreground);
}

/* White left to right, black bottom to top, over the current hue */
.picker-area {
    position: relative;
    height: 130px;
    border-radius: var(--radius-md);
    background-image:
        linear-gradient(to top, #000, transparent),
        linear-gradient(to right, #fff, transparent);
    cursor: crosshair;
    touch-action: none;
}

.picker-hue {
    position: relative;
    height: 12px;
    border-radius: 999px;
    background: linear-gradient(
        to right,
        #f00 0%,
        #ff0 17%,
        #0f0 33%,
        #0ff 50%,
        #00f 67%,
        #f0f 83%,
        #f00 100%
    );
    cursor: ew-resize;
    touch-action: none;
}

.picker-knob {
    position: absolute;
    width: 14px;
    height: 14px;
    margin: -7px 0 0 -7px;
    border: 2px solid #fff;
    border-radius: 999px;
    box-shadow: 0 0 0 1px rgb(0 0 0 / 0.3);
    pointer-events: none;
}

.picker-row {
    display: flex;
    align-items: center;
    gap: 0.375rem;
}

.picker-chip {
    width: 1.5rem;
    height: 1.5rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
}

.picker-hex {
    flex: 1;
    min-width: 0;
    height: 1.75rem;
    padding: 0 0.5rem;
    font-family: var(--font-mono, monospace);
    font-size: 0.75rem;
    color: var(--foreground);
    background-color: var(--background);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
}

.picker-drop {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.75rem;
    height: 1.75rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
}
.picker-drop:hover {
    background-color: var(--muted);
}

.picker-presets {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 4px;
}

.picker-preset {
    height: 1.1rem;
    border: 1px solid rgb(0 0 0 / 0.15);
    border-radius: var(--radius-sm);
    cursor: pointer;
}
.picker-preset:hover {
    transform: scale(1.1);
}
</style>
