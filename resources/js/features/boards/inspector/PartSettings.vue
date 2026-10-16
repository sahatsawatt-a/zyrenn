<script setup lang="ts">
import { computed } from 'vue';
import { Switch } from '@/components/ui/switch';
import {
    chipFrom,
    pinList,
    symbolOf,
    withWords,
    wordsOf,
} from '../circuit/symbols';
import type { Item } from '../model/items';

// A part of a circuit chosen on its own: its name and its value, the two
// things said of it; whether a switch or a clock is on; a module's pins.
const props = defineProps<{ item: Item }>();
const emit = defineEmits<{ update: [patch: Partial<Item>] }>();

const part = computed(() => {
    const symbol = symbolOf(props.item.symbol);

    return symbol && symbol.labels !== 'none'
        ? { item: props.item, symbol, ...wordsOf(props.item) }
        : null;
});

/**
 * A module's pins typed anew: it becomes a chip with those pins, the size
 * they need. A wire on a pin that is still there stays on it.
 */
const onPins = (event: Event) => {
    const symbol = symbolOf(chipFrom((event.target as HTMLInputElement).value));

    if (symbol && symbol.pins.length) {
        emit('update', {
            symbol: symbol.id,
            width: symbol.width,
            height: symbol.height,
        });
    }
};

const onWord = (field: 'name' | 'value', event: Event) => {
    const chosen = part.value;

    if (!chosen) {
        return;
    }

    const typed = (event.target as HTMLInputElement).value;

    emit('update', {
        text: chosen.symbol.prefix
            ? withWords(
                  field === 'name' ? typed : chosen.name,
                  field === 'value' ? typed : chosen.value,
              )
            : typed.trim(),
    });
};
</script>

<template>
    <!-- A part's name and its value, the two things said of it -->
    <div v-if="part" class="inspector-part" data-test="symbol-config">
        <label v-if="part.symbol.prefix">
            Name
            <input
                type="text"
                :value="part.name"
                :placeholder="`${part.symbol.prefix}1`"
                data-test="symbol-name"
                @change="onWord('name', $event)"
            />
        </label>
        <label>
            {{ part.symbol.prefix ? 'Value' : 'Name' }}
            <input
                type="text"
                :value="part.symbol.prefix ? part.value : part.name"
                :placeholder="part.symbol.hint"
                data-test="symbol-value"
                @change="onWord('value', $event)"
            />
        </label>
        <label
            v-if="
                part.symbol.logic === 'input' || part.symbol.logic === 'clock'
            "
            class="inspector-switch"
            title="Click it on the board to flip it. A switch that is on feeds the circuit a 1; a clock that is on ticks."
        >
            On
            <Switch
                :model-value="part.item.on"
                data-test="symbol-on"
                @update:model-value="emit('update', { on: $event })"
            />
        </label>
        <label
            v-if="part.symbol.module"
            title="The pins down its left, then after a | those down its right"
        >
            Pins
            <input
                type="text"
                :value="pinList(part.symbol)"
                placeholder="VCC, GND | OUT"
                data-test="symbol-pins"
                @change="onPins"
            />
        </label>
    </div>
</template>

<style scoped>
/* A part's name and value: a short word, then the room to type in */
.inspector-part {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}
.inspector-part label:not(.inspector-switch) {
    display: grid;
    grid-template-columns: 3rem minmax(0, 1fr);
    align-items: center;
    gap: 0.375rem;
    font-size: 0.6875rem;
    color: var(--muted-foreground);
}
.inspector-part input {
    min-width: 0;
    height: 1.75rem;
    padding: 0 0.375rem;
    font-size: 0.75rem;
    color: var(--foreground);
    background-color: var(--background);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
}
</style>
