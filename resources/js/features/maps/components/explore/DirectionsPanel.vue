<script setup lang="ts">
import { ArrowDownUp, LoaderCircle } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import type { Candidate, Route, TravelMode } from '@/lib/maps';
import { findRoute } from '@/lib/maps';
import {
    formatDistance,
    formatDuration,
    travelModes,
} from '@/features/maps/lib/format';
import type { LocalPlace } from '@/components/map/PlaceSearch.vue';
import PlaceSearch from '@/components/map/PlaceSearch.vue';

// From one place to another, by car, bike or on foot, with the turns listed.
// Either end can come from search, a saved place, a click on the map, or the
// device's own position.

const props = defineProps<{
    near: () => { lat: number; lng: number };
    local?: (term: string) => LocalPlace[];
}>();
const from = defineModel<Candidate | null>('from', { required: true });
const to = defineModel<Candidate | null>('to', { required: true });
const emit = defineEmits<{
    route: [route: Route | null];
    focus: [at: [number, number]];
}>();

const mode = ref<TravelMode>('auto');
const fromText = ref(from.value?.name ?? '');
const toText = ref(to.value?.name ?? '');
const found = ref<Route | null>(null);
const loading = ref(false);
const error = ref('');

// The ends can be set from outside (a click on the map), so the boxes follow
watch(from, (place) => (fromText.value = place?.name ?? ''));
watch(to, (place) => (toText.value = place?.name ?? ''));

let pending: AbortController | null = null;

// Where the ends are, not which object holds them: a clicked point gets its
// address a moment later, and that is no reason to ask for the route again.
// A string, because a new array each time would always count as a change
watch(
    () =>
        JSON.stringify([
            from.value?.lat,
            from.value?.lng,
            to.value?.lat,
            to.value?.lng,
            mode.value,
        ]),
    async () => {
        pending?.abort();
        error.value = '';

        if (!from.value || !to.value) {
            found.value = null;
            emit('route', null);

            return;
        }

        loading.value = true;
        pending = new AbortController();

        try {
            found.value = await findRoute(
                [from.value, to.value],
                mode.value,
                pending.signal,
            );
            emit('route', found.value);
        } catch (thrown) {
            if ((thrown as Error).name !== 'AbortError') {
                found.value = null;
                emit('route', null);
                error.value = /No path/i.test((thrown as Error).message)
                    ? `No ${travelModes.find((each) => each.id === mode.value)?.verb} route between these two.`
                    : (thrown as Error).message;
            }
        } finally {
            loading.value = false;
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => pending?.abort());

const swap = () => {
    [from.value, to.value] = [to.value, from.value];
};
</script>

<template>
    <div class="flex h-full flex-col" data-test="directions">
        <div class="space-y-3 border-b p-4">
            <div class="grid grid-cols-3 rounded-lg border p-0.5 text-sm">
                <button
                    v-for="option in travelModes"
                    :key="option.id"
                    type="button"
                    class="flex items-center justify-center gap-1.5 rounded-md py-1.5"
                    :class="
                        mode === option.id
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-accent'
                    "
                    :data-test="`mode-${option.id}`"
                    @click="mode = option.id"
                >
                    <component :is="option.icon" class="size-4" />
                    {{ option.label }}
                </button>
            </div>

            <div class="flex items-center gap-2">
                <div class="flex flex-1 flex-col gap-2">
                    <PlaceSearch
                        v-model="fromText"
                        placeholder="Start -- or click the map"
                        :near="props.near"
                        :local="props.local"
                        my-location
                        data-test="from"
                        @pick="from = $event"
                    />
                    <PlaceSearch
                        v-model="toText"
                        placeholder="Destination -- or click the map"
                        :near="props.near"
                        :local="props.local"
                        my-location
                        data-test="to"
                        @pick="to = $event"
                    />
                </div>
                <button
                    type="button"
                    class="text-muted-foreground hover:bg-accent rounded-md p-2"
                    aria-label="Swap start and destination"
                    @click="swap"
                >
                    <ArrowDownUp class="size-4" />
                </button>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto">
            <p
                v-if="loading"
                class="text-muted-foreground flex items-center gap-2 p-4 text-sm"
            >
                <LoaderCircle class="size-4 animate-spin" /> Finding the way…
            </p>
            <p v-else-if="error" class="text-destructive p-4 text-sm">
                {{ error }}
            </p>
            <p v-else-if="!found" class="text-muted-foreground p-4 text-sm">
                Choose where to start and where to go.
            </p>

            <template v-else>
                <div class="border-b p-4" data-test="route-summary">
                    <div class="text-2xl font-semibold">
                        {{ formatDuration(found.seconds) }}
                    </div>
                    <div class="text-muted-foreground text-sm">
                        {{ formatDistance(found.km) }}
                    </div>
                </div>
                <ol class="text-sm">
                    <li
                        v-for="(step, index) in found.steps"
                        :key="index"
                        class="hover:bg-accent flex cursor-pointer gap-3 border-b px-4 py-2.5"
                        @click="emit('focus', step.at)"
                    >
                        <span
                            class="text-muted-foreground w-5 shrink-0 text-right text-xs tabular-nums"
                            >{{ index + 1 }}</span
                        >
                        <span class="flex-1">{{ step.instruction }}</span>
                        <span
                            v-if="step.km"
                            class="text-muted-foreground shrink-0 text-xs"
                            >{{ formatDistance(step.km) }}</span
                        >
                    </li>
                </ol>
            </template>
        </div>

        <p class="text-muted-foreground border-t px-4 py-2 text-[11px]">
            Routes by Valhalla (FOSSGIS) on OpenStreetMap data.
        </p>
    </div>
</template>
