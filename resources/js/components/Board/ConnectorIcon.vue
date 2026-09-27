<script setup lang="ts">
import { computed } from 'vue';
import type { HeadType, LineStyle, Routing } from './items';

// Small previews of what each connector setting draws. A picture of a dashed
// line beats the word "dashed", and a diamond cap is only obvious as a diamond.
const props = defineProps<{
    kind: Routing | LineStyle | { head: HeadType; side: 'start' | 'end' };
}>();

const head = computed(() =>
    typeof props.kind === 'object' ? props.kind : null,
);

// Head previews draw the cap at the right end; a start cap is the same picture
// mirrored, which the transform below does.
const capAt = 25;

const dash = computed(() => {
    if (props.kind === 'dashed') return '5 3';
    if (props.kind === 'dotted') return '1 3';

    return undefined;
});

const routePath = computed(() => {
    switch (props.kind) {
        case 'elbow':
            return 'M3 13 H14 V5 H25';
        case 'curved':
            return 'M3 13 C13 13, 15 5, 25 5';
        case 'straight':
            return 'M3 13 L25 5';
        default:
            // A plain rule for the line styles and the head previews
            return 'M3 9 H25';
    }
});

// Stop the line short of a solid cap so it does not poke through
const lineEnd = computed(() => {
    const type = head.value?.head;

    return type && type !== 'none' && type !== 'bar' ? capAt - 7 : capAt;
});
</script>

<template>
    <svg
        viewBox="0 0 28 18"
        class="connector-icon"
        :style="
            head?.side === 'start' ? { transform: 'scaleX(-1)' } : undefined
        "
        aria-hidden="true"
    >
        <path
            :d="head ? `M3 9 H${lineEnd}` : routePath"
            fill="none"
            stroke="currentColor"
            stroke-width="1.6"
            :stroke-dasharray="dash"
            stroke-linecap="round"
        />

        <template v-if="head">
            <polygon
                v-if="head.head === 'arrow'"
                :points="`${capAt},9 ${capAt - 7},5.5 ${capAt - 7},12.5`"
                fill="currentColor"
            />
            <polyline
                v-else-if="head.head === 'open'"
                :points="`${capAt - 7},5 ${capAt},9 ${capAt - 7},13`"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
            <circle
                v-else-if="head.head === 'circle'"
                :cx="capAt - 3"
                cy="9"
                r="3.2"
                fill="currentColor"
            />
            <polygon
                v-else-if="head.head === 'diamond'"
                :points="`${capAt},9 ${capAt - 3.5},5.5 ${capAt - 7},9 ${capAt - 3.5},12.5`"
                fill="currentColor"
            />
            <line
                v-else-if="head.head === 'bar'"
                :x1="capAt"
                y1="4.5"
                :x2="capAt"
                y2="13.5"
                stroke="currentColor"
                stroke-width="1.8"
                stroke-linecap="round"
            />
        </template>
    </svg>
</template>

<style scoped>
.connector-icon {
    width: 28px;
    height: 18px;
}
</style>
