<script setup lang="ts">
import { NodeViewWrapper } from '@tiptap/vue-3';
import type { NodeViewProps } from '@tiptap/vue-3';
import { TriangleAlert } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { valueOf, want } from '@/features/notes/lib/noteValues';
import { holdPrint } from '@/lib/printReady';

// A live value: what its formula comes to now, as the server works it out.
// Clicked, by someone who can edit, its formula opens to change.
const props = defineProps<NodeViewProps>();

const expression = computed(() => String(props.node.attrs.expression ?? ''));
const answer = computed(() =>
    expression.value ? valueOf(expression.value) : undefined,
);

const shown = computed(() => {
    const value = answer.value?.value;

    if (typeof value === 'number') {
        return value.toLocaleString('en-US', { maximumFractionDigits: 2 });
    }

    return answer.value?.text ?? '';
});

watch(expression, (now) => now && want(now), { immediate: true });

// The PDF printer waits for the answer (see printReady)
let releasePrint: (() => void) | null = holdPrint();
const letPrint = () => {
    releasePrint?.();
    releasePrint = null;
};

watch(
    () => answer.value !== undefined || !expression.value,
    (ready) => ready && letPrint(),
    { immediate: true },
);
onBeforeUnmount(letPrint);

// ------------------------------------------------------------ editing
const editing = ref(false);
const draft = ref('');

const open = () => {
    if (!props.editor.isEditable) {
        return;
    }

    draft.value = expression.value;
    editing.value = true;
};

const keep = () => {
    const next = draft.value.trim();
    editing.value = false;

    if (!next) {
        props.deleteNode();

        return;
    }

    props.updateAttributes({ expression: next });
};

// A new one, put in from the slash menu, opens to be written
onMounted(() => {
    if (!expression.value && props.editor.isEditable) {
        open();
    }
});
</script>

<template>
    <NodeViewWrapper as="span" class="note-value-wrap">
        <Popover
            :open="editing"
            @update:open="(isOpen) => (isOpen ? open() : (editing = false))"
        >
            <PopoverAnchor as-child>
                <span
                    class="note-value"
                    :class="{
                        'is-error': answer?.error,
                        'is-selected': selected,
                    }"
                    :title="
                        answer?.error
                            ? `${answer.error}\n{{ ${expression} }}`
                            : `{{ ${expression} }}`
                    "
                    data-test="note-value"
                    @click="open"
                >
                    <template v-if="!expression">Live value</template>
                    <template v-else-if="answer?.error">
                        <TriangleAlert class="inline size-3.5 align-[-2px]" />
                        {{ expression }}
                    </template>
                    <template v-else-if="answer">{{ shown }}</template>
                    <template v-else>…</template>
                </span>
            </PopoverAnchor>
            <PopoverContent class="w-96" align="start">
                <form class="grid gap-2" @submit.prevent="keep">
                    <textarea
                        v-model="draft"
                        rows="2"
                        spellcheck="false"
                        placeholder='e.g. trip("Shanghai").total_cost'
                        class="border-input bg-background focus-visible:ring-ring/50 rounded-md border px-3 py-2 font-mono text-sm outline-none focus-visible:ring-2"
                        data-test="note-value-expression"
                        @keydown.enter.exact.prevent="keep"
                    />
                    <p
                        v-if="answer?.error && draft === expression"
                        class="text-destructive text-xs"
                    >
                        {{ answer.error }}
                    </p>
                    <p v-else class="text-muted-foreground text-xs leading-5">
                        Worked out each time the note is read. Reach a trip or
                        table by its title:
                        <code>trip("Shanghai").day(5).cost</code>,
                        <code>sum(table("Budget").thb)</code>.
                    </p>
                    <div class="flex justify-end">
                        <Button
                            type="submit"
                            size="sm"
                            data-test="note-value-save"
                        >
                            Done
                        </Button>
                    </div>
                </form>
            </PopoverContent>
        </Popover>
    </NodeViewWrapper>
</template>

<style scoped>
.note-value {
    display: inline-block;
    border-radius: 0.375rem;
    padding: 0 0.35rem;
    background: color-mix(in oklab, var(--primary) 10%, transparent);
    color: var(--foreground);
    font-variant-numeric: tabular-nums;
    cursor: pointer;
    white-space: nowrap;
}

.note-value.is-error {
    background: color-mix(in oklab, var(--destructive) 12%, transparent);
    color: var(--destructive);
    font-family: var(--font-mono, monospace);
    font-size: 0.85em;
}

.note-value.is-selected {
    outline: 2px solid color-mix(in oklab, var(--primary) 60%, transparent);
}
</style>
