<script setup lang="ts">
import { Image, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Look, Thing } from './steps';

// A page as the browser found it: a picture of it with each field, button,
// link and piece of writing marked, and the same listed by the name a step
// goes by, to be found by a few letters. Choosing one -- on the picture or in
// the list -- is what it is for (picking a step's thing, building a check by
// clicking). `kinds` says which can be chosen.

const props = defineProps<{
    look: Look;
    kinds: Thing['kind'][];
    // The one being worked on, kept outlined: the field being typed into
    marked?: Thing | null;
}>();

// The same thing on a page looked at again, by what it is and is called
const isMarked = (thing: Thing) =>
    !!props.marked &&
    thing.kind === props.marked.kind &&
    thing.name === props.marked.name;
const emit = defineEmits<{ pick: [thing: Thing] }>();

const GROUPS: { title: string; kinds: Thing['kind'][] }[] = [
    { title: 'Fields', kinds: ['field', 'list', 'file'] },
    {
        title: 'Buttons and links',
        kinds: ['button', 'link', 'tab', 'checkbox', 'radio'],
    },
    { title: 'Pictures', kinds: ['picture'] },
    { title: 'Writing', kinds: ['words'] },
];
const KIND_SAID: Record<Thing['kind'], string> = {
    field: 'field',
    button: 'button',
    link: 'link',
    tab: 'tab',
    checkbox: 'tick box',
    radio: 'choice',
    picture: 'picture',
    list: 'list',
    file: 'file',
    words: 'words',
};

const filter = ref('');
const hovered = ref<Thing | null>(null);
// On a phone the list comes first, the picture on a click
const pictureShown = ref(false);

// A new page: nothing chosen on it yet
watch(
    () => props.look,
    () => {
        filter.value = '';
        hovered.value = null;
    },
);

const fits = computed(() =>
    props.look.things.filter(
        (thing) =>
            props.kinds.includes(thing.kind) &&
            thing.name
                .toLowerCase()
                .includes(filter.value.trim().toLowerCase()),
    ),
);
// Further down than the picture goes: listed, but not marked on it
const lower = (thing: Thing) => thing.box.y >= props.look.size.height;
const drawn = computed(() => fits.value.filter((thing) => !lower(thing)));
const groups = computed(() =>
    [
        ...GROUPS.map((group) => ({
            ...group,
            things: drawn.value.filter((thing) =>
                group.kinds.includes(thing.kind),
            ),
        })),
        {
            title: 'Further down the page',
            kinds: [],
            things: fits.value.filter(lower),
        },
    ].filter((group) => group.things.length),
);

// Where a step could not be done, the page is as it was before it
const stopped = computed(() => {
    const at = props.look.steps.findIndex((step) => !step.ok);

    return at === -1
        ? null
        : { at: at + 1, why: props.look.steps[at].error ?? '' };
});

const place = (thing: Thing) => {
    const { width, height } = props.look.size;

    return {
        left: `${(thing.box.x / width) * 100}%`,
        top: `${(thing.box.y / height) * 100}%`,
        width: `${(thing.box.w / width) * 100}%`,
        height: `${(thing.box.h / height) * 100}%`,
    };
};
</script>

<template>
    <div class="flex min-h-0 flex-col gap-3">
        <p
            v-if="stopped"
            class="flex items-start gap-2 rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
            Step {{ stopped.at }} could not be done ({{ stopped.why }}), so this
            is the page as it was before it.
        </p>

        <!-- A height of its own, each side scrolling within it, so nothing on
             a long page runs past the dialog it is in -->
        <div
            class="grid min-h-0 flex-1 gap-3 md:h-[58vh] md:flex-none md:grid-cols-[1fr_18rem] md:grid-rows-[minmax(0,1fr)]"
        >
            <!-- The page -->
            <div
                class="min-h-0 overflow-auto rounded-lg border"
                :class="pictureShown ? 'block' : 'hidden md:block'"
            >
                <!-- No wider than the page was drawn: a phone's page is shown at
                     a phone's width, not stretched -->
                <div
                    v-if="look.shot"
                    class="relative mx-auto"
                    :style="{ width: `min(100%, ${look.size.width}px)` }"
                >
                    <img
                        :src="look.shot"
                        :alt="`The page: ${look.title}`"
                        class="block w-full"
                        data-test="pick-picture"
                    />
                    <button
                        v-for="(thing, index) in drawn"
                        :key="index"
                        type="button"
                        class="absolute rounded-sm border-2 transition-colors"
                        :class="
                            hovered === thing || isMarked(thing)
                                ? 'border-sky-500 bg-sky-500/25'
                                : 'border-sky-500/30 hover:border-sky-500 hover:bg-sky-500/20'
                        "
                        :style="place(thing)"
                        :aria-label="`${KIND_SAID[thing.kind]} ${thing.name}`"
                        :title="thing.name"
                        @mouseenter="hovered = thing"
                        @mouseleave="hovered = null"
                        @click="emit('pick', thing)"
                    />
                </div>
            </div>

            <!-- What is on it -->
            <div class="flex min-h-0 flex-col gap-2">
                <div class="flex gap-2">
                    <Input
                        v-model="filter"
                        placeholder="Find"
                        aria-label="Find on the page"
                        class="h-8"
                        data-test="pick-filter"
                    />
                    <Button
                        variant="outline"
                        size="icon-sm"
                        class="md:hidden"
                        :aria-label="
                            pictureShown ? 'Hide the page' : 'Show the page'
                        "
                        @click="pictureShown = !pictureShown"
                    >
                        <Image />
                    </Button>
                </div>
                <div
                    class="max-h-[50vh] min-h-0 flex-1 space-y-3 overflow-y-auto md:max-h-none"
                    data-test="pick-list"
                >
                    <div v-for="group in groups" :key="group.title">
                        <h3
                            class="text-muted-foreground mb-1 text-xs font-medium"
                        >
                            {{ group.title }}
                        </h3>
                        <ul class="space-y-0.5">
                            <li
                                v-for="(thing, index) in group.things"
                                :key="index"
                            >
                                <button
                                    type="button"
                                    class="hover:bg-accent flex w-full items-baseline gap-2 rounded px-2 py-1 text-left text-sm"
                                    :class="{
                                        'bg-accent': hovered === thing,
                                    }"
                                    data-test="pick-thing"
                                    @mouseenter="hovered = thing"
                                    @mouseleave="hovered = null"
                                    @click="emit('pick', thing)"
                                >
                                    <span class="min-w-0 flex-1">{{
                                        thing.name
                                    }}</span>
                                    <span
                                        class="text-muted-foreground shrink-0 text-[11px]"
                                        :title="
                                            thing.count > 1
                                                ? `${thing.count} share this name: this is the one at number ${thing.nth}`
                                                : undefined
                                        "
                                        >{{ KIND_SAID[thing.kind]
                                        }}{{ thing.frame ? ' · in a frame' : ''
                                        }}{{
                                            thing.count > 1
                                                ? ` · ${thing.nth} of ${thing.count}`
                                                : ''
                                        }}</span
                                    >
                                </button>
                            </li>
                        </ul>
                    </div>
                    <p
                        v-if="!groups.length"
                        class="text-muted-foreground text-sm"
                    >
                        Nothing on the page fits.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
