<script setup lang="ts">
import {
    Bookmark,
    Check,
    Copy,
    ExternalLink,
    Globe,
    LoaderCircle,
    Navigation,
    Phone,
    Sparkles,
    Star,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import IconByName from '@/components/icons/IconByName.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { Candidate, PlaceInfo } from '@/lib/maps';
import { placeInfo } from '@/lib/maps';
import { readHours, WEEKDAYS } from '@/features/maps/lib/hours';
import type { PlaceStore } from '@/features/maps/composables/usePlaceStore';

// One place, however it was found. Not saved: save it to a list. Saved:
// rename it, note it, move it to another list, or forget it. Either way,
// Google's hours and rating when asked for -- and kept, once saved.

const props = defineProps<{
    place: Candidate;
    store: PlaceStore;
    /** A project's viewers look, and change nothing. */
    editable: boolean;
}>();
const emit = defineEmits<{
    close: [];
    directionsTo: [place: Candidate];
    directionsFrom: [place: Candidate];
    saved: [place: Candidate];
}>();

const saved = computed(() =>
    props.store.places.value.find(
        (place) => place.ref_id === props.place.savedId,
    ),
);
const list = computed(() =>
    saved.value ? props.store.listOf(saved.value) : null,
);

// Google's details: a saved place keeps them; anything else is asked for
const asked = ref<PlaceInfo | null>(null);
const info = computed(() => saved.value?.details ?? asked.value);
const looking = ref(false);
const infoError = ref('');
const copied = ref(false);

// A new place, not the same one with its address just arrived
watch(
    // A string: a new array each time would always count as a change
    () => `${props.place.lat},${props.place.lng},${props.place.savedId ?? ''}`,
    () => {
        asked.value = null;
        infoError.value = '';
        copied.value = false;
    },
);

const lookUp = async () => {
    looking.value = true;
    infoError.value = '';

    try {
        asked.value = await placeInfo(props.place);

        if (saved.value && props.editable) {
            await props.store.update(saved.value.ref_id, {
                details: asked.value,
            });
        }
    } catch (thrown) {
        infoError.value = (thrown as Error).message;
    } finally {
        looking.value = false;
    }
};

const keep = async (into?: string) => {
    const place = await props.store.save(props.place, into, asked.value);

    if (place) {
        emit('saved', { ...props.place, savedId: place.ref_id });
    }
};

const forget = async () => {
    if (saved.value) {
        const ref = saved.value.ref_id;
        emit('saved', { ...props.place, savedId: undefined });
        await props.store.remove(ref);
    }
};

const coordinates = computed(
    () => `${props.place.lat.toFixed(6)}, ${props.place.lng.toFixed(6)}`,
);

const copy = async () => {
    try {
        await navigator.clipboard.writeText(coordinates.value);
        copied.value = true;
    } catch {
        // clipboard refused; the numbers are on screen to select by hand
    }
};

// "amenity/place_of_worship" reads as "place of worship"
const kind = computed(() => {
    const [, what] = (props.place.kind || '').split('/');

    return (
        info.value?.type ||
        (what && what !== 'yes' ? what.replaceAll('_', ' ') : '')
    );
});

const today = WEEKDAYS[(new Date().getDay() + 6) % 7];
</script>

<template>
    <div class="space-y-4 p-4" data-test="place-card">
        <div class="flex items-start gap-2">
            <div class="min-w-0 flex-1">
                <input
                    v-if="saved && editable"
                    :value="saved.name"
                    class="hover:bg-accent/60 focus:bg-accent -mx-1 w-full rounded px-1 text-lg leading-tight font-semibold outline-none"
                    aria-label="Name"
                    @change="
                        store.update(saved.ref_id, {
                            name: ($event.target as HTMLInputElement).value,
                        })
                    "
                />
                <h2 v-else class="text-lg leading-tight font-semibold">
                    {{ place.name }}
                </h2>
                <p
                    v-if="kind"
                    class="text-muted-foreground mt-0.5 text-sm capitalize"
                >
                    {{ kind }}
                </p>
            </div>
            <button
                type="button"
                class="text-muted-foreground hover:bg-accent rounded-md p-1"
                aria-label="Close"
                @click="emit('close')"
            >
                <X class="size-4" />
            </button>
        </div>

        <div class="flex flex-wrap gap-2">
            <Button
                size="sm"
                data-test="directions-to"
                @click="emit('directionsTo', place)"
            >
                <Navigation class="size-4" /> Directions
            </Button>
            <Button
                size="sm"
                variant="outline"
                @click="emit('directionsFrom', place)"
                >Start here</Button
            >

            <DropdownMenu v-if="editable">
                <DropdownMenuTrigger as-child>
                    <Button
                        size="sm"
                        variant="outline"
                        :data-test="saved ? 'saved-in' : 'save-place'"
                    >
                        <IconByName
                            v-if="saved"
                            :name="list?.icon"
                            class="size-4"
                            :style="{ color: list?.color }"
                        />
                        <Bookmark v-else class="size-4" />
                        {{ saved ? list?.name : 'Save' }}
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" class="w-52">
                    <DropdownMenuLabel>{{
                        saved ? 'Move to' : 'Save to'
                    }}</DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="each in store.lists.value"
                        :key="each.ref_id"
                        :data-test="`list-option-${each.name}`"
                        @select="
                            saved
                                ? store.update(saved.ref_id, {
                                      list: each.ref_id,
                                  })
                                : keep(each.ref_id)
                        "
                    >
                        <IconByName
                            :name="each.icon"
                            class="size-4"
                            :style="{ color: each.color }"
                        />
                        {{ each.name }}
                        <Check
                            v-if="saved?.list === each.ref_id"
                            class="ml-auto size-4"
                        />
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="!store.lists.value.length"
                        @select="keep()"
                    >
                        <Bookmark class="size-4" /> Saved
                    </DropdownMenuItem>
                    <template v-if="saved">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            class="text-destructive"
                            data-test="forget-place"
                            @select="forget"
                        >
                            <Trash2 class="size-4" /> Remove from saved
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <!-- Google's details: hours, rating, photo -->
        <section
            class="space-y-3 rounded-lg border p-3 text-sm"
            data-test="place-info"
        >
            <button
                v-if="!info && !looking"
                type="button"
                class="text-primary flex items-center gap-2 hover:underline"
                data-test="look-up"
                @click="lookUp"
            >
                <Sparkles class="size-4" /> Opening hours &amp; rating
                <span class="text-muted-foreground text-xs">from Google</span>
            </button>
            <p
                v-if="looking"
                class="text-muted-foreground flex items-center gap-2 text-xs"
            >
                <LoaderCircle class="size-3.5 animate-spin" /> Asking Google…
            </p>
            <p v-if="infoError" class="text-destructive text-xs">
                {{ infoError }}
            </p>

            <p v-if="info && !info.found" class="text-muted-foreground text-xs">
                Google has nothing by this name within 400 m of here.
            </p>
            <template v-else-if="info">
                <div class="flex gap-3">
                    <img
                        v-if="info.thumbnail"
                        :src="info.thumbnail"
                        alt=""
                        class="size-16 shrink-0 rounded-md object-cover"
                        referrerpolicy="no-referrer"
                    />
                    <div class="min-w-0 space-y-1">
                        <div
                            v-if="info.rating"
                            class="flex items-center gap-1"
                            data-test="rating"
                        >
                            <Star
                                class="size-4 fill-amber-400 text-amber-400"
                            />
                            <span class="font-semibold">{{ info.rating }}</span>
                            <span class="text-muted-foreground text-xs">
                                ({{
                                    (info.reviews ?? 0).toLocaleString()
                                }}
                                reviews){{
                                    info.price ? ` · ${info.price}` : ''
                                }}
                            </span>
                        </div>
                        <p
                            v-if="info.description"
                            class="text-muted-foreground text-xs"
                        >
                            {{ info.description }}
                        </p>
                    </div>
                </div>

                <table
                    v-if="info.hours"
                    class="w-full text-xs"
                    data-test="hours"
                >
                    <tr
                        v-for="weekday in WEEKDAYS"
                        :key="weekday"
                        :class="weekday === today && 'font-semibold'"
                    >
                        <td class="w-12 py-0.5 capitalize">
                            {{ weekday.slice(0, 3) }}
                        </td>
                        <td
                            :class="
                                readHours(info.hours[weekday])?.kind ===
                                    'closed' && 'text-destructive'
                            "
                        >
                            {{ info.hours[weekday] ?? '–' }}
                        </td>
                    </tr>
                </table>
                <p v-else class="text-muted-foreground text-xs">
                    No opening hours listed.
                </p>

                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
                    <a
                        v-if="info.phone"
                        :href="`tel:${info.phone}`"
                        class="text-primary flex items-center gap-1 hover:underline"
                    >
                        <Phone class="size-3.5" /> {{ info.phone }}
                    </a>
                    <a
                        v-if="info.website"
                        :href="info.website"
                        target="_blank"
                        rel="noopener"
                        class="text-primary flex items-center gap-1 hover:underline"
                    >
                        <Globe class="size-3.5" /> Website
                    </a>
                </div>
            </template>
        </section>

        <label v-if="saved" class="block space-y-1">
            <span class="text-muted-foreground text-xs">Note</span>
            <textarea
                :value="saved.note"
                rows="3"
                :readonly="!editable"
                class="bg-background focus-visible:ring-ring/50 dark:bg-input/30 w-full resize-none rounded-md border px-2.5 py-2 text-sm outline-none focus-visible:ring-[3px]"
                placeholder="Opening hours, who to ask for, the side entrance…"
                @change="
                    store.update(saved.ref_id, {
                        note: ($event.target as HTMLTextAreaElement).value,
                    })
                "
            />
        </label>

        <dl class="space-y-2 text-sm">
            <div v-if="place.address">
                <dt class="text-muted-foreground text-xs">Address</dt>
                <dd>{{ place.address }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground text-xs">Coordinates</dt>
                <dd class="flex items-center gap-2 font-mono text-xs">
                    {{ coordinates }}
                    <button
                        type="button"
                        class="text-primary flex items-center gap-1 hover:underline"
                        @click="copy"
                    >
                        <Check v-if="copied" class="size-3.5" />
                        <Copy v-else class="size-3.5" />
                        {{ copied ? 'Copied' : 'Copy' }}
                    </button>
                </dd>
            </div>
        </dl>

        <a
            class="text-muted-foreground flex items-center gap-1 text-xs hover:underline"
            :href="`https://www.openstreetmap.org/?mlat=${place.lat}&mlon=${place.lng}#map=18/${place.lat}/${place.lng}`"
            target="_blank"
            rel="noopener"
        >
            Open in OpenStreetMap <ExternalLink class="size-3" />
        </a>
    </div>
</template>
