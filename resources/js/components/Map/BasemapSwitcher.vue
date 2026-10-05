<script setup lang="ts">
import { Layers } from '@lucide/vue';
import type { BasemapId } from './maplibre';
import { basemaps } from './maplibre';

// The background maps to choose between, in a corner of the map.

const basemap = defineModel<BasemapId>({ required: true });
</script>

<template>
    <div
        class="bg-background/95 absolute top-3 right-14 z-10 flex items-center gap-0.5 rounded-lg border p-0.5 text-xs shadow-md backdrop-blur"
        data-test="basemaps"
    >
        <Layers class="text-muted-foreground mx-1.5 size-3.5" />
        <button
            v-for="option in basemaps"
            :key="option.id"
            type="button"
            class="rounded-md px-2.5 py-1 transition-colors"
            :class="
                basemap === option.id
                    ? 'bg-primary text-primary-foreground'
                    : 'hover:bg-accent'
            "
            @click="basemap = option.id"
        >
            {{ option.label }}
        </button>
    </div>
</template>
