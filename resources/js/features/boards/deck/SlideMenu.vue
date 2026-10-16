<script setup lang="ts">
import { GalleryVerticalEnd } from '@lucide/vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Button } from '@/components/ui/button';
import type { SlideLayout } from '@/features/boards/deck/deck';
import { SLIDE_LAYOUTS } from '@/features/boards/deck/deck';

// A new slide, laid out: each layout drawn small, as boxes where its words
// and picture go, so it is chosen by how it looks.
defineProps<{ compact?: boolean }>();
const emit = defineEmits<{ add: [layout: SlideLayout] }>();

// Titles are drawn as a bar, words as the block they fill, a picture as a
// dashed box: what each part of a 960 x 540 slide is, in the preview's 48 x 27
const LOUD = ['title', 'heading', 'big'];

const drawn = (part: SlideLayout['parts'][number]) => {
    const loud = LOUD.includes(part.role);
    const picture = part.role === 'picture';

    return {
        x: (part.x / 960) * 48,
        y: (part.y / 540) * 27,
        width: (part.width / 960) * 48,
        height: loud
            ? Math.min(4, (part.height / 540) * 27)
            : Math.max(1.5, (part.height / 540) * 27),
        rx: 0.6,
        fill: picture ? 'none' : 'currentColor',
        stroke: picture ? 'currentColor' : 'none',
        'stroke-width': 0.6,
        'stroke-dasharray': '1.5 1',
        opacity: loud ? 0.85 : picture ? 0.7 : 0.25,
    };
};
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="sm"
                title="A new slide after the last, laid out: a title, two columns, a big number…"
                aria-label="New slide"
                data-test="new-slide"
            >
                <GalleryVerticalEnd />
                <span v-if="!compact">New slide</span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-60">
            <DropdownMenuLabel>New slide</DropdownMenuLabel>
            <DropdownMenuItem
                v-for="layout in SLIDE_LAYOUTS"
                :key="layout.id"
                :title="layout.about"
                :data-test="`slide-layout-${layout.id}`"
                @select="emit('add', layout)"
            >
                <span
                    class="text-muted-foreground h-[27px] w-12 shrink-0 overflow-hidden rounded-sm border"
                >
                    <!-- "size-" keeps the menu from sizing it as an icon -->
                    <svg
                        viewBox="0 0 48 27"
                        class="size-full"
                        aria-hidden="true"
                    >
                        <rect
                            v-for="part in layout.parts"
                            :key="part.slot"
                            v-bind="drawn(part)"
                        />
                    </svg>
                </span>
                {{ layout.label }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
