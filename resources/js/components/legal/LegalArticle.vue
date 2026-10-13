<script setup lang="ts">
// One of the pages anyone may read -- privacy, terms, deleting data: its
// title, when it was last changed, and its words set as one column to read.
import { Head } from '@inertiajs/vue3';
import PublicLayout from '@/layouts/PublicLayout.vue';

const props = defineProps<{
    title: string;
    // The day it was last changed, as YYYY-MM-DD
    updated: string;
}>();

const day = new Date(`${props.updated}T00:00:00`).toLocaleDateString([], {
    dateStyle: 'long',
});
</script>

<template>
    <Head :title="title" />

    <PublicLayout>
        <main class="mx-auto w-full max-w-2xl flex-1 px-6 py-12 sm:py-16">
            <h1 class="text-3xl font-semibold tracking-tight text-balance">
                {{ title }}
            </h1>
            <p class="text-muted-foreground mt-2 text-sm">
                Last changed {{ day }}
            </p>

            <div
                class="text-foreground/90 [&_h2]:text-foreground mt-8 text-[15px] leading-relaxed [&_a]:underline [&_a]:underline-offset-4 [&_h2]:mt-10 [&_h2]:text-lg [&_h2]:font-semibold [&_li]:mt-1.5 [&_ol]:mt-3 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mt-3 [&_ul]:mt-3 [&_ul]:list-disc [&_ul]:pl-5"
            >
                <slot />
            </div>
        </main>
    </PublicLayout>
</template>
