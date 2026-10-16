<script setup lang="ts">
import { useNow } from '@vueuse/core';
import { computed } from 'vue';

// WhatsApp and Instagram take an answer only for a day after the customer
// last wrote: said before it is written, since the service would refuse it

const props = defineProps<{
    // Until when its service takes an answer
    until: string;
    service: string | undefined;
}>();

const until = computed(() => new Date(props.until));
const now = useNow({ interval: 30_000 });
const tooLate = computed(() => until.value < now.value);
const format = new Intl.DateTimeFormat(undefined, {
    weekday: 'short',
    hour: 'numeric',
    minute: '2-digit',
});
</script>

<template>
    <p
        class="mb-1.5 px-1 text-xs"
        :class="
            tooLate
                ? 'text-amber-700 dark:text-amber-400'
                : 'text-muted-foreground'
        "
        data-chat-window
    >
        <template v-if="tooLate">
            More than a day since they last wrote:
            {{ service }} will not take an answer until they write again. A note
            still works.
        </template>
        <template v-else>
            {{ service }} takes an answer until {{ format.format(until) }}.
        </template>
    </p>
</template>
