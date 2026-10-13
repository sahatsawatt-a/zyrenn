<script setup lang="ts">
import { CircuitBoard, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    billOfMaterials,
    bomCsv,
    circuitText,
    readCircuit,
} from '@/features/boards/circuit/netlist';
import { truthTable } from '@/features/boards/circuit/logic';
import type { Item } from '@/features/boards/composables/items';
import { copyToClipboard } from '@/lib/utils';

// What the drawing says as a circuit: the parts to buy, which pins are joined
// to which, and anything that looks unfinished. Read from the symbols and
// wires on the board each time it is opened; nothing here is kept.
const props = defineProps<{ items: Item[] }>();

const open = ref(false);
const tab = ref<'parts' | 'nets' | 'truth'>('parts');

// Only worked out while it is being looked at
const reading = computed(() => (open.value ? readCircuit(props.items) : null));
const bom = computed(() =>
    reading.value ? billOfMaterials(reading.value) : [],
);

// What a logic circuit puts out for everything that can be put in
const truth = computed(() => (open.value ? truthTable(props.items) : null));

const copy = async (text: string, what: string) => {
    try {
        await copyToClipboard(text);
        toast.success(`${what} copied`);
    } catch {
        toast.error('Couldn’t copy that.');
    }
};
</script>

<template>
    <Button
        variant="ghost"
        size="sm"
        title="Parts, nets and checks of the circuit drawn here"
        data-test="board-circuit"
        @click="open = true"
    >
        <CircuitBoard />
        Circuit
    </Button>

    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-xl" data-test="circuit-reading">
            <DialogHeader>
                <DialogTitle>Circuit</DialogTitle>
                <DialogDescription v-if="reading">
                    {{ reading.parts.length }} part{{
                        reading.parts.length === 1 ? '' : 's'
                    }}
                    · {{ reading.nets.length }} net{{
                        reading.nets.length === 1 ? '' : 's'
                    }}
                </DialogDescription>
            </DialogHeader>

            <template v-if="reading">
                <ul
                    v-if="reading.problems.length"
                    class="circuit-problems"
                    data-test="circuit-problems"
                >
                    <li v-for="problem in reading.problems" :key="problem">
                        <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
                        {{ problem }}
                    </li>
                </ul>

                <div class="circuit-tabs">
                    <button
                        type="button"
                        :class="{ 'is-on': tab === 'parts' }"
                        data-test="circuit-tab-parts"
                        @click="tab = 'parts'"
                    >
                        Parts
                    </button>
                    <button
                        type="button"
                        :class="{ 'is-on': tab === 'nets' }"
                        data-test="circuit-tab-nets"
                        @click="tab = 'nets'"
                    >
                        Nets
                    </button>
                    <button
                        v-if="truth"
                        type="button"
                        :class="{ 'is-on': tab === 'truth' }"
                        data-test="circuit-tab-truth"
                        @click="tab = 'truth'"
                    >
                        Truth table
                    </button>
                </div>

                <div class="max-h-72 overflow-y-auto">
                    <table v-if="tab === 'parts'" class="circuit-table">
                        <thead>
                            <tr>
                                <th>Part</th>
                                <th>Value</th>
                                <th class="text-right">Qty</th>
                                <th>Designators</th>
                            </tr>
                        </thead>
                        <tbody data-test="circuit-parts">
                            <tr
                                v-for="row in bom"
                                :key="`${row.what}|${row.value}`"
                            >
                                <td>{{ row.what }}</td>
                                <td>{{ row.value || '—' }}</td>
                                <td class="text-right tabular-nums">
                                    {{ row.names.length }}
                                </td>
                                <td>{{ row.names.join(', ') }}</td>
                            </tr>
                            <tr v-if="!bom.length">
                                <td colspan="4" class="text-muted-foreground">
                                    No parts on this board yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <table
                        v-else-if="tab === 'truth' && truth"
                        class="circuit-table circuit-truth"
                    >
                        <thead>
                            <tr>
                                <th v-for="name in truth.inputs" :key="name">
                                    {{ name }}
                                </th>
                                <th
                                    v-for="name in truth.outputs"
                                    :key="name"
                                    class="is-output"
                                >
                                    {{ name }}
                                </th>
                            </tr>
                        </thead>
                        <tbody data-test="circuit-truth">
                            <tr v-for="(row, index) in truth.rows" :key="index">
                                <td
                                    v-for="(level, column) in row.inputs"
                                    :key="`in-${column}`"
                                >
                                    {{ level }}
                                </td>
                                <td
                                    v-for="(level, column) in row.outputs"
                                    :key="`out-${column}`"
                                    class="is-output"
                                    :class="{ 'is-high': level === 1 }"
                                >
                                    {{ level ?? '?' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <table v-else class="circuit-table">
                        <thead>
                            <tr>
                                <th>Net</th>
                                <th>Joins</th>
                            </tr>
                        </thead>
                        <tbody data-test="circuit-nets">
                            <tr v-for="net in reading.nets" :key="net.name">
                                <td class="font-medium">{{ net.name }}</td>
                                <td>
                                    {{
                                        net.pins
                                            .map(
                                                (pin) =>
                                                    `${pin.part}.${pin.pin}`,
                                            )
                                            .join(', ')
                                    }}
                                </td>
                            </tr>
                            <tr v-if="!reading.nets.length">
                                <td colspan="2" class="text-muted-foreground">
                                    Nothing is wired yet: draw a connector from
                                    one pin to another.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap justify-end gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!bom.length"
                        title="The parts list, for a spreadsheet"
                        data-test="circuit-copy-bom"
                        @click="copy(bomCsv(reading), 'Parts list')"
                        >Copy parts (CSV)</Button
                    >
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!reading.parts.length"
                        title="The circuit as text: paste it into a note's circuit block and it is drawn there"
                        data-test="circuit-copy-text"
                        @click="copy(circuitText(reading), 'Circuit text')"
                        >Copy as text</Button
                    >
                </div>
            </template>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
.circuit-problems {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    padding: 0.5rem 0.625rem;
    font-size: 0.75rem;
    color: color-mix(in oklab, var(--destructive) 85%, var(--foreground));
    background-color: color-mix(in oklab, var(--destructive) 8%, transparent);
    border-radius: var(--radius-md);
}
.circuit-problems li {
    display: flex;
    gap: 0.375rem;
}

.circuit-tabs {
    display: flex;
    gap: 2px;
    padding: 2px;
    background-color: var(--muted);
    border-radius: var(--radius-md);
}
.circuit-tabs button {
    flex: 1;
    height: 1.75rem;
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--muted-foreground);
    border-radius: var(--radius-sm);
    cursor: pointer;
}
.circuit-tabs button.is-on {
    color: var(--foreground);
    background-color: var(--background);
    box-shadow: 0 1px 2px rgb(15 23 42 / 0.08);
}

.circuit-table {
    width: 100%;
    font-size: 0.75rem;
    border-collapse: collapse;
}
.circuit-table th {
    padding: 0.25rem 0.5rem;
    font-size: 0.6875rem;
    font-weight: 600;
    text-align: left;
    color: var(--muted-foreground);
    border-bottom: 1px solid var(--border);
}
.circuit-truth th,
.circuit-truth td {
    width: 3rem;
    text-align: center;
    font-variant-numeric: tabular-nums;
}
.circuit-truth .is-output {
    border-left: 1px solid var(--border);
}
.circuit-truth td.is-high {
    font-weight: 600;
    color: #15803d;
}
.circuit-table td {
    padding: 0.3125rem 0.5rem;
    vertical-align: top;
    border-bottom: 1px solid var(--border);
}
</style>
