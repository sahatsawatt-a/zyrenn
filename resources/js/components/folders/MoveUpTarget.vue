<script setup lang="ts">
import { CornerLeftUp } from '@lucide/vue';

// Shown while dragging inside a folder: drop here to move up a level
defineProps<{ label: string; active: boolean }>();
</script>

<template>
    <div
        :class="[
            'flex items-center gap-2 rounded-lg border-2 border-dashed px-4 py-3 text-sm transition-colors',
            active
                ? 'border-primary bg-primary/5 text-foreground'
                : 'border-border text-muted-foreground',
        ]"
    >
        <CornerLeftUp class="size-4" />
        Move up to <span class="font-medium">{{ label }}</span>
    </div>
</template>
