<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import FoldSection from '@/components/common/FoldSection.vue';
import RangeField from '@/components/common/RangeField.vue';
import Tip from '@/components/common/Tip.vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    Item,
    LineStyle,
    Routing,
    Side,
} from '@/features/boards/model/items';
import {
    CONNECTOR_TYPES,
    HEAD_TYPES,
    connectorTypeOf,
} from '@/features/boards/model/connectors';
import type { ConnectorLook } from '@/features/boards/model/connectors';
import { SIDES } from '@/features/boards/model/geometry';
import ConnectorIcon from './ConnectorIcon.vue';

// Everything that can be said about a line between two shapes. The fiddly
// parts fold away: what a line is capped with is set once and rarely again.
const props = defineProps<{ line: Item }>();

const emit = defineEmits<{
    update: [Partial<Item>];
}>();

// The kind of line it is, as one choice: "Is a kind of" sets both caps and
// the dash, which are still there to set one by one under Ends
const type = computed(() => connectorTypeOf(props.line));
const choosingType = ref(false);

const typeGroups = (['Lines', 'Class diagram', 'Data model'] as const).map(
    (name) => ({
        name,
        types: CONNECTOR_TYPES.filter((each) => each.group === name),
    }),
);

const setType = (look: ConnectorLook) => {
    emit('update', { ...look });
    choosingType.value = false;
};

/** A cap by the words the fold's summary can say it in. */
const headLabel = (head: Item['startHead']) =>
    (HEAD_TYPES.find((each) => each.value === head)?.label ?? head)
        .replace(/ \(.*\)$/, '')
        .toLowerCase();

const routings: Routing[] = ['elbow', 'straight', 'curved'];
const styles: LineStyle[] = ['solid', 'dashed', 'dotted'];

const pinnedSide = (end: 'from' | 'to'): Side | null =>
    props.line[end]?.side ?? null;

// A list cannot hold an empty choice, so "auto" stands for no side
const AUTO = 'auto';

const endsOpen = ref(false);

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
        <p class="inspector-label">Type</p>
        <Popover v-model:open="choosingType">
            <PopoverTrigger as-child>
                <button
                    type="button"
                    class="inspector-type"
                    title="What kind of line this is: its ends and its dash, chosen together"
                    data-test="connector-type"
                >
                    <ConnectorIcon
                        :kind="{
                            look: {
                                startHead: line.startHead,
                                endHead: line.endHead,
                                lineStyle: line.lineStyle,
                            },
                        }"
                    />
                    <span>{{ type?.label ?? 'Custom' }}</span>
                    <ChevronDown class="size-3.5 opacity-60" />
                </button>
            </PopoverTrigger>
            <PopoverContent
                side="left"
                align="start"
                class="max-h-[min(26rem,70svh)] w-56 overflow-y-auto p-1.5"
                data-test="connector-types"
            >
                <template v-for="group in typeGroups" :key="group.name">
                    <p class="type-heading">{{ group.name }}</p>
                    <button
                        v-for="each in group.types"
                        :key="each.id"
                        type="button"
                        class="type-option"
                        :class="{ 'is-on': type?.id === each.id }"
                        :data-test="`connector-type-${each.id}`"
                        @click="setType(each.look)"
                    >
                        <ConnectorIcon :kind="{ look: each.look }" />
                        {{ each.label }}
                    </button>
                </template>
            </PopoverContent>
        </Popover>

        <p class="inspector-label">Routing</p>
        <div class="inspector-segments">
            <Tip v-for="option in routings" :key="option" :text="option">
                <button
                    type="button"
                    :aria-label="option"
                    :class="{ 'is-on': line.routing === option }"
                    :data-test="`routing-${option}`"
                    @click="emit('update', { routing: option })"
                >
                    <ConnectorIcon :kind="option" />
                </button>
            </Tip>
        </div>

        <p class="inspector-label">Line</p>
        <div class="inspector-segments">
            <Tip v-for="option in styles" :key="option" :text="option">
                <button
                    type="button"
                    :aria-label="option"
                    :class="{ 'is-on': line.lineStyle === option }"
                    :data-test="`style-${option}`"
                    @click="emit('update', { lineStyle: option })"
                >
                    <ConnectorIcon :kind="option" />
                </button>
            </Tip>
        </div>

        <label class="inspector-slider">
            Thickness
            <RangeField
                :model-value="line.lineWidth"
                label="Thickness"
                :min="1"
                :max="8"
                class="flex-1"
                data-test="line-width"
                @update:model-value="emit('update', { lineWidth: $event })"
            />
        </label>

        <div class="inspector-fields">
            <div v-for="end in ['from', 'to'] as const" :key="end">
                {{ end === 'from' ? 'Start on' : 'End on' }}
                <Select
                    :model-value="pinnedSide(end) ?? AUTO"
                    @update:model-value="
                        pinSide(
                            end,
                            ($event === AUTO ? null : $event) as Side | null,
                        )
                    "
                >
                    <SelectTrigger
                        size="sm"
                        class="h-7 w-full px-2 text-xs capitalize"
                        :aria-label="end === 'from' ? 'Start on' : 'End on'"
                        :data-test="`side-${end}`"
                        :data-value="pinnedSide(end) ?? ''"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="AUTO" class="text-xs"
                            >Auto</SelectItem
                        >
                        <SelectItem
                            v-for="side in SIDES"
                            :key="side"
                            :value="side"
                            class="text-xs capitalize"
                            >{{ side }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
        </div>

        <FoldSection
            v-model:open="endsOpen"
            title="Ends"
            :hint="`${headLabel(line.startHead)} → ${headLabel(line.endHead)}`"
            data-test="ends-config"
        >
            <div
                v-for="side in ['start', 'end'] as const"
                :key="side"
                class="inspector-heads"
            >
                <span>{{ side }}</span>
                <Tip
                    v-for="head in HEAD_TYPES"
                    :key="head.value"
                    :text="head.label"
                >
                    <button
                        type="button"
                        :aria-label="`${side}: ${head.label}`"
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
                </Tip>
            </div>

            <label class="inspector-slider">
                Head size
                <RangeField
                    :model-value="line.headSize"
                    label="Head size"
                    :min="6"
                    :max="24"
                    class="flex-1"
                    data-test="head-size"
                    @update:model-value="emit('update', { headSize: $event })"
                />
            </label>
        </FoldSection>
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

/* The kind of line, as a picture of it and its name */
.inspector-type {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    height: 2rem;
    padding: 0 0.5rem;
    font-size: 0.75rem;
    color: var(--foreground);
    background-color: var(--background);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
}

.inspector-type span {
    flex: 1;
    text-align: left;
}

.inspector-type:hover {
    background-color: var(--muted);
}

.type-heading {
    padding: 0.375rem 0.375rem 0.125rem;
    font-size: 0.625rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted-foreground);
}

.type-option {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    width: 100%;
    padding: 0.25rem 0.375rem;
    font-size: 0.75rem;
    color: var(--foreground);
    border-radius: var(--radius-sm);
    cursor: pointer;
}

.type-option:hover {
    background-color: var(--muted);
}

.type-option.is-on {
    color: var(--primary);
    background-color: color-mix(in oklab, var(--primary) 10%, transparent);
}

/* Each end's caps: its name, then a row of seven and a row of seven */
.inspector-heads {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 2px;
}

.inspector-heads span {
    grid-column: 1 / -1;
    font-size: 0.6875rem;
    text-transform: capitalize;
    color: var(--muted-foreground);
}

.inspector-heads button {
    display: inline-flex;
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

/* Two short pickers, where ten buttons used to be */
.inspector-fields {
    display: flex;
    gap: 0.375rem;
}

.inspector-fields > div {
    min-width: 0;
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 0.125rem;
    font-size: 0.6875rem;
    color: var(--muted-foreground);
}

.inspector-slider {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.6875rem;
    color: var(--muted-foreground);
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
