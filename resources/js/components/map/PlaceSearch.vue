<script setup lang="ts">
import { LocateFixed, LoaderCircle, MapPin, Search, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import IconByName from '@/components/icons/IconByName.vue';
import type { Candidate, Hit } from '@/lib/maps';
import { searchPlaces } from '@/lib/maps';

// A place search with suggestions as you type: the page's own places first
// (saved ones, say), then what Photon finds nearest the map -- asked through
// our server. Used wherever a place is chosen.

/** One of the page's own places a search can offer. */
export interface LocalPlace extends Candidate {
    detail: string;
    color?: string;
    /** The icon of the list it is saved in. */
    listIcon?: string;
}

const props = defineProps<{
    placeholder: string;
    /** Where "near" is -- the middle of the map, asked for when needed. */
    near: () => { lat: number; lng: number };
    /** The page's own places matching what is typed. */
    local?: (term: string) => LocalPlace[];
    /** Offer the browser's own position as the first choice. */
    myLocation?: boolean;
    autofocus?: boolean;
}>();

const emit = defineEmits<{ pick: [place: Candidate] }>();
const text = defineModel<string>({ default: '' });

const open = ref(false);
const active = ref(0);
const loading = ref(false);
const error = ref('');
const hits = ref<Hit[]>([]);

type Option = {
    key: string;
    title: string;
    detail: string;
    icon: 'saved' | 'place' | 'me';
    color?: string;
    listIcon?: string;
    pick: () => Promise<Candidate | null> | Candidate | null;
};

const locate = () =>
    new Promise<Candidate | null>((resolve) => {
        navigator.geolocation.getCurrentPosition(
            ({ coords }) =>
                resolve({
                    name: 'Your location',
                    address: '',
                    kind: 'you',
                    lat: coords.latitude,
                    lng: coords.longitude,
                }),
            () => {
                error.value = 'Your browser would not share your location.';
                resolve(null);
            },
            { enableHighAccuracy: true, timeout: 10000 },
        );
    });

const options = computed<Option[]>(() => {
    const term = text.value.trim();
    const list: Option[] = [];

    if (props.myLocation && !term) {
        list.push({
            key: 'me',
            title: 'Your location',
            detail: 'Where this device is',
            icon: 'me',
            pick: locate,
        });
    }

    if (term && props.local) {
        for (const [index, place] of props.local(term).slice(0, 3).entries()) {
            list.push({
                key: `local-${index}`,
                title: place.name,
                detail: place.detail,
                icon: 'saved',
                color: place.color,
                listIcon: place.listIcon,
                pick: () => place,
            });
        }
    }

    hits.value.forEach((hit, index) =>
        list.push({
            key: `hit-${index}`,
            title: hit.name,
            detail: hit.address,
            icon: 'place',
            pick: () => ({
                name: hit.name,
                address: hit.address,
                kind: hit.kind,
                lat: hit.lat,
                lng: hit.lng,
            }),
        }),
    );

    return list;
});

// One request at a time: a newer keystroke cancels the one before. Only
// typing searches -- not the box being filled in with a place's name
let pending: AbortController | null = null;
let timer: ReturnType<typeof setTimeout> | undefined;

const typed = () => {
    const value = text.value;
    open.value = true;
    clearTimeout(timer);
    pending?.abort();
    error.value = '';
    active.value = 0;

    if (value.trim().length < 2) {
        hits.value = [];
        loading.value = false;

        return;
    }

    loading.value = true;
    timer = setTimeout(async () => {
        pending = new AbortController();

        try {
            hits.value = await searchPlaces(
                value,
                props.near(),
                pending.signal,
            );
        } catch (thrown) {
            if ((thrown as Error).name !== 'AbortError') {
                error.value = 'Search is not answering -- try again.';
            }
        } finally {
            loading.value = false;
        }
    }, 250);
};

const choose = async (option: Option | undefined) => {
    if (!option) {
        return;
    }

    const place = await option.pick();
    open.value = false;

    if (place) {
        text.value = place.name;
        emit('pick', place);
    }
};

const key = (event: KeyboardEvent) => {
    if (event.key === 'ArrowDown') {
        active.value = Math.min(active.value + 1, options.value.length - 1);
        open.value = true;
    } else if (event.key === 'ArrowUp') {
        active.value = Math.max(active.value - 1, 0);
    } else if (event.key === 'Enter') {
        void choose(options.value[active.value]);
    } else if (event.key === 'Escape') {
        open.value = false;

        return;
    } else {
        return;
    }

    event.preventDefault();
};
</script>

<template>
    <div class="relative">
        <Search
            class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
        />
        <input
            v-model="text"
            type="text"
            :placeholder="placeholder"
            :autofocus="autofocus"
            class="bg-background placeholder:text-muted-foreground focus-visible:ring-ring/50 dark:bg-input/30 h-10 w-full rounded-lg border pr-9 pl-9 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
            data-test="place-search"
            @focus="open = true"
            @input="typed"
            @blur="open = false"
            @keydown="key"
        />
        <LoaderCircle
            v-if="loading"
            class="text-muted-foreground absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin"
        />
        <button
            v-else-if="text"
            type="button"
            class="text-muted-foreground hover:bg-accent absolute top-1/2 right-2 -translate-y-1/2 rounded p-1"
            aria-label="Clear"
            @mousedown.prevent="
                text = '';
                hits = [];
            "
        >
            <X class="size-3.5" />
        </button>

        <ul
            v-if="open && (options.length || error)"
            class="bg-popover absolute inset-x-0 top-full z-30 mt-1 max-h-80 overflow-y-auto rounded-lg border py-1 text-sm shadow-lg"
            data-test="place-suggestions"
        >
            <li v-if="error" class="text-destructive px-3 py-2 text-xs">
                {{ error }}
            </li>
            <li
                v-for="(option, index) in options"
                :key="option.key"
                class="flex cursor-pointer items-start gap-2.5 px-3 py-2"
                :class="index === active && 'bg-accent'"
                @mouseenter="active = index"
                @mousedown.prevent="choose(option)"
            >
                <LocateFixed
                    v-if="option.icon === 'me'"
                    class="text-primary mt-0.5 size-4 shrink-0"
                />
                <IconByName
                    v-else-if="option.icon === 'saved'"
                    :name="option.listIcon"
                    class="mt-0.5 size-4 shrink-0"
                    :style="{ color: option.color }"
                />
                <MapPin
                    v-else
                    class="text-muted-foreground mt-0.5 size-4 shrink-0"
                />
                <span class="min-w-0">
                    <span class="block truncate font-medium">{{
                        option.title
                    }}</span>
                    <span
                        v-if="option.detail"
                        class="text-muted-foreground block truncate text-xs"
                    >
                        {{ option.detail }}
                    </span>
                </span>
            </li>
        </ul>
    </div>
</template>
