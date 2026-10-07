<script setup lang="ts">
import { NodeViewWrapper } from '@tiptap/vue-3';
import type { NodeViewProps } from '@tiptap/vue-3';
import {
    computed,
    defineAsyncComponent,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import type { TripContent } from '@/features/maps/composables/useTripPlan';
import { addDays, shortDate } from '@/features/maps/composables/useTripPlan';
import type { TripSummary, TripTotals } from '@/lib/maps';
import { listTrips, tripContent } from '@/lib/maps';
import { holdPrint } from '@/lib/printReady';
import { show } from '@/routes/trips';

// A trip shown in a note: the whole of it, or one day's timeline.
//
// Kept as a ```trip fence holding two references, like a ```board, so it
// travels through Markdown and an agent can read what the note points at.
// Nothing of the trip is copied in: the note shows the trip as it is today.
const props = defineProps<NodeViewProps>();

// The trip's timeline brings the map's code with it; most notes hold no trip
const TripEmbed = defineAsyncComponent(
    () => import('@/features/maps/components/trips/TripEmbed.vue'),
);

// The PDF printer waits for the day's times to be worked out (see printReady)
const releasePrint = holdPrint();
onBeforeUnmount(releasePrint);

const WHOLE_TRIP = 'all';

const source = computed(() => props.node.textContent);

// One reference a line, either may be empty: the space after the colon must
// not run on past the newline and swallow the next line
const reference = computed(() => ({
    ref: /^[ \t]*ref:[ \t]*(\S+)[ \t]*$/m.exec(source.value)?.[1] ?? '',
    day: /^[ \t]*day:[ \t]*(\S+)[ \t]*$/m.exec(source.value)?.[1] ?? WHOLE_TRIP,
}));

const trips = ref<TripSummary[]>([]);
const trip = ref<{
    ref_id: string;
    title: string;
    content: TripContent;
    revision: number;
    totals: TripTotals;
} | null>(null);
const loading = ref(false);
const problem = ref('');

const days = computed(() =>
    (trip.value?.content.days ?? []).map((day, index) => ({
        id: day.id,
        label: `Day ${index + 1} · ${shortDate(addDays(trip.value!.content.startDate, index))}`,
    })),
);

/** Writes the two references back into the fence, which is what is saved. */
const write = (next: { ref?: string; day?: string }) => {
    const chosen = { ...reference.value, ...next };
    const from = props.getPos();

    if (typeof from !== 'number') {
        return;
    }

    props.editor
        .chain()
        .focus()
        .insertContentAt(
            { from: from + 1, to: from + props.node.nodeSize - 1 },
            `ref: ${chosen.ref}\nday: ${chosen.day}`,
        )
        .run();
};

const load = async (refId: string) => {
    if (!refId) {
        trip.value = null;
        releasePrint();

        return;
    }

    loading.value = true;
    problem.value = '';

    try {
        const found = await tripContent(refId);
        trip.value = { ...found, content: found.content as TripContent };
    } catch {
        trip.value = null;
        problem.value = 'That trip is not there any more, or not yours to see.';
        releasePrint();
    } finally {
        loading.value = false;
    }
};

onMounted(async () => {
    try {
        trips.value = await listTrips();
    } catch {
        // The picker simply stays empty; the trip itself may still load
    }

    await load(reference.value.ref);
});

watch(
    () => reference.value.ref,
    (refId) => void load(refId),
);

const link = computed(() =>
    reference.value.ref ? show.url(reference.value.ref) : '',
);
</script>

<template>
    <NodeViewWrapper class="trip-block" data-test="trip-block">
        <!-- Toolbar: which trip, and which of its days -->
        <div class="trip-toolbar" contenteditable="false">
            <select
                class="trip-select"
                aria-label="Trip"
                data-test="trip-choose"
                :value="reference.ref"
                @change="
                    write({
                        ref: ($event.target as HTMLSelectElement).value,
                        day: WHOLE_TRIP,
                    })
                "
            >
                <option value="" disabled>Choose a trip…</option>
                <option
                    v-for="option in trips"
                    :key="option.ref_id"
                    :value="option.ref_id"
                >
                    {{ option.title || 'Untitled trip' }}
                </option>
            </select>

            <select
                class="trip-select"
                aria-label="Day"
                data-test="day-choose"
                :value="reference.day"
                :disabled="!days.length"
                @change="
                    write({ day: ($event.target as HTMLSelectElement).value })
                "
            >
                <option :value="WHOLE_TRIP">Whole trip</option>
                <option v-for="each in days" :key="each.id" :value="each.id">
                    {{ each.label }}
                </option>
            </select>

            <a
                v-if="link"
                class="trip-open"
                :href="link"
                target="_blank"
                rel="noopener"
                >Open</a
            >
        </div>

        <div class="trip-stage" contenteditable="false">
            <p v-if="problem" class="trip-message">{{ problem }}</p>
            <p v-else-if="loading" class="trip-message">Loading…</p>
            <p v-else-if="!reference.ref" class="trip-message">
                Choose a trip to show it here.
            </p>
            <TripEmbed
                v-else-if="trip"
                :key="`${trip.ref_id}:${trip.revision}`"
                :trip="trip"
                :totals="trip.totals"
                :day="reference.day"
                @ready="releasePrint"
            />
        </div>

        <!-- The fence's own text: the two references, kept out of sight -->
        <pre class="trip-source"><code /></pre>
    </NodeViewWrapper>
</template>

<style scoped>
.trip-block {
    margin: 1rem 0;
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background-color: var(--card);
}

.trip-toolbar {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.375rem 0.5rem;
    border-bottom: 1px solid var(--border);
    background-color: var(--muted);
}

.trip-select {
    max-width: 16rem;
    flex: 1;
    height: 1.75rem;
    padding: 0 0.375rem;
    font-size: 0.75rem;
    color: var(--foreground);
    background-color: var(--background);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
}
.trip-select:disabled {
    opacity: 0.6;
    cursor: default;
}

.trip-open {
    margin-left: auto;
    padding: 0 0.5rem;
    font-size: 0.75rem;
    color: var(--muted-foreground);
    text-decoration: none;
}
.trip-open:hover {
    color: var(--foreground);
}

.trip-message {
    padding: 2rem 1rem;
    text-align: center;
    font-size: 0.8125rem;
    color: var(--muted-foreground);
}

/*
 * The note's own lists (typography.css) number and indent every ol and li,
 * !important, and the timeline is lists. Inside the block, hand each of
 * those properties back to the timeline's own classes: revert-layer rolls
 * back to the Tailwind utilities beneath these unlayered rules.
 */
.trip-stage :deep(ol),
.trip-stage :deep(ul),
.trip-stage :deep(li) {
    display: revert-layer !important;
    list-style: revert-layer !important;
    padding-left: revert-layer !important;
    margin: revert-layer !important;
    line-height: revert-layer;
    color: revert-layer;
}

/* The fence still holds the references; nobody needs to read them here */
.trip-source {
    display: none;
}
</style>
