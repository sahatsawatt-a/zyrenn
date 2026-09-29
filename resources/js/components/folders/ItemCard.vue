<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Folder } from '@lucide/vue';
import type { Component } from 'vue';
import Highlight from '@/components/folders/Highlight.vue';
import ItemActions from '@/components/folders/ItemActions.vue';
import { formatRelativeTime } from '@/lib/utils';

// A note, board or table in a folder view, as a card in the grid or a row in
// the list. Drag handlers bound on it fall through to the card.
defineProps<{
    layout: 'grid' | 'list';
    href: InertiaLinkProps['href'];
    icon: Component;
    title: string;
    query: string;
    // Only in search results, which span every folder
    path?: string | null;
    snippet?: string | null;
    searching: boolean;
    rootLabel: string;
    time: string;
    timeLabel: string;
    dragging: boolean;
}>();

defineEmits<{ rename: []; move: []; remove: [] }>();
</script>

<template>
    <div
        class="group bg-card relative transition-all"
        :class="[
            layout === 'grid'
                ? 'hover:border-primary/40 flex flex-col rounded-xl border hover:shadow-sm'
                : 'hover:bg-muted/50 flex items-center',
            { 'opacity-50': dragging },
        ]"
    >
        <Link
            :href="href"
            class="flex min-w-0 flex-1"
            :class="
                layout === 'grid'
                    ? 'flex-col gap-3 p-4'
                    : 'items-center gap-3 py-2.5 pl-4'
            "
        >
            <div
                class="bg-muted text-muted-foreground group-hover:bg-primary/10 group-hover:text-primary flex shrink-0 items-center justify-center rounded-lg transition-colors"
                :class="layout === 'grid' ? 'size-10' : 'size-8'"
            >
                <component
                    :is="icon"
                    :class="layout === 'grid' ? 'size-5' : 'size-4'"
                />
            </div>

            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                <Highlight
                    :text="title || 'Untitled'"
                    :query="query"
                    class="truncate font-medium"
                    :class="{ 'text-muted-foreground': !title }"
                />
                <span
                    v-if="searching"
                    class="text-muted-foreground truncate text-xs"
                >
                    <Folder class="mr-1 inline size-3 align-[-2px]" />{{
                        path ?? rootLabel
                    }}
                </span>
                <Highlight
                    v-if="snippet"
                    :text="snippet"
                    :query="query"
                    class="text-muted-foreground line-clamp-2 text-sm"
                />
                <span
                    class="text-muted-foreground flex items-center gap-1.5 text-xs"
                    :class="{ 'mt-auto pt-1': layout === 'grid' }"
                >
                    <slot />
                    <span v-if="$slots.default" aria-hidden="true">·</span>
                    <time :datetime="time" :title="timeLabel">
                        {{ formatRelativeTime(time) }}
                    </time>
                </span>
            </span>
        </Link>

        <ItemActions
            :class="layout === 'grid' ? 'absolute top-3 right-3' : 'mx-2'"
            label="Actions"
            @rename="$emit('rename')"
            @move="$emit('move')"
            @remove="$emit('remove')"
        />
    </div>
</template>
