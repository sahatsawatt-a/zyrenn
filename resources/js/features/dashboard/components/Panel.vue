<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import type { Component } from 'vue';

// A titled box on a dashboard, with a way to all of it when there is one
defineProps<{
    icon: Component;
    title: string;
    count?: number;
    href?: InertiaLinkProps['href'];
    hrefLabel?: string;
}>();
</script>

<template>
    <section class="bg-card flex flex-col rounded-xl border">
        <header class="flex items-center gap-2 border-b px-4 py-3">
            <component :is="icon" class="text-muted-foreground size-4" />
            <h2 class="text-sm font-medium">{{ title }}</h2>
            <span
                v-if="count"
                class="bg-muted rounded-full px-1.5 py-px text-[10px] tabular-nums"
            >
                {{ count }}
            </span>
            <Link
                v-if="href"
                :href="href"
                class="text-muted-foreground hover:text-foreground ml-auto flex items-center text-xs"
            >
                {{ hrefLabel ?? 'See all' }}
                <ChevronRight class="size-3.5" />
            </Link>
        </header>
        <div class="flex flex-1 flex-col p-1.5">
            <slot />
        </div>
    </section>
</template>
