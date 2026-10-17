<script setup lang="ts">
import { CalendarDays, ChevronLeft, ChevronRight, Clock, X } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

// A date, a date and a time, or a time, the app's own way: one calendar on
// every device, in the app's words, weeks from Monday, and times in steps
// that fit what it is for -- where the browser's own takes its look and its
// order of day and month from the browser, and is a wheel on a phone.
//
// Its value is written as the browser's fields write theirs, so it takes
// their place as it is: "2026-10-23", "2026-10-23T14:00", "14:00".

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        modelValue: string | null | undefined;
        kind?: 'date' | 'datetime' | 'time';
        // What it is, for whoever cannot see it
        label: string;
        placeholder?: string;
        min?: string;
        max?: string;
        // Minutes between the times offered; any time can still be typed
        minuteStep?: number;
        clearable?: boolean;
        disabled?: boolean;
        compact?: boolean;
        id?: string;
        class?: string;
    }>(),
    { kind: 'date', minuteStep: 30 },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const open = ref(false);
const timeList = ref<HTMLElement | null>(null);

const pad = (n: number) => String(n).padStart(2, '0');
const isoDate = (d: Date) =>
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

// The value, read as its parts
const datePart = computed(() => {
    const v = props.modelValue ?? '';

    return props.kind === 'time'
        ? ''
        : (/^\d{4}-\d{2}-\d{2}/.exec(v)?.[0] ?? '');
});
const timePart = computed(() => {
    const v = props.modelValue ?? '';

    return props.kind === 'date' ? '' : (/(\d{2}:\d{2})/.exec(v)?.[1] ?? '');
});

const said = computed(() => {
    if (!props.modelValue) {
        return '';
    }

    const day = datePart.value
        ? new Date(`${datePart.value}T00:00`).toLocaleDateString('en-GB', {
              weekday: 'short',
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '';

    return [day, timePart.value].filter(Boolean).join(', ');
});

// The month on show
const shown = ref(new Date());

watch(open, async (now) => {
    if (!now) {
        return;
    }

    const at = datePart.value
        ? new Date(`${datePart.value}T00:00`)
        : new Date();
    shown.value = new Date(at.getFullYear(), at.getMonth(), 1);
    await nextTick();
    timeList.value
        ?.querySelector('[data-on="true"]')
        ?.scrollIntoView({ block: 'center' });
});

const month = computed(() =>
    shown.value.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }),
);

const minDate = computed(
    () => /^\d{4}-\d{2}-\d{2}/.exec(props.min ?? '')?.[0] ?? '',
);
const maxDate = computed(
    () => /^\d{4}-\d{2}-\d{2}/.exec(props.max ?? '')?.[0] ?? '',
);

const days = computed(() => {
    const first = new Date(
        shown.value.getFullYear(),
        shown.value.getMonth(),
        1,
    );
    const lead = (first.getDay() + 6) % 7;
    const today = isoDate(new Date());

    return Array.from({ length: 42 }, (_, i) => {
        const d = new Date(first.getFullYear(), first.getMonth(), 1 - lead + i);
        const iso = isoDate(d);

        return {
            iso,
            day: d.getDate(),
            out: d.getMonth() !== first.getMonth(),
            today: iso === today,
            chosen: iso === datePart.value,
            off:
                (minDate.value !== '' && iso < minDate.value) ||
                (maxDate.value !== '' && iso > maxDate.value),
        };
    });
});

const times = computed(() => {
    const step = Math.max(5, Math.min(60, props.minuteStep));
    const all = [];

    for (let m = 0; m < 24 * 60; m += step) {
        all.push(`${pad(Math.floor(m / 60))}:${pad(m % 60)}`);
    }

    // What it holds is offered, even off the steps
    return timePart.value && !all.includes(timePart.value)
        ? [...all, timePart.value].sort()
        : all;
});

function write(date: string, time: string) {
    const value =
        props.kind === 'date'
            ? date
            : props.kind === 'time'
              ? time
              : date && `${date}T${time || '09:00'}`;
    emit('update:modelValue', value);
}

function pickDay(iso: string) {
    write(iso, timePart.value);

    if (props.kind === 'date') {
        open.value = false;
    }
}

function pickTime(time: string) {
    write(datePart.value || isoDate(new Date()), time);
    open.value = false;
}

// A time typed as people do: 9, 930, 9:30, 21.15
function typedTime(event: Event) {
    const raw = (event.target as HTMLInputElement).value
        .trim()
        .replace('.', ':');
    const found = /^(\d{1,2})(?::?(\d{2}))?$/.exec(raw);

    if (!found) {
        return;
    }

    const h = Number(found[1]);
    const m = Number(found[2] ?? 0);

    if (h < 24 && m < 60) {
        pickTime(`${pad(h)}:${pad(m)}`);
    }
}

const moveMonth = (by: number) =>
    (shown.value = new Date(
        shown.value.getFullYear(),
        shown.value.getMonth() + by,
        1,
    ));

const Icon = computed(() => (props.kind === 'time' ? Clock : CalendarDays));
</script>

<template>
    <Popover v-model:open="open">
        <div :class="cn('relative min-w-0', props.class)">
            <PopoverTrigger as-child :disabled="disabled">
                <Button
                    v-bind="$attrs"
                    :id="id"
                    type="button"
                    variant="outline"
                    :aria-label="said ? `${label}: ${said}` : label"
                    :disabled="disabled"
                    class="dark:bg-input/30 w-full justify-between gap-2 px-3 font-normal tabular-nums"
                    :class="[
                        compact ? 'h-8 text-xs' : '',
                        clearable && modelValue ? 'pr-8' : '',
                    ]"
                    data-datetime
                >
                    <span
                        class="truncate"
                        :class="{ 'text-muted-foreground': !said }"
                        >{{
                            said ||
                            placeholder ||
                            (kind === 'time'
                                ? 'Choose a time'
                                : 'Choose a date')
                        }}</span
                    >
                    <component
                        :is="Icon"
                        v-if="!(clearable && modelValue)"
                        class="text-muted-foreground size-4 shrink-0"
                    />
                </Button>
            </PopoverTrigger>
            <button
                v-if="clearable && modelValue && !disabled"
                type="button"
                class="text-muted-foreground hover:bg-muted hover:text-foreground absolute top-1/2 right-1.5 grid size-6 -translate-y-1/2 place-items-center rounded"
                :aria-label="`Clear ${label.toLowerCase()}`"
                data-datetime-clear
                @click="emit('update:modelValue', '')"
            >
                <X class="size-3.5" />
            </button>
        </div>
        <PopoverContent
            align="start"
            class="flex w-auto gap-3 p-3"
            :aria-label="label"
            data-datetime-pop
        >
            <div v-if="kind !== 'time'" class="w-64">
                <div class="mb-2 flex items-center justify-between">
                    <button
                        type="button"
                        class="hover:bg-accent grid size-7 place-items-center rounded-md border"
                        aria-label="Previous month"
                        @click="moveMonth(-1)"
                    >
                        <ChevronLeft class="size-4" />
                    </button>
                    <span class="text-sm font-medium" data-datetime-month>{{
                        month
                    }}</span>
                    <button
                        type="button"
                        class="hover:bg-accent grid size-7 place-items-center rounded-md border"
                        aria-label="Next month"
                        @click="moveMonth(1)"
                    >
                        <ChevronRight class="size-4" />
                    </button>
                </div>
                <div
                    class="grid grid-cols-7 gap-0.5 text-center text-sm"
                    role="grid"
                >
                    <span
                        v-for="d in ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su']"
                        :key="d"
                        class="text-muted-foreground py-1 text-[11px]"
                        >{{ d }}</span
                    >
                    <button
                        v-for="d in days"
                        :key="d.iso"
                        type="button"
                        class="aspect-square rounded-md text-sm tabular-nums disabled:pointer-events-none disabled:opacity-30"
                        :class="[
                            d.chosen
                                ? 'bg-primary text-primary-foreground'
                                : 'hover:bg-accent',
                            d.out && !d.chosen
                                ? 'text-muted-foreground/60'
                                : '',
                            d.today && !d.chosen
                                ? 'ring-ring ring-1 ring-inset'
                                : '',
                        ]"
                        :disabled="d.off"
                        :aria-pressed="d.chosen"
                        :aria-label="d.iso"
                        :data-day="d.iso"
                        @click="pickDay(d.iso)"
                    >
                        {{ d.day }}
                    </button>
                </div>
                <button
                    type="button"
                    class="text-primary mt-2 text-xs hover:underline"
                    @click="pickDay(isoDate(new Date()))"
                >
                    Today
                </button>
            </div>
            <div v-if="kind !== 'date'" class="flex w-24 flex-col gap-2">
                <input
                    type="text"
                    inputmode="numeric"
                    :value="timePart"
                    placeholder="14:30"
                    class="border-input dark:bg-input/30 focus-visible:ring-ring/50 h-8 w-full rounded-md border bg-transparent px-2 text-center text-sm tabular-nums outline-none focus-visible:ring-[3px]"
                    aria-label="Type a time"
                    data-datetime-time
                    @change="typedTime"
                    @keydown.enter.prevent="typedTime"
                />
                <div
                    ref="timeList"
                    class="flex max-h-56 flex-col gap-0.5 overflow-y-auto pr-1"
                >
                    <button
                        v-for="t in times"
                        :key="t"
                        type="button"
                        class="rounded-md px-2 py-1 text-sm tabular-nums"
                        :class="
                            t === timePart
                                ? 'bg-primary text-primary-foreground'
                                : 'hover:bg-accent'
                        "
                        :data-on="t === timePart"
                        :data-time="t"
                        @click="pickTime(t)"
                    >
                        {{ t }}
                    </button>
                </div>
            </div>
        </PopoverContent>
    </Popover>
</template>
