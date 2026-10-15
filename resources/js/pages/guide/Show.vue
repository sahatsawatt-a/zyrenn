<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    BookOpen,
    MousePointerClick,
} from '@lucide/vue';
import type { JSONContent } from '@tiptap/vue-3';
import { computed } from 'vue';
import PageHeader from '@/components/common/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { play } from '@/features/browser/tour';
import { toursFor } from '@/features/browser/tours';
import TiptapEditor from '@/features/notes/components/TiptapEditor.vue';
import { guide } from '@/routes';

// How the app is used: the topics down the side (along the top on a phone),
// and the one being read. A topic is a Markdown file in resources/guide,
// and its parts the files in a folder beside it, listed under it.
// Where there is a tour for it, "Show me" does it in the app, with a cursor
// to follow (features/browser/tours.ts).

type TopicRef = { slug: string; title: string };

const props = defineProps<{
    // A topic can go deeper in parts of its own ("notes/math")
    topics: (TopicRef & { parts: TopicRef[] })[];
    // and a part says which topic it is in
    topic: TopicRef & { doc: JSONContent; in: TopicRef | null };
}>();

// The topic being read, or the one the part being read is in, and its parts
const home = computed(() => props.topic.in?.slug ?? props.topic.slug);
const parts = computed(
    () => props.topics.find(({ slug }) => slug === home.value)?.parts ?? [],
);

// Read in order, each topic and then its parts
const reading = computed(() =>
    props.topics.flatMap(({ parts, ...topic }) => [topic, ...parts]),
);
const at = computed(() =>
    reading.value.findIndex(({ slug }) => slug === props.topic.slug),
);
const before = computed(() => reading.value[at.value - 1] ?? null);
const after = computed(() => reading.value[at.value + 1] ?? null);
const tours = computed(() => toursFor(props.topic.slug));
</script>

<template>
    <Head :title="`${topic.title} · Guide`" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :icon="BookOpen"
            title="Guide"
            description="How each part of the app works."
        />

        <div class="flex flex-col gap-6 md:flex-row md:gap-10">
            <nav
                class="-mx-4 flex shrink-0 gap-1 overflow-x-auto px-4 md:mx-0 md:w-48 md:flex-col md:overflow-visible md:px-0"
                aria-label="Topics"
                data-guide-topics
            >
                <template v-for="each in topics" :key="each.slug">
                    <Link
                        :href="guide(each.slug)"
                        class="rounded-lg px-3 py-2 text-sm whitespace-nowrap"
                        :class="
                            each.slug === topic.slug
                                ? 'bg-accent text-accent-foreground font-medium'
                                : each.slug === home
                                  ? 'text-foreground font-medium'
                                  : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground'
                        "
                        :aria-current="
                            each.slug === topic.slug ? 'page' : undefined
                        "
                    >
                        {{ each.title }}
                    </Link>
                    <!-- Its parts under it, on a wide screen; a phone has them in a row below -->
                    <div
                        v-if="each.slug === home && parts.length"
                        class="mb-1 ml-3 hidden flex-col border-l pl-2 md:flex"
                        data-guide-parts
                    >
                        <Link
                            v-for="part in parts"
                            :key="part.slug"
                            :href="guide(part.slug)"
                            class="rounded-md px-2 py-1 text-[13px]"
                            :class="
                                part.slug === topic.slug
                                    ? 'bg-accent text-accent-foreground font-medium'
                                    : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground'
                            "
                            :aria-current="
                                part.slug === topic.slug ? 'page' : undefined
                            "
                        >
                            {{ part.title }}
                        </Link>
                    </div>
                </template>
            </nav>
            <nav
                v-if="parts.length"
                class="-mx-4 -mt-4 flex gap-1.5 overflow-x-auto px-4 md:hidden"
                aria-label="Parts of this topic"
                data-guide-parts-row
            >
                <Link
                    v-for="part in [
                        { slug: home, title: 'Overview' },
                        ...parts,
                    ]"
                    :key="part.slug"
                    :href="guide(part.slug)"
                    class="rounded-full border px-3 py-1 text-xs whitespace-nowrap"
                    :class="
                        part.slug === topic.slug
                            ? 'bg-accent text-accent-foreground border-transparent font-medium'
                            : 'text-muted-foreground'
                    "
                >
                    {{ part.title }}
                </Link>
            </nav>

            <article class="min-w-0 flex-1" data-guide-topic>
                <Link
                    v-if="topic.in"
                    :href="guide(topic.in.slug)"
                    class="text-muted-foreground hover:text-foreground mb-1 inline-block text-sm"
                >
                    {{ topic.in.title }}
                </Link>
                <h2 class="mb-4 text-2xl font-semibold tracking-tight">
                    {{ topic.title }}
                </h2>
                <ul
                    v-if="tours.length"
                    class="mb-6 flex flex-col gap-2"
                    data-guide-tours
                >
                    <li
                        v-for="tour in tours"
                        :key="tour.id"
                        class="flex flex-wrap items-center gap-x-3 gap-y-1"
                    >
                        <Button
                            variant="outline"
                            size="sm"
                            :data-test="`tour-play-${tour.id}`"
                            @click="play(tour)"
                        >
                            <MousePointerClick />
                            Show me: {{ tour.title }}
                        </Button>
                        <span class="text-muted-foreground text-xs">{{
                            tour.makes ? `Makes ${tour.makes}` : tour.about
                        }}</span>
                    </li>
                </ul>
                <!-- The same reading surface as a chat answer: a note, only read -->
                <TiptapEditor
                    :key="topic.slug"
                    :content="topic.doc"
                    :editable="false"
                    wide
                    class="chat-answer min-h-0! pt-0!"
                />

                <section
                    v-if="!topic.in && parts.length"
                    class="mt-8"
                    data-guide-deeper
                >
                    <h3 class="mb-2 text-sm font-medium">Go deeper</h3>
                    <ul class="grid gap-1 sm:grid-cols-2">
                        <li v-for="part in parts" :key="part.slug">
                            <Link
                                :href="guide(part.slug)"
                                class="hover:bg-accent/50 flex items-center gap-2 rounded-md px-2 py-1.5 text-sm"
                            >
                                <ArrowRight
                                    class="text-muted-foreground size-3.5"
                                />
                                {{ part.title }}
                            </Link>
                        </li>
                    </ul>
                </section>

                <div class="mt-10 flex justify-between gap-4 border-t pt-4">
                    <Link
                        v-if="before"
                        :href="guide(before.slug)"
                        class="text-muted-foreground hover:text-foreground flex items-center gap-2 text-sm"
                    >
                        <ArrowLeft class="size-4" />
                        {{ before.title }}
                    </Link>
                    <span v-else />
                    <Link
                        v-if="after"
                        :href="guide(after.slug)"
                        class="text-muted-foreground hover:text-foreground flex items-center gap-2 text-sm"
                    >
                        {{ after.title }}
                        <ArrowRight class="size-4" />
                    </Link>
                </div>
            </article>
        </div>
    </div>
</template>
