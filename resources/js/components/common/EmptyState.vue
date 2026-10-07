<script setup lang="ts">
import type { Component } from 'vue';

// What a folder view shows when it has nothing to list
defineProps<{ icon: Component; title: string }>();
</script>

<template>
    <div
        class="flex flex-col items-center gap-2 rounded-2xl border border-dashed px-6 py-16 text-center"
    >
        <div
            class="bg-muted text-muted-foreground mb-2 flex size-14 items-center justify-center rounded-full"
        >
            <component :is="icon" class="size-6" />
        </div>
        <p class="font-medium">{{ title }}</p>
        <slot />
    </div>
</template>
