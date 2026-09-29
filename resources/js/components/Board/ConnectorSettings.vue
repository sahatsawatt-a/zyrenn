<script setup lang="ts">
import type {
    Item,
    LineStyle,
    Routing,
    Side,
} from '../../composables/board/items';
import { HEAD_TYPES } from '../../composables/board/connectors';
import { SIDES } from '../../composables/board/geometry';
import ConnectorIcon from './ConnectorIcon.vue';

// Everything that can be said about a line between two shapes. The fiddly
// parts fold away: what a line is capped with is set once and rarely again.
const props = defineProps<{ line: Item }>();

const emit = defineEmits<{
    update: [Partial<Item>];
}>();

const routings: Routing[] = ['elbow', 'straight', 'curved'];
const styles: LineStyle[] = ['solid', 'dashed', 'dotted'];

const pinnedSide = (end: 'from' | 'to'): Side | null =>
    props.line[end]?.side ?? null;

// The end keeps whatever it is pinned to; only its face changes
const pinSide = (end: 'from' | 'to', side: Side | null) => {
    const current = props.line[end];

    if (!current) {
        return;
    }

    emit('update', { [end]: { ...current, side } });
};
</script>

<template>
    <!-- Connector-only controls: how the line is routed and capped -->
    <div class="inspector-connector" data-test="connector-config">
        <p class="inspector-label">Routing</p>
        <div class="inspector-segments">
            <button
                v-for="option in routings"
                :key="option"
                type="button"
                :title="option"
                :class="{ 'is-on': line.routing === option }"
                :data-test="`routing-${option}`"
                @click="emit('update', { routing: option })"
            >
                <ConnectorIcon :kind="option" />
            </button>
        </div>

        <p class="inspector-label">Line</p>
        <div class="inspector-segments">
            <button
                v-for="option in styles"
                :key="option"
                type="button"
                :title="option"
                :class="{ 'is-on': line.lineStyle === option }"
                :data-test="`style-${option}`"
                @click="emit('update', { lineStyle: option })"
            >
                <ConnectorIcon :kind="option" />
            </button>
        </div>

        <label class="inspector-slider">
            Thickness
            <input
                type="range"
                min="1"
                max="8"
                :value="line.lineWidth"
                data-test="line-width"
                @input="
                    emit('update', {
                        lineWidth: Number(
                            ($event.target as HTMLInputElement).value,
                        ),
                    })
                "
            />
            <span>{{ line.lineWidth }}</span>
        </label>

        <div class="inspector-fields">
            <label
                v-for="end in ['from', 'to'] as const"
                :key="end"
                :title="'Auto keeps this end facing whatever is at the other; a face keeps it there'"
            >
                {{ end === 'from' ? 'Start on' : 'End on' }}
                <select
                    :value="pinnedSide(end) ?? ''"
                    :data-test="`side-${end}`"
                    @change="
                        pinSide(
                            end,
                            (($event.target as HTMLSelectElement).value ||
                                null) as Side | null,
                        )
                    "
                >
                    <option value="">Auto</option>
                    <option v-for="side in SIDES" :key="side" :value="side">
                        {{ side }}
                    </option>
                </select>
            </label>
        </div>

        <details class="inspector-fold" data-test="ends-config">
            <summary>
                Ends
                <span>{{ line.startHead }} → {{ line.endHead }}</span>
            </summary>
            <div
                v-for="side in ['start', 'end'] as const"
                :key="side"
                class="inspector-heads"
            >
                <span>{{ side }}</span>
                <button
                    v-for="head in HEAD_TYPES"
                    :key="head.value"
                    type="button"
                    :title="`${side}: ${head.label}`"
                    :class="{
                        'is-on':
                            (side === 'start'
                                ? line.startHead
                                : line.endHead) === head.value,
                    }"
                    :data-test="`head-${side}-${head.value}`"
                    @click="
                        emit(
                            'update',
                            side === 'start'
                                ? { startHead: head.value }
                                : { endHead: head.value },
                        )
                    "
                >
                    <ConnectorIcon :kind="{ head: head.value, side }" />
                </button>
            </div>

            <label class="inspector-slider">
                Head size
                <input
                    type="range"
                    min="6"
                    max="24"
                    :value="line.headSize"
                    data-test="head-size"
                    @input="
                        emit('update', {
                            headSize: Number(
                                ($event.target as HTMLInputElement).value,
                            ),
                        })
                    "
                />
                <span>{{ line.headSize }}</span>
            </label>
        </details>
    </div>
</template>

<style scoped>
.inspector-connector {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}

.inspector-segments {
    display: flex;
    gap: 2px;
}

.inspector-segments button {
    display: inline-flex;
    flex: 1;
    align-items: center;
    justify-content: center;
    height: 1.75rem;
    font-size: 0.6875rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
}

.inspector-segments button:hover {
    background-color: var(--muted);
}

.inspector-segments button.is-on {
    color: var(--primary-foreground);
    background-color: var(--primary);
    border-color: var(--primary);
}

.inspector-heads {
    display: flex;
    align-items: center;
    gap: 2px;
}

.inspector-heads span {
    width: 2.25rem;
    font-size: 0.6875rem;
    text-transform: capitalize;
    color: var(--muted-foreground);
}

.inspector-heads button {
    display: inline-flex;
    flex: 1;
    align-items: center;
    justify-content: center;
    height: 1.75rem;
    color: var(--foreground);
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    cursor: pointer;
}

.inspector-heads button:hover {
    background-color: var(--muted);
}

.inspector-heads button.is-on {
    color: var(--primary);
    border-color: var(--primary);
    background-color: color-mix(in oklab, var(--primary) 10%, transparent);
}

/* The sides read as words rather than pictures: "auto" has no icon to draw */
.inspector-sides {
    display: flex;
    align-items: center;
    gap: 2px;
}

.inspector-sides span {
    width: 2.25rem;
    font-size: 0.6875rem;
    text-transform: capitalize;
    color: var(--muted-foreground);
}

.inspector-sides button {
    flex: 1;
    padding: 0.25rem 0;
    font-size: 0.6875rem;
    color: var(--foreground);
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    cursor: pointer;
}

.inspector-sides button:hover {
    background-color: var(--muted);
}

.inspector-sides button.is-on {
    color: var(--primary);
    border-color: var(--primary);
    background-color: color-mix(in oklab, var(--primary) 10%, transparent);
}

/* A fold keeps the fiddly settings out of the way until they are wanted */
.inspector-fold > summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.25rem 0;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted-foreground);
    cursor: pointer;
}

.inspector-fold > summary span {
    font-weight: 400;
    letter-spacing: 0;
    text-transform: none;
}

.inspector-fold[open] > summary {
    margin-bottom: 0.25rem;
}

/* Two short pickers, where ten buttons used to be */
.inspector-fields {
    display: flex;
    gap: 0.375rem;
}

.inspector-fields label {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 0.125rem;
    font-size: 0.6875rem;
    color: var(--muted-foreground);
}

.inspector-fields select {
    height: 1.75rem;
    padding: 0 0.25rem;
    font-size: 0.75rem;
    color: var(--foreground);
    background-color: var(--background);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    text-transform: capitalize;
    cursor: pointer;
}

.inspector-slider {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.6875rem;
    color: var(--muted-foreground);
}

.inspector-slider input {
    flex: 1;
    min-width: 0;
}

.inspector-slider span {
    width: 1.25rem;
    text-align: right;
    color: var(--foreground);
}

.inspector-label {
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted-foreground);
}

/* Keeps the two halves of the label controls apart */
.inspector-divider {
    width: 1px;
    height: 1.25rem;
    margin: 0 0.25rem;
    background-color: var(--border);
}
</style>
