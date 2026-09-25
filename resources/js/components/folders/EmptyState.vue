<script setup lang="ts">
import type { Component } from 'vue';

// The dashed box a folder view shows when it has nothing to list
defineProps<{ icon: Component; title: string }>();
</script>

<template>
    <div
        class="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center gap-3 rounded-xl border border-dashed px-6 py-16 text-center"
    >
        <component :is="icon" class="text-muted-foreground size-8" />
        <p class="font-medium">{{ title }}</p>
        <slot />
    </div>
</template>
