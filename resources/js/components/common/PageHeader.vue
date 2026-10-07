<script setup lang="ts">
import type { Component } from 'vue';

// The top of a folder view: what it is, where you are, and what can be made
defineProps<{ icon: Component; title: string; description: string }>();
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex min-w-0 items-center gap-3">
            <div
                class="bg-primary/10 text-primary flex size-11 shrink-0 items-center justify-center rounded-xl"
            >
                <component :is="icon" class="size-5" />
            </div>
            <div class="min-w-0">
                <h1 class="truncate text-xl font-semibold tracking-tight">
                    {{ title }}
                </h1>
                <p class="text-muted-foreground truncate text-sm">
                    {{ description }}
                </p>
            </div>
        </div>
        <div class="flex gap-2">
            <slot />
        </div>
    </div>
</template>
