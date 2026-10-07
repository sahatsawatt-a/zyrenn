<script setup lang="ts">
// A titled group in a folder view -- the folders, then the things
defineProps<{ title?: string; count: number }>();
</script>

<template>
    <section class="flex flex-col gap-3">
        <h3
            v-if="title"
            class="text-muted-foreground flex items-center gap-2 text-xs font-medium tracking-wide uppercase"
        >
            {{ title }}
            <span
                class="bg-muted rounded-full px-1.5 py-px text-[10px] tracking-normal tabular-nums"
            >
                {{ count }}
            </span>
        </h3>
        <slot />
    </section>
</template>
