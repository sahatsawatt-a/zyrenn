<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import type { Weekday } from '@/lib/maps';
import { readHours } from '../hours';
import type { TripStop } from './useTripPlan';

// A stop's own settings: how long to stay, what it costs, a note, and its
// opening hours on that day -- typed in where Google has none.

const props = defineProps<{
    stop: TripStop;
    currency: string;
    /** The day's weekday, for hours typed in. */
    weekday: Weekday | null;
}>();
const emit = defineEmits<{ remove: []; done: [] }>();

const hours = ref(
    props.weekday ? (props.stop.hours?.[props.weekday] ?? '') : '',
);
const hoursError = ref('');

/** Hours for this weekday -- empty takes them away. */
const saveHours = () => {
    const text = hours.value.trim();

    if (!props.weekday) {
        return true;
    }

    if (text && !readHours(text)) {
        hoursError.value =
            'Try "9 AM–5 PM", "11 AM–2 PM, 5–10 PM", "Closed" or "Open 24 hours".';

        return false;
    }

    const kept = { ...props.stop.hours };

    if (text) {
        kept[props.weekday] = text;
    } else {
        delete kept[props.weekday];
    }

    props.stop.hours = Object.keys(kept).length ? kept : null;

    return true;
};

const done = () => {
    if (saveHours()) {
        emit('done');
    }
};

const field =
    'h-8 w-full rounded-md border bg-background px-2 text-sm dark:bg-input/30';
</script>

<template>
    <form class="space-y-3" data-test="stop-form" @submit.prevent="done">
        <div class="truncate text-sm font-medium">{{ stop.name }}</div>
        <div class="grid grid-cols-2 gap-2">
            <label class="text-muted-foreground space-y-1 text-xs">
                Stay (min)
                <input
                    v-model.number="stop.minutes"
                    type="number"
                    min="0"
                    step="15"
                    :class="field"
                    data-test="stop-minutes"
                />
            </label>
            <label
                v-if="!stop.rest"
                class="text-muted-foreground space-y-1 text-xs"
            >
                Cost ({{ currency }})
                <input
                    v-model.number="stop.cost"
                    type="number"
                    min="0"
                    step="any"
                    :class="field"
                    data-test="stop-cost"
                />
            </label>
        </div>
        <label
            v-if="!stop.rest && weekday"
            class="text-muted-foreground block space-y-1 text-xs"
        >
            <span class="capitalize">{{ weekday }}</span> hours
            <input
                v-model="hours"
                :placeholder="
                    stop.hours === undefined
                        ? 'Not looked up -- e.g. 9 AM–5 PM'
                        : 'e.g. 9 AM–5 PM, or Closed'
                "
                :class="field"
                data-test="hours-input"
            />
            <span v-if="hoursError" class="text-destructive block">{{
                hoursError
            }}</span>
        </label>
        <label
            v-if="!stop.rest"
            class="text-muted-foreground block space-y-1 text-xs"
        >
            Note
            <textarea
                v-model="stop.note"
                rows="2"
                class="bg-background dark:bg-input/30 w-full resize-none rounded-md border px-2 py-1.5 text-sm"
                placeholder="Tickets at gate 2, closes early on Sundays…"
            />
        </label>
        <div class="flex items-center gap-2">
            <Button size="sm" type="submit" data-test="stop-done">Done</Button>
            <button
                type="button"
                class="text-muted-foreground hover:text-destructive ml-auto flex items-center gap-1 text-xs"
                data-test="stop-remove"
                @click="emit('remove')"
            >
                <Trash2 class="size-3.5" /> Remove
            </button>
        </div>
    </form>
</template>
