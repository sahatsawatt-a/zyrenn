<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, BookOpen } from '@lucide/vue';
import type { JSONContent } from '@tiptap/vue-3';
import { computed } from 'vue';
import PageHeader from '@/components/common/PageHeader.vue';
import TiptapEditor from '@/features/notes/components/TiptapEditor.vue';
import { guide } from '@/routes';

// How the app is used: the topics down the side (along the top on a phone),
// and the one being read. A topic is a Markdown file in resources/guide.

type TopicRef = { slug: string; title: string };

const props = defineProps<{
    topics: TopicRef[];
    topic: TopicRef & { doc: JSONContent };
}>();

const at = computed(() =>
    props.topics.findIndex(({ slug }) => slug === props.topic.slug),
);
const before = computed(() => props.topics[at.value - 1] ?? null);
const after = computed(() => props.topics[at.value + 1] ?? null);
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
                <Link
                    v-for="each in topics"
                    :key="each.slug"
                    :href="guide(each.slug)"
                    class="rounded-lg px-3 py-2 text-sm whitespace-nowrap"
                    :class="
                        each.slug === topic.slug
                            ? 'bg-accent text-accent-foreground font-medium'
                            : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground'
                    "
                    :aria-current="
                        each.slug === topic.slug ? 'page' : undefined
                    "
                >
                    {{ each.title }}
                </Link>
            </nav>

            <article class="min-w-0 flex-1" data-guide-topic>
                <h2 class="mb-4 text-2xl font-semibold tracking-tight">
                    {{ topic.title }}
                </h2>
                <!-- The same reading surface as a chat answer: a note, only read -->
                <TiptapEditor
                    :key="topic.slug"
                    :content="topic.doc"
                    :editable="false"
                    wide
                    class="chat-answer min-h-0! pt-0!"
                />

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
