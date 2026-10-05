<script setup lang="ts">
import {
    ChevronRight,
    Download,
    Eye,
    EyeOff,
    MoreHorizontal,
    Pencil,
    Plus,
    Trash2,
    Upload,
} from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { SavedPlace } from '@/lib/maps';
import type { PlaceStore } from './usePlaceStore';

// The saved places, by list: show or hide a list on the map, open a place,
// make, rename or delete a list, and take the lot in or out as GeoJSON.

const props = defineProps<{ store: PlaceStore; editable: boolean }>();
const emit = defineEmits<{ open: [place: SavedPlace] }>();

const expanded = ref<Record<string, boolean>>({});
const naming = ref<string | null>(null);
const nameDraft = ref('');
const newList = ref('');
const message = ref('');
const picker = ref<HTMLInputElement>();

const add = async () => {
    const name = newList.value.trim();

    if (name) {
        const list = await props.store.addList(name);

        if (list) {
            expanded.value[list.ref_id] = true;
            newList.value = '';
        }
    }
};

const rename = (ref: string, name: string) => {
    naming.value = ref;
    nameDraft.value = name;
};

const saveName = async () => {
    if (naming.value && nameDraft.value.trim()) {
        await props.store.renameList(naming.value, nameDraft.value.trim());
    }

    naming.value = null;
};

const download = () => {
    const blob = new Blob(
        [JSON.stringify(props.store.exportGeoJson(), null, 2)],
        {
            type: 'application/geo+json',
        },
    );
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'places.geojson';
    link.click();
    URL.revokeObjectURL(link.href);
};

const upload = async (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
        return;
    }

    try {
        const { added, skipped } = await props.store.importGeoJson(
            JSON.parse(await file.text()),
            (done, of) => (message.value = `Adding ${done} of ${of}…`),
        );
        message.value = `Added ${added} place${added === 1 ? '' : 's'}${skipped ? `; left out ${skipped} line${skipped === 1 ? '' : 's'} or area${skipped === 1 ? '' : 's'}` : ''}.`;
    } catch (thrown) {
        message.value =
            thrown instanceof SyntaxError
                ? 'That file is not JSON.'
                : (thrown as Error).message;
    }

    (event.target as HTMLInputElement).value = '';
};
</script>

<template>
    <div class="flex flex-col" data-test="places">
        <div class="flex items-center justify-between px-4 pt-3 pb-1">
            <h2 class="text-sm font-semibold">Your places</h2>
            <span class="text-muted-foreground text-xs"
                >{{ store.places.value.length }} saved</span
            >
        </div>
        <p
            v-if="store.error.value"
            class="bg-destructive/10 text-destructive mx-4 mt-1 rounded-md px-2 py-1 text-xs"
        >
            {{ store.error.value }}
        </p>

        <section
            v-for="list in store.lists.value"
            :key="list.ref_id"
            class="border-b last:border-b-0"
        >
            <div class="group flex items-center gap-2 px-4 py-2">
                <button
                    type="button"
                    class="flex min-w-0 flex-1 items-center gap-2 text-left text-sm"
                    :data-test="`list-${list.name}`"
                    @click="expanded[list.ref_id] = !expanded[list.ref_id]"
                >
                    <ChevronRight
                        class="text-muted-foreground size-3.5 shrink-0 transition-transform"
                        :class="expanded[list.ref_id] && 'rotate-90'"
                    />
                    <span
                        class="size-2.5 shrink-0 rounded-full"
                        :style="{ background: list.color }"
                    />
                    <input
                        v-if="naming === list.ref_id"
                        v-model="nameDraft"
                        class="bg-background min-w-0 flex-1 rounded border px-1 text-sm"
                        aria-label="List name"
                        @click.stop
                        @keydown.enter="saveName"
                        @keydown.escape="naming = null"
                        @blur="saveName"
                    />
                    <span v-else class="truncate font-medium">{{
                        list.name
                    }}</span>
                    <span class="text-muted-foreground text-xs">{{
                        store.placesIn(list.ref_id).length
                    }}</span>
                </button>
                <button
                    type="button"
                    class="text-muted-foreground hover:bg-accent rounded p-1"
                    :aria-label="
                        store.visible(list.ref_id)
                            ? `Hide ${list.name} on the map`
                            : `Show ${list.name} on the map`
                    "
                    :data-test="`toggle-${list.name}`"
                    @click="store.toggle(list.ref_id)"
                >
                    <Eye v-if="store.visible(list.ref_id)" class="size-4" />
                    <EyeOff v-else class="size-4 opacity-60" />
                </button>
                <DropdownMenu v-if="editable">
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="text-muted-foreground hover:bg-accent rounded p-1"
                            :aria-label="`${list.name} options`"
                        >
                            <MoreHorizontal class="size-4" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            @select="rename(list.ref_id, list.name)"
                        >
                            <Pencil class="size-4" /> Rename
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            class="text-destructive"
                            @select="store.removeList(list.ref_id)"
                        >
                            <Trash2 class="size-4" />
                            {{
                                store.placesIn(list.ref_id).length &&
                                store.lists.value.length > 1
                                    ? 'Delete, moving its places'
                                    : 'Delete'
                            }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <ul v-if="expanded[list.ref_id]" class="pb-2">
                <li
                    v-for="place in store.placesIn(list.ref_id)"
                    :key="place.ref_id"
                    class="hover:bg-accent cursor-pointer py-1.5 pr-4 pl-12"
                    @click="emit('open', place)"
                >
                    <div class="truncate text-sm">{{ place.name }}</div>
                    <div
                        v-if="place.note || place.address"
                        class="text-muted-foreground truncate text-xs"
                    >
                        {{ place.note || place.address }}
                    </div>
                </li>
                <li
                    v-if="!store.placesIn(list.ref_id).length"
                    class="text-muted-foreground py-1.5 pr-4 pl-12 text-xs"
                >
                    Nothing here yet -- search or click the map, then Save.
                </li>
            </ul>
        </section>

        <p
            v-if="!store.lists.value.length"
            class="text-muted-foreground px-4 py-3 text-sm"
        >
            Search for a place or click the map, then save it to a list.
        </p>

        <template v-if="editable">
            <form class="flex gap-2 px-4 py-3" @submit.prevent="add">
                <input
                    v-model="newList"
                    placeholder="New list"
                    class="bg-background dark:bg-input/30 h-8 flex-1 rounded-md border px-2 text-sm"
                    data-test="new-list"
                />
                <Button size="sm" variant="outline" type="submit"
                    ><Plus class="size-4" /> Add</Button
                >
            </form>

            <div class="space-y-2 border-t px-4 py-3">
                <div class="flex gap-2">
                    <Button
                        size="sm"
                        variant="ghost"
                        class="flex-1"
                        @click="download"
                    >
                        <Download class="size-4" /> Export GeoJSON
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        class="flex-1"
                        @click="picker?.click()"
                    >
                        <Upload class="size-4" /> Import GeoJSON
                    </Button>
                    <input
                        ref="picker"
                        type="file"
                        accept=".geojson,.json,application/geo+json,application/json"
                        class="hidden"
                        @change="upload"
                    />
                </div>
                <p v-if="message" class="text-muted-foreground text-xs">
                    {{ message }}
                </p>
            </div>
        </template>
    </div>
</template>
