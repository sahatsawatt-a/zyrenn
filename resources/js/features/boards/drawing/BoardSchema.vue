<script setup lang="ts">
import { Check, Copy, Database } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Item } from '@/features/boards/model/items';
import {
    isTable,
    schemaAsMermaid,
    schemaAsSql,
} from '@/features/boards/model/schema';
import { sqlDialects } from '@/features/notes/lib/erToSql';
import type { SqlDialect } from '@/features/notes/lib/erToSql';
import { copyToClipboard } from '@/lib/utils';

// The tables drawn on the board, written out: as SQL to make them with, or
// as a Mermaid erDiagram to put in a note. There only once a table is drawn
// -- a board is for any kind of diagram.
const props = withDefaults(
    defineProps<{
        items: Item[];
        // Its own button in the bar; without one it is opened from elsewhere
        button?: boolean;
    }>(),
    { button: true },
);

const open = defineModel<boolean>('open', { default: false });
const form = ref<SqlDialect | 'mermaid'>('postgres');
const copied = ref(false);

const forms: { id: SqlDialect | 'mermaid'; label: string }[] = [
    ...sqlDialects,
    { id: 'mermaid', label: 'Mermaid' },
];

const tables = computed(() => props.items.filter(isTable).length);

const written = computed(() => {
    if (!open.value) {
        return { ok: true, text: '' };
    }

    try {
        return {
            ok: true,
            text:
                form.value === 'mermaid'
                    ? schemaAsMermaid(props.items)
                    : schemaAsSql(props.items, form.value),
        };
    } catch (error) {
        return { ok: false, text: (error as Error).message };
    }
});

const copy = async () => {
    await copyToClipboard(written.value.text);
    copied.value = true;
    window.setTimeout(() => (copied.value = false), 1500);
};
</script>

<template>
    <Button
        v-if="button"
        variant="ghost"
        size="sm"
        :title="`Write the ${tables} table${tables === 1 ? '' : 's'} on this board out as SQL`"
        data-test="board-schema"
        @click="open = true"
    >
        <Database />
        SQL
    </Button>

    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-2xl" data-test="schema-dialog">
            <DialogHeader>
                <DialogTitle>The schema on this board</DialogTitle>
                <DialogDescription>
                    Read from the tables as they are drawn: their columns and
                    keys, and each line from a foreign key to the column it
                    points at.
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-wrap items-center gap-1">
                <button
                    v-for="each in forms"
                    :key="each.id"
                    type="button"
                    class="schema-form"
                    :class="{ 'is-on': form === each.id }"
                    :data-test="`schema-${each.id}`"
                    @click="form = each.id"
                >
                    {{ each.label }}
                </button>
                <Button
                    size="sm"
                    class="ml-auto"
                    :disabled="!written.ok"
                    data-test="schema-copy"
                    @click="copy"
                >
                    <component :is="copied ? Check : Copy" />
                    {{ copied ? 'Copied' : 'Copy' }}
                </Button>
            </div>

            <pre
                class="schema-text"
                :class="{ 'text-destructive': !written.ok }"
                data-test="schema-text"
                >{{ written.text }}</pre>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
.schema-form {
    height: 1.75rem;
    padding: 0 0.625rem;
    font-size: 0.75rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    cursor: pointer;
}
.schema-form:hover {
    background-color: var(--muted);
}
.schema-form.is-on {
    color: var(--primary-foreground);
    background-color: var(--primary);
    border-color: var(--primary);
}

.schema-text {
    max-height: min(26rem, 55svh);
    overflow: auto;
    padding: 0.75rem;
    font-family: var(--font-mono, ui-monospace, monospace);
    font-size: 0.75rem;
    line-height: 1.55;
    white-space: pre;
    background-color: var(--muted);
    border-radius: var(--radius-lg);
}
</style>
