<script setup lang="ts">
import { ChevronLeft, ChevronRight, ExternalLink, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    CELL_INPUT,
    ensureHttp,
} from '@/features/tables/composables/useCellCell';
import type { ColumnMeta } from '@/types';

// A date, picked from a month; and the three kinds that are a line of text with
// somewhere to go -- an email, a link, a phone number.
const props = defineProps<{
    column: ColumnMeta;
    modelValue?: string | null;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string | null): void;
}>();

const WEEKDAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

const calendarOpen = ref(false);
const today = new Date();
const month = ref(today.getMonth());
const year = ref(today.getFullYear());

const isoOf = (y: number, m: number, d: number) =>
    `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;

const todayIso = isoOf(today.getFullYear(), today.getMonth(), today.getDate());

// "2026-10-15" is that day where the person is; new Date() alone reads it as UTC
const parseDay = (value?: string | null): Date | null => {
    const [y, m, d] = (value ?? '').split('-').map(Number);
    const day = new Date(y, m - 1, d);

    return value && !isNaN(day.getTime()) ? day : null;
};

// The month shown opens on the date there is, or on this month
const openCalendar = (open: boolean) => {
    calendarOpen.value = open;
    const shown = parseDay(props.modelValue);

    if (open && shown) {
        month.value = shown.getMonth();
        year.value = shown.getFullYear();
    }
};

const monthLabel = computed(() =>
    new Date(year.value, month.value).toLocaleDateString('en-US', {
        month: 'long',
        year: 'numeric',
    }),
);

const leadingBlanks = computed(() =>
    new Date(year.value, month.value, 1).getDay(),
);

const daysInMonth = computed(() =>
    new Date(year.value, month.value + 1, 0).getDate(),
);

const turn = (by: number) => {
    const next = new Date(year.value, month.value + by);
    month.value = next.getMonth();
    year.value = next.getFullYear();
};

const pick = (day: number) => {
    emit('update:modelValue', isoOf(year.value, month.value, day));
    calendarOpen.value = false;
};

const dayClass = (day: number) => {
    const iso = isoOf(year.value, month.value, day);

    if (iso === props.modelValue) {
        return 'bg-primary text-primary-foreground font-medium';
    }

    return iso === todayIso
        ? 'text-primary font-medium ring-1 ring-primary/40 ring-inset hover:bg-muted'
        : 'hover:bg-muted';
};

const shownDate = computed(() => {
    const parsed = parseDay(props.modelValue);

    return parsed
        ? parsed.toLocaleDateString('en-US', {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
          })
        : props.modelValue;
});

const LINES = {
    email: { input: 'email', href: (v: string) => `mailto:${v}` },
    url: { input: 'url', href: ensureHttp },
    phone: { input: 'tel', href: (v: string) => `tel:${v}` },
} as const;

const line = computed(
    () => LINES[props.column.type as keyof typeof LINES] ?? LINES.url,
);
</script>

<template>
    <Popover
        v-if="column.type === 'date'"
        :open="calendarOpen"
        @update:open="openCalendar"
    >
        <PopoverTrigger as-child>
            <button
                type="button"
                class="flex h-full w-full min-w-0 items-center gap-1 px-2 text-left text-sm tabular-nums"
            >
                <span class="min-w-0 flex-1 truncate">{{ shownDate }}</span>
                <X
                    v-if="modelValue"
                    class="text-muted-foreground hover:text-foreground size-3.5 shrink-0 opacity-0 group-hover/cell:opacity-100"
                    @click.stop="emit('update:modelValue', null)"
                />
            </button>
        </PopoverTrigger>
        <PopoverContent align="start" class="w-64 p-3">
            <div class="mb-2 flex items-center justify-between">
                <button
                    type="button"
                    class="hover:bg-muted rounded-md p-1"
                    title="Last month"
                    @click="turn(-1)"
                >
                    <ChevronLeft class="size-4" />
                </button>
                <span class="text-sm font-medium">{{ monthLabel }}</span>
                <button
                    type="button"
                    class="hover:bg-muted rounded-md p-1"
                    title="Next month"
                    @click="turn(1)"
                >
                    <ChevronRight class="size-4" />
                </button>
            </div>
            <div
                class="text-muted-foreground grid grid-cols-7 gap-1 text-center text-[11px]"
            >
                <span v-for="day in WEEKDAYS" :key="day">{{ day }}</span>
            </div>
            <div class="mt-1 grid grid-cols-7 gap-1 text-center text-sm">
                <span v-for="blank in leadingBlanks" :key="`blank-${blank}`" />
                <button
                    v-for="day in daysInMonth"
                    :key="day"
                    type="button"
                    class="h-8 rounded-md tabular-nums transition-colors"
                    :class="dayClass(day)"
                    @click="pick(day)"
                >
                    {{ day }}
                </button>
            </div>
            <button
                type="button"
                class="text-muted-foreground hover:bg-muted hover:text-foreground mt-2 w-full rounded-md py-1 text-xs"
                @click="
                    emit('update:modelValue', todayIso);
                    calendarOpen = false;
                "
            >
                Today
            </button>
        </PopoverContent>
    </Popover>

    <div v-else class="flex h-full w-full min-w-0 items-center">
        <input
            :type="line.input"
            :value="modelValue ?? ''"
            :class="[
                CELL_INPUT,
                column.type === 'url' ? 'text-primary' : '',
                column.type === 'phone' ? 'tabular-nums' : '',
            ]"
            @input="
                emit(
                    'update:modelValue',
                    ($event.target as HTMLInputElement).value,
                )
            "
        />
        <a
            v-if="modelValue"
            :href="line.href(String(modelValue))"
            target="_blank"
            rel="noopener"
            class="text-muted-foreground hover:text-foreground mr-1.5 shrink-0 rounded p-0.5 opacity-0 group-hover/cell:opacity-100"
            :title="`Open ${modelValue}`"
        >
            <ExternalLink class="size-3.5" />
        </a>
    </div>
</template>
