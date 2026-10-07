<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Folder } from '@lucide/vue';
import Highlight from '@/components/common/Highlight.vue';
import ItemActions from './ItemActions.vue';

// A folder in a folder view. Drag and drop handlers bound on it fall through to the card.
defineProps<{
    // `path` is only there in search results, which span every folder
    folder: { name: string; path?: string };
    href: InertiaLinkProps['href'];
    query: string;
    over: boolean;
    dragging: boolean;
}>();

defineEmits<{ rename: []; move: []; remove: [] }>();
</script>

<template>
    <div
        class="group bg-card hover:border-primary/40 relative flex items-center rounded-xl border transition-all hover:shadow-sm"
        :class="{
            'border-primary! bg-primary/5 ring-primary/30 ring-2': over,
            'opacity-50': dragging,
        }"
    >
        <Link
            :href="href"
            class="flex min-w-0 flex-1 items-center gap-3 py-3 pl-3"
        >
            <div
                class="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-lg"
            >
                <Folder class="fill-primary/20 size-[18px]" />
            </div>
            <span class="min-w-0">
                <Highlight
                    :text="folder.name"
                    :query="query"
                    class="block truncate text-sm font-medium"
                />
                <span
                    v-if="folder.path?.includes(' / ')"
                    class="text-muted-foreground block truncate text-xs"
                >
                    {{ folder.path.slice(0, folder.path.lastIndexOf(' / ')) }}
                </span>
            </span>
        </Link>

        <ItemActions
            class="mr-2"
            label="Folder actions"
            @rename="$emit('rename')"
            @move="$emit('move')"
            @remove="$emit('remove')"
        />
    </div>
</template>
