<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Motion, Step } from './steps';

// How a press, move or drag goes, past the thing it is on: where in it, the
// way it moves (straight, a curve, an SVG path), how long it takes, and how
// it is held (keys, a button, a double-click, a pen or finger). Folded away
// until asked for, or until any of it is set.

const props = defineProps<{ step: Step; readonly?: boolean }>();
const emit = defineEmits<{ change: [] }>();

const AUTO = 'auto';
const DURATIONS = [0.5, 1, 1.5, 2, 3, 5, 8, 10];
const KEY_SETS = [
    'Shift',
    'Alt',
    'Control',
    'Meta',
    'Control+Shift',
    'Alt+Shift',
];

const moves = computed(() => props.step.do !== 'down');
const holds = computed(() => props.step.do !== 'move');
const set = (step: Step) =>
    Boolean(
        step.at ||
        step.motion ||
        step.points ||
        step.path ||
        step.by ||
        step.duration != null ||
        step.keys ||
        step.button ||
        step.clicks != null ||
        step.pointer,
    );
const open = ref(set(props.step));

function put<K extends keyof Step>(field: K, value: Step[K] | typeof AUTO) {
    if (value === AUTO || value === '' || value == null) {
        delete props.step[field];
    } else {
        props.step[field] = value as Step[K];
    }

    emit('change');
}
</script>

<template>
    <div class="space-y-2 sm:col-span-2" data-test="step-pointer">
        <button
            type="button"
            class="text-muted-foreground hover:text-foreground flex items-center gap-1 text-xs"
            :aria-expanded="open"
            data-test="step-pointer-more"
            @click="open = !open"
        >
            <ChevronDown
                class="size-3.5 transition-transform"
                :class="open ? '' : '-rotate-90'"
            />
            {{
                moves
                    ? 'Where, how it moves, keys held'
                    : 'Where in it, keys held'
            }}
        </button>

        <div v-if="open" class="grid gap-2 sm:grid-cols-2">
            <label v-if="step.do !== 'move' || step.target" class="space-y-1">
                <span class="text-muted-foreground block text-xs">{{
                    step.do === 'move' ? 'Ends at, in it' : 'Pressed at, in it'
                }}</span>
                <Input
                    :model-value="step.at ?? ''"
                    :readonly="readonly"
                    placeholder="Its middle, or 0.2,0.3 of its box"
                    class="h-8"
                    data-test="step-at"
                    @update:model-value="put('at', String($event).trim())"
                />
            </label>

            <label v-if="moves" class="space-y-1">
                <span class="text-muted-foreground block text-xs"
                    >How it moves</span
                >
                <Select
                    :model-value="step.motion ?? 'straight'"
                    :disabled="readonly"
                    @update:model-value="
                        put(
                            'motion',
                            $event === 'straight' ? AUTO : ($event as Motion),
                        )
                    "
                >
                    <SelectTrigger
                        size="sm"
                        class="w-full"
                        aria-label="How it moves"
                        data-test="step-motion"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="straight">Straight</SelectItem>
                        <SelectItem value="curve">Through points</SelectItem>
                        <SelectItem value="path">Path</SelectItem>
                    </SelectContent>
                </Select>
            </label>

            <label
                v-if="moves && step.motion !== 'path'"
                class="space-y-1 sm:col-span-2"
            >
                <span class="text-muted-foreground block text-xs">{{
                    step.motion === 'curve'
                        ? 'Through these points, smoothly'
                        : 'Through these points, if any'
                }}</span>
                <Input
                    :model-value="step.points ?? ''"
                    :readonly="readonly"
                    :placeholder="
                        step.target
                            ? '0.2,0.3 0.5,0.8 0.8,0.3 -- of its box; 500ms pauses'
                            : '40,0 80,40 -- pixels from where it is; 500ms pauses'
                    "
                    class="h-8 font-mono text-xs"
                    data-test="step-points"
                    @update:model-value="put('points', String($event).trim())"
                />
            </label>

            <template v-if="moves && step.motion === 'path'">
                <label class="space-y-1 sm:col-span-2">
                    <span class="text-muted-foreground block text-xs"
                        >SVG path -- 0,0 where it is pressed; each M a new
                        stroke</span
                    >
                    <textarea
                        :value="step.path ?? ''"
                        :readonly="readonly"
                        rows="2"
                        class="bg-background dark:bg-input/30 border-input block w-full resize-y rounded-md border px-3 py-2 font-mono text-xs"
                        placeholder="M 0 0 a 60 60 0 1 0 120 0 a 60 60 0 1 0 -120 0"
                        aria-label="SVG path"
                        data-test="step-path"
                        @input="
                            put(
                                'path',
                                ($event.target as HTMLTextAreaElement).value,
                            )
                        "
                    />
                </label>
                <label class="space-y-1">
                    <span class="text-muted-foreground block text-xs"
                        >Its numbers are</span
                    >
                    <Select
                        :model-value="step.units ?? 'px'"
                        :disabled="readonly"
                        @update:model-value="
                            put('units', $event === 'px' ? AUTO : 'box')
                        "
                    >
                        <SelectTrigger
                            size="sm"
                            class="w-full"
                            aria-label="What the path's numbers are"
                            data-test="step-units"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="px">Pixels</SelectItem>
                            <SelectItem value="box"
                                >Of its box (0 to 1)</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </label>
            </template>

            <label v-if="moves && step.motion !== 'path'" class="space-y-1">
                <span class="text-muted-foreground block text-xs"
                    >Or ends this far on</span
                >
                <Input
                    :model-value="step.by ?? ''"
                    :readonly="readonly"
                    placeholder="120,-40 pixels"
                    class="h-8"
                    data-test="step-by"
                    @update:model-value="put('by', String($event).trim())"
                />
            </label>

            <label v-if="moves" class="space-y-1">
                <span class="text-muted-foreground block text-xs">Takes</span>
                <Select
                    :model-value="
                        step.duration == null ? AUTO : String(step.duration)
                    "
                    :disabled="readonly"
                    @update:model-value="
                        put('duration', $event === AUTO ? AUTO : Number($event))
                    "
                >
                    <SelectTrigger
                        size="sm"
                        class="w-full"
                        aria-label="How long the move takes"
                        data-test="step-duration"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="AUTO"
                            >As the run's speed</SelectItem
                        >
                        <SelectItem
                            v-for="one in DURATIONS"
                            :key="one"
                            :value="String(one)"
                            >{{ one }} s</SelectItem
                        >
                    </SelectContent>
                </Select>
            </label>

            <template v-if="holds">
                <label class="space-y-1">
                    <span class="text-muted-foreground block text-xs"
                        >Keys held</span
                    >
                    <Select
                        :model-value="step.keys || AUTO"
                        :disabled="readonly"
                        @update:model-value="put('keys', $event as string)"
                    >
                        <SelectTrigger
                            size="sm"
                            class="w-full"
                            aria-label="Keys held while it is"
                            data-test="step-keys"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="AUTO">None</SelectItem>
                            <SelectItem
                                v-for="one in KEY_SETS"
                                :key="one"
                                :value="one"
                                >{{ one }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </label>
                <label class="space-y-1">
                    <span class="text-muted-foreground block text-xs"
                        >Pressed with</span
                    >
                    <Select
                        :model-value="
                            [
                                step.pointer ?? 'mouse',
                                step.button ?? 'left',
                                Number(step.clicks) === 2 ? 2 : 1,
                            ].join(':')
                        "
                        :disabled="readonly"
                        @update:model-value="
                            (value) => {
                                const [pointer, button, clicks] =
                                    String(value).split(':');
                                put(
                                    'pointer',
                                    pointer === 'mouse'
                                        ? AUTO
                                        : (pointer as Step['pointer']),
                                );
                                put(
                                    'button',
                                    button === 'left'
                                        ? AUTO
                                        : (button as Step['button']),
                                );
                                put('clicks', clicks === '2' ? 2 : AUTO);
                            }
                        "
                    >
                        <SelectTrigger
                            size="sm"
                            class="w-full"
                            aria-label="What it is pressed with"
                            data-test="step-pressed-with"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="mouse:left:1"
                                >The mouse</SelectItem
                            >
                            <SelectItem value="mouse:left:2"
                                >A double-click, held</SelectItem
                            >
                            <SelectItem value="mouse:right:1"
                                >The right button</SelectItem
                            >
                            <SelectItem value="mouse:middle:1"
                                >The middle button</SelectItem
                            >
                            <SelectItem value="pen:left:1">A pen</SelectItem>
                            <SelectItem value="touch:left:1"
                                >A finger</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </label>
            </template>
        </div>
    </div>
</template>
