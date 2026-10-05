<script setup lang="ts">
import { ChevronDown, ChevronUp } from '@lucide/vue';

// The panel over a map-first page: a card floating top-left on a wide
// screen, a sheet from the bottom on a phone. Folded, it keeps only its
// header, and the map has the room.

const folded = defineModel<boolean>('folded', { default: false });
</script>

<template>
    <section
        class="bg-background/95 supports-[backdrop-filter]:bg-background/85 absolute z-20 flex flex-col overflow-hidden border shadow-xl backdrop-blur max-md:inset-x-0 max-md:bottom-0 max-md:rounded-t-2xl md:top-3 md:left-3 md:w-[400px] md:rounded-xl"
        :class="
            folded
                ? 'max-md:max-h-[4.5rem]'
                : 'max-md:max-h-[62%] md:max-h-[calc(100%-1.5rem)]'
        "
        data-test="map-panel"
    >
        <!-- On a phone, the bar to pull it up or down -->
        <button
            type="button"
            class="flex justify-center pt-2 md:hidden"
            aria-label="Fold the panel"
            @click="folded = !folded"
        >
            <span class="bg-muted-foreground/30 h-1.5 w-10 rounded-full" />
        </button>

        <header class="flex shrink-0 items-start gap-2 border-b px-4 py-3">
            <div class="min-w-0 flex-1">
                <slot name="header" />
            </div>
            <button
                type="button"
                class="text-muted-foreground hover:bg-accent hidden rounded-md p-1 md:block"
                :aria-label="folded ? 'Unfold the panel' : 'Fold the panel'"
                data-test="fold-panel"
                @click="folded = !folded"
            >
                <ChevronDown v-if="folded" class="size-4" />
                <ChevronUp v-else class="size-4" />
            </button>
        </header>

        <div v-show="!folded" class="min-h-0 flex-1 overflow-y-auto">
            <slot />
        </div>
    </section>
</template>
