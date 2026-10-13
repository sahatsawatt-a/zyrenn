<script setup lang="ts">
// Someone else's cursor, as the app shows it while people work together:
// an arrow in their colour, with their name beside it.
defineProps<{
    name: string;
    // One of the chart colours, by its number
    tone: 1 | 2 | 3 | 4 | 5;
}>();
</script>

<template>
    <div
        class="pointer-events-none absolute z-10 flex items-start gap-0.5"
        :style="{ color: `var(--chart-${tone})` }"
        aria-hidden="true"
    >
        <svg viewBox="0 0 16 16" class="size-4 drop-shadow-sm">
            <path
                d="M2 1.5 13.5 7 8.4 8.6 6.6 13.8Z"
                fill="currentColor"
                stroke="white"
                stroke-width="1"
                stroke-linejoin="round"
            />
        </svg>
        <span
            class="mt-3 rounded-full px-1.5 py-0.5 text-[10px] leading-none font-medium text-white"
            :style="{ background: `var(--chart-${tone})` }"
            >{{ name }}</span
        >
    </div>
</template>
