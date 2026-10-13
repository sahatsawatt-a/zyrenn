<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    circuitExamples,
    defaultCircuit,
} from '@/features/boards/circuit/examples';
import { renderCircuit } from '@/features/boards/circuit/render';
import type { Item } from '@/features/boards/composables/items';

// A circuit written as text and put on the board as parts and wires: the
// same few lines a note draws from, here made into things that can be moved,
// re-valued and wired further.
const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{ insert: [Item[]] }>();

const source = ref(defaultCircuit.source);

const drawn = computed(() => renderCircuit(source.value));

// Opened again, it is as it was left: a circuit is often put on twice
const example = ref(defaultCircuit.id);

watch(example, (id) => {
    const chosen = circuitExamples.find((each) => each.id === id);

    if (chosen) {
        source.value = chosen.source;
    }
});

const insert = () => {
    // Laid out afresh, so each time it is put on its parts are new ones
    const made = renderCircuit(source.value);

    if (made.ok) {
        emit('insert', made.items);
        open.value = false;
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-3xl" data-test="circuit-dialog">
            <DialogHeader>
                <DialogTitle>Circuit from text</DialogTitle>
                <DialogDescription>
                    A part to a line, then the wires between them. It goes on
                    the board as parts you can move and wire further.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="flex min-w-0 flex-col gap-2">
                    <select
                        v-model="example"
                        class="border-border bg-background h-8 rounded-md border px-2 text-xs"
                        aria-label="Start from an example"
                        data-test="circuit-example"
                    >
                        <option
                            v-for="each in circuitExamples"
                            :key="each.id"
                            :value="each.id"
                        >
                            {{ each.label }}
                        </option>
                    </select>
                    <textarea
                        v-model="source"
                        class="border-border bg-background h-64 w-full resize-none rounded-lg border p-3 font-mono text-xs"
                        spellcheck="false"
                        data-test="circuit-source"
                    />
                </div>

                <div
                    class="border-border flex h-64 min-w-0 items-center justify-center overflow-auto rounded-lg border bg-white p-3 text-slate-900 sm:h-auto"
                    data-test="circuit-preview"
                >
                    <!-- eslint-disable-next-line vue/no-v-html -- drawn here from the library's own paths; the words in it are escaped -->
                    <div
                        v-if="drawn.ok"
                        class="circuit-drawing"
                        v-html="drawn.svg"
                    />
                    <p
                        v-else
                        class="text-destructive text-xs"
                        data-test="circuit-error"
                    >
                        {{ drawn.error }}
                    </p>
                </div>
            </div>

            <div class="flex justify-end">
                <Button
                    :disabled="!drawn.ok"
                    data-test="circuit-add"
                    @click="insert"
                    >Add to board</Button
                >
            </div>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
.circuit-drawing :deep(svg) {
    max-width: 100%;
    height: auto;
}
</style>
