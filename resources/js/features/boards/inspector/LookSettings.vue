<script setup lang="ts">
import { Plus, Sparkles, X } from '@lucide/vue';
import { computed } from 'vue';
import NumberField from '@/components/common/NumberField.vue';
import RangeField from '@/components/common/RangeField.vue';
import Tip from '@/components/common/Tip.vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import type { Blend, GradientStop, Item } from '@/features/boards/model/items';
import { hasText } from '@/features/boards/model/items';
import { HUGGING } from '@/features/boards/drawing/labels';
import { cssPaint } from '@/features/boards/drawing/paint';
import ColourChip from './ColourChip.vue';

// How what is chosen is painted beyond its colours: rounded corners, how
// see-through, a fill or a line that fades, a glow, softening and blending,
// a box as tall as its words, words spread apart or in capitals, the part
// of a picture kept in view, and the still a video shows. Each shows only
// for what it applies to; the rarer ones wait behind "Effects".
const props = defineProps<{
    selection: Item[];
    // Which side a colour popover opens on, as the rest of the panel's
    beside: 'top' | 'left' | 'right' | 'bottom';
}>();
const emit = defineEmits<{ update: [patch: Partial<Item>] }>();

const ROUNDED: Item['kind'][] = ['rect', 'sticky', 'frame', 'image', 'video'];
const FADING: Item['kind'][] = [
    'rect',
    'sticky',
    'frame',
    'pill',
    'ellipse',
    'star',
    'triangle',
    'diamond',
    'hexagon',
    'path',
];
// What has a line of its own that can fade
const LINED: Item['kind'][] = [
    'path',
    'arrow',
    'rect',
    'pill',
    'ellipse',
    'star',
    'triangle',
    'diamond',
    'hexagon',
];

const first = computed(() => props.selection[0]);
const every = (kinds: Item['kind'][]) =>
    props.selection.every((item) => kinds.includes(item.kind));
const rounded = computed(() => every(ROUNDED));
const fading = computed(() => every(FADING));
const lined = computed(() => every(LINED));
const hugging = computed(() => every(HUGGING));
const worded = computed(() => props.selection.every(hasText));
// A picture filling its box, or a slide's background: where to keep in view
const focused = computed(() =>
    props.selection.length === 1 &&
    ((first.value.kind === 'image' && first.value.fit === 'cover') ||
        (first.value.kind === 'frame' && !!first.value.src))
        ? first.value
        : null,
);
const video = computed(() =>
    props.selection.length === 1 && first.value.kind === 'video'
        ? first.value
        : null,
);

const radius = computed(
    () =>
        first.value.radius ??
        ({ rect: 8, sticky: 4, video: 4 } as Record<string, number>)[
            first.value.kind
        ] ??
        0,
);
const percent = (value: number | undefined) => Math.round((value ?? 1) * 100);

// Which way a fade runs, as CSS angles: the four a slide mostly needs
const DIRECTIONS = [
    { angle: 180, label: '↓', title: 'Top to bottom' },
    { angle: 90, label: '→', title: 'Left to right' },
    { angle: 135, label: '↘', title: 'Corner to corner' },
    { angle: 45, label: '↗', title: 'Bottom corner up' },
];

const BLENDS: { value: Blend; label: string }[] = [
    { value: 'source-over', label: 'Normal' },
    { value: 'multiply', label: 'Multiply: darker' },
    { value: 'screen', label: 'Screen: lighter' },
    { value: 'overlay', label: 'Overlay: more contrast' },
    { value: 'soft-light', label: 'Soft light' },
    { value: 'color-dodge', label: 'Colour dodge: glowing' },
    { value: 'darken', label: 'Darken' },
    { value: 'lighten', label: 'Lighten' },
];

// The nine points of a picture to keep in view
const SPOTS = [0, 0.5, 1].flatMap((y) => [0, 0.5, 1].map((x) => ({ x, y })));

// ------------------------------------------------------------- a fill's fade
const fades = computed(
    () =>
        !!first.value.gradient || (first.value.gradientStops?.length ?? 0) > 1,
);

/** Its colours from one end to the other: its stops, or fill to gradient. */
const stops = computed<GradientStop[]>(() =>
    first.value.gradientStops?.length
        ? first.value.gradientStops
        : [
              { at: 0, color: first.value.fill },
              { at: 1, color: first.value.gradient ?? first.value.fill },
          ],
);

const fade = (on: boolean) =>
    emit(
        'update',
        on
            ? { gradient: first.value.gradient || '#7c3aed' }
            : { gradient: '', gradientStops: [] },
    );

/** One of its colours changed: the first is its fill as well. */
const recolour = (at: number, colour: string) => {
    const next = stops.value.map((stop, index) =>
        index === at ? { ...stop, color: colour } : stop,
    );

    emit('update', {
        gradientStops: next,
        ...(at === 0 ? { fill: colour } : {}),
    });
};

/** Another colour, halfway between the last two. */
const addStop = () => {
    const list = [...stops.value];
    const last = list[list.length - 1];
    const before = list[list.length - 2];

    list.splice(list.length - 1, 0, {
        at: (before.at + last.at) / 2,
        color: last.color,
    });
    emit('update', { gradientStops: list });
};

const dropStop = (at: number) =>
    emit('update', {
        gradientStops: stops.value.filter((_, index) => index !== at),
    });
</script>

<template>
    <div class="space-y-2" data-test="look-settings">
        <label v-if="rounded" class="inspector-slider">
            Corners
            <RangeField
                :model-value="radius"
                label="Corners"
                :min="0"
                :max="80"
                class="flex-1"
                data-test="look-radius"
                @update:model-value="emit('update', { radius: $event })"
            />
        </label>

        <label class="inspector-slider">
            Opacity
            <RangeField
                :model-value="percent(first.opacity)"
                label="Opacity"
                :min="0"
                :max="100"
                unit="%"
                class="flex-1"
                data-test="look-opacity"
                @update:model-value="emit('update', { opacity: $event / 100 })"
            />
        </label>

        <!-- A fill that fades: straight or out from the middle, through two
             colours or more -->
        <div v-if="fading" class="inspector-align">
            <label class="inspector-switch">
                Gradient
                <Switch
                    :model-value="fades"
                    data-test="look-gradient"
                    @update:model-value="fade($event)"
                />
            </label>
            <template v-if="fades">
                <div class="inspector-segments">
                    <button
                        type="button"
                        :class="{ 'is-on': first.gradientType !== 'radial' }"
                        data-test="look-linear"
                        @click="emit('update', { gradientType: 'linear' })"
                    >
                        Linear
                    </button>
                    <button
                        type="button"
                        :class="{ 'is-on': first.gradientType === 'radial' }"
                        data-test="look-radial"
                        @click="emit('update', { gradientType: 'radial' })"
                    >
                        Radial
                    </button>
                </div>
                <!-- Its colours in order, each opened to change; one more
                     added between the last two -->
                <div
                    class="flex flex-wrap items-center gap-1"
                    data-test="look-stops"
                >
                    <span
                        v-for="(stop, at) in stops"
                        :key="at"
                        class="relative inline-flex"
                    >
                        <ColourChip
                            :colour="stop.color"
                            :label="`Colour ${at + 1} of the fade`"
                            :side="beside"
                            :swatch="stop.color"
                            @pick="recolour(at, $event)"
                        />
                        <Tip
                            v-if="
                                stops.length > 2 &&
                                at > 0 &&
                                at < stops.length - 1
                            "
                            text="Take this colour out"
                        >
                            <button
                                type="button"
                                class="bg-background absolute -top-1.5 -right-1.5 rounded-full p-0.5"
                                aria-label="Take this colour out"
                                @click="dropStop(at)"
                            >
                                <X :size="9" />
                            </button>
                        </Tip>
                    </span>
                    <Tip
                        v-if="stops.length < 6"
                        text="Another colour in the fade"
                    >
                        <button
                            type="button"
                            class="hover:bg-muted rounded-md border p-1"
                            aria-label="Another colour in the fade"
                            data-test="look-add-stop"
                            @click="addStop"
                        >
                            <Plus :size="13" />
                        </button>
                    </Tip>
                    <span
                        class="inspector-chip ml-auto w-12"
                        :style="{ background: cssPaint(first) }"
                    />
                </div>
                <div
                    v-if="first.gradientType !== 'radial'"
                    class="inspector-segments"
                >
                    <Tip
                        v-for="direction in DIRECTIONS"
                        :key="direction.angle"
                        :text="direction.title"
                    >
                        <button
                            type="button"
                            :aria-label="direction.title"
                            :class="{
                                'is-on':
                                    (first.gradientAngle ?? 180) ===
                                    direction.angle,
                            }"
                            :data-test="`look-angle-${direction.angle}`"
                            @click="
                                emit('update', {
                                    gradientAngle: direction.angle,
                                })
                            "
                        >
                            {{ direction.label }}
                        </button>
                    </Tip>
                </div>
            </template>
        </div>

        <!-- A line that fades to a second colour: along a connector, across
             a shape or a path -->
        <div v-if="lined" class="inspector-align">
            <label class="inspector-switch">
                Line gradient
                <Switch
                    :model-value="!!first.strokeGradient"
                    data-test="look-line-gradient"
                    @update:model-value="
                        emit('update', {
                            strokeGradient: $event
                                ? first.strokeGradient || '#06b6d4'
                                : '',
                            strokeStops: [],
                        })
                    "
                />
            </label>
            <div v-if="first.strokeGradient" class="inspector-segments">
                <ColourChip
                    :colour="first.strokeGradient"
                    label="The colour the line fades to"
                    :side="beside"
                    @pick="emit('update', { strokeGradient: $event })"
                >
                    Fades to
                </ColourChip>
            </div>
        </div>

        <label v-if="hugging" class="inspector-switch">
            Fit height to text
            <Switch
                :model-value="selection.every((item) => item.hug)"
                data-test="look-hug"
                @update:model-value="emit('update', { hug: $event })"
            />
        </label>

        <!-- Its words: spread apart, in capitals, fading to a second colour -->
        <template v-if="worded">
            <label class="inspector-slider">
                Letter spacing
                <RangeField
                    :model-value="first.letterSpacing ?? 0"
                    label="Letter spacing"
                    :min="0"
                    :max="20"
                    :step="0.5"
                    class="flex-1"
                    data-test="look-letter-spacing"
                    @update:model-value="
                        emit('update', { letterSpacing: $event })
                    "
                />
            </label>
            <label class="inspector-switch">
                Capitals
                <Switch
                    :model-value="!!first.caps"
                    data-test="look-caps"
                    @update:model-value="emit('update', { caps: $event })"
                />
            </label>
            <label class="inspector-switch">
                Words fade
                <Switch
                    :model-value="!!first.colorTo"
                    data-test="look-words-fade"
                    @update:model-value="
                        emit('update', {
                            colorTo: $event ? first.colorTo || '#06b6d4' : '',
                        })
                    "
                />
            </label>
            <div v-if="first.colorTo" class="inspector-segments">
                <ColourChip
                    :colour="first.colorTo"
                    label="The colour its words fade to"
                    :side="beside"
                    @pick="emit('update', { colorTo: $event })"
                >
                    To
                </ColourChip>
            </div>
        </template>

        <!-- The rarer ones, kept behind a click: glow, softening, blending,
             and an inside or line of their own transparency -->
        <Popover>
            <PopoverTrigger as-child>
                <button
                    type="button"
                    class="inspector-more"
                    data-test="look-effects"
                >
                    <Sparkles :size="13" /> Effects
                </button>
            </PopoverTrigger>
            <PopoverContent
                :side="beside"
                align="start"
                :collision-padding="8"
                class="w-72 space-y-3 p-3"
            >
                <label class="inspector-switch">
                    Glow
                    <Switch
                        :model-value="!!first.glow"
                        data-test="look-glow"
                        @update:model-value="
                            emit('update', {
                                glow: $event ? first.glow || '#6366f1' : '',
                            })
                        "
                    />
                </label>
                <div v-if="first.glow" class="flex items-center gap-2">
                    <ColourChip
                        :colour="first.glow"
                        label="The glow's colour"
                        :side="beside"
                        @pick="emit('update', { glow: $event })"
                    />
                    <label class="inspector-slider flex-1">
                        Size
                        <RangeField
                            :model-value="first.glowSize ?? 24"
                            label="Glow size"
                            :min="4"
                            :max="120"
                            :format="false"
                            class="flex-1"
                            data-test="look-glow-size"
                            @update:model-value="
                                emit('update', { glowSize: $event })
                            "
                        />
                    </label>
                </div>
                <label class="inspector-slider">
                    Blur
                    <RangeField
                        :model-value="first.blur ?? 0"
                        label="Blur"
                        :min="0"
                        :max="60"
                        class="flex-1"
                        data-test="look-blur"
                        @update:model-value="emit('update', { blur: $event })"
                    />
                </label>
                <label class="inspector-slider">
                    Fill
                    <RangeField
                        :model-value="percent(first.fillOpacity)"
                        label="Fill opacity"
                        :min="0"
                        :max="100"
                        unit="%"
                        class="flex-1"
                        data-test="look-fill-opacity"
                        @update:model-value="
                            emit('update', { fillOpacity: $event / 100 })
                        "
                    />
                </label>
                <label class="inspector-slider">
                    Line
                    <RangeField
                        :model-value="percent(first.strokeOpacity)"
                        label="Line opacity"
                        :min="0"
                        :max="100"
                        unit="%"
                        class="flex-1"
                        data-test="look-line-opacity"
                        @update:model-value="
                            emit('update', { strokeOpacity: $event / 100 })
                        "
                    />
                </label>
                <div class="space-y-1">
                    <p class="inspector-label">Blend</p>
                    <Select
                        :model-value="first.blend ?? 'source-over'"
                        @update:model-value="
                            emit('update', { blend: $event as Blend })
                        "
                    >
                        <SelectTrigger
                            class="w-full"
                            size="sm"
                            data-test="look-blend"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in BLENDS"
                                :key="option.value"
                                :value="option.value"
                                >{{ option.label }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
            </PopoverContent>
        </Popover>

        <!-- The part of the picture kept in view when it is cut to fit -->
        <div v-if="focused" class="inspector-align">
            <p class="inspector-label">Keep in view</p>
            <div class="grid w-fit grid-cols-3 gap-1" data-test="look-focus">
                <Tip
                    v-for="spot in SPOTS"
                    :key="`${spot.x}-${spot.y}`"
                    :text="`Keep the ${['top', 'middle', 'bottom'][spot.y * 2]} ${['left', 'centre', 'right'][spot.x * 2]} in view`"
                >
                    <button
                        type="button"
                        class="size-5 rounded border"
                        :class="
                            (focused.focusX ?? 0.5) === spot.x &&
                            (focused.focusY ?? 0.5) === spot.y
                                ? 'border-primary bg-primary'
                                : 'border-border hover:bg-muted'
                        "
                        :aria-label="`Keep the ${['top', 'middle', 'bottom'][spot.y * 2]} ${['left', 'centre', 'right'][spot.x * 2]} in view`"
                        @click="
                            emit('update', { focusX: spot.x, focusY: spot.y })
                        "
                    />
                </Tip>
            </div>
        </div>

        <!-- The still a video shows while it isn't playing: in pictures and
             PDFs of the board too -->
        <label v-if="video" class="inspector-slider">
            Still at
            <NumberField
                :model-value="video.posterAt ?? 0"
                label="Still at, in seconds"
                :min="0"
                :step="0.5"
                unit="s"
                compact
                data-test="look-poster-at"
                @change="emit('update', { posterAt: $event ?? 0 })"
            />
        </label>
    </div>
</template>

<style scoped>
.inspector-more {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.75rem;
    color: var(--muted-foreground);
}
.inspector-more:hover {
    color: var(--foreground);
}
</style>
