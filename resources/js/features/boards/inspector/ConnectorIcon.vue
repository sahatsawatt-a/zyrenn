<script setup lang="ts">
import { computed } from 'vue';
import { headParts, headRoom } from '@/features/boards/model/connectors';
import type { ConnectorLook } from '@/features/boards/model/connectors';
import type {
    HeadType,
    LineStyle,
    Routing,
} from '@/features/boards/model/items';

// Small previews of what each connector setting draws. A picture of a dashed
// line beats the word "dashed", and a diamond cap is only obvious as a
// diamond. A cap is drawn from the same pieces the board draws it from.
const props = defineProps<{
    kind:
        | Routing
        | LineStyle
        | { head: HeadType; side: 'start' | 'end' }
        // A whole line: both of its caps, and its dash
        | { look: ConnectorLook };
}>();

const head = computed(() =>
    typeof props.kind === 'object' && 'head' in props.kind ? props.kind : null,
);
const look = computed(() =>
    typeof props.kind === 'object' && 'look' in props.kind
        ? props.kind.look
        : null,
);

// The line runs between these; a cap sits on whichever end it is for. One
// cap alone is drawn at the right, and mirrored for a start (below).
const FROM = 3;
const TO = 25;
const MIDDLE = 9;
// Caps are drawn this big: enough to tell a crow's foot from an arrow
const SIZE = 6.5;

const caps = computed(() => {
    if (head.value) {
        return [{ type: head.value.head, at: TO, turn: 0 }];
    }

    if (look.value) {
        return [
            { type: look.value.startHead, at: FROM, turn: 180 },
            { type: look.value.endHead, at: TO, turn: 0 },
        ];
    }

    return [];
});

const dash = computed(() => {
    const style = look.value?.lineStyle ?? props.kind;

    if (style === 'dashed') return '4 2.5';
    if (style === 'dotted') return '1 3';

    return undefined;
});

const linePath = computed(() => {
    // Stopped short of a solid cap, so it does not poke through
    if (head.value) {
        return `M${FROM} ${MIDDLE} H${TO - headRoom(head.value.head, SIZE)}`;
    }

    if (look.value) {
        return `M${FROM + headRoom(look.value.startHead, SIZE)} ${MIDDLE} H${TO - headRoom(look.value.endHead, SIZE)}`;
    }

    switch (props.kind) {
        case 'elbow':
            return 'M3 13 H14 V5 H25';
        case 'curved':
            return 'M3 13 C13 13, 15 5, 25 5';
        case 'straight':
            return 'M3 13 L25 5';
        default:
            // A plain rule for the line styles
            return `M${FROM} ${MIDDLE} H${TO}`;
    }
});

/** A piece's points, as SVG wants them. */
const pointsOf = (points: number[] = []) =>
    points
        .reduce<string[]>(
            (pairs, value, index) =>
                index % 2
                    ? [...pairs.slice(0, -1), `${pairs.at(-1)},${value}`]
                    : [...pairs, `${value}`],
            [],
        )
        .join(' ');

const fillOf = (fill?: 'ink' | 'paper') =>
    fill === 'ink'
        ? 'currentColor'
        : fill === 'paper'
          ? 'var(--background)'
          : 'none';
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
            :d="linePath"
            fill="none"
            stroke="currentColor"
            stroke-width="1.6"
            :stroke-dasharray="dash"
            stroke-linecap="round"
        />

        <g
            v-for="cap in caps"
            :key="cap.at"
            :transform="`translate(${cap.at} ${MIDDLE}) rotate(${cap.turn})`"
        >
            <template
                v-for="(part, index) in headParts(cap.type, SIZE)"
                :key="index"
            >
                <circle
                    v-if="part.circle"
                    :cx="part.circle.x"
                    cy="0"
                    :r="part.circle.radius"
                    :fill="fillOf(part.fill)"
                    :stroke="part.stroked ? 'currentColor' : 'none'"
                    stroke-width="1.3"
                />
                <polygon
                    v-else-if="part.closed"
                    :points="pointsOf(part.points)"
                    :fill="fillOf(part.fill)"
                    stroke="currentColor"
                    stroke-width="1.3"
                    stroke-linejoin="round"
                />
                <polyline
                    v-else
                    :points="pointsOf(part.points)"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </template>
        </g>
    </svg>
</template>

<style scoped>
.connector-icon {
    width: 28px;
    height: 18px;
}
</style>
