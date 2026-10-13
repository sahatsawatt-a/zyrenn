<template>
    <DiagramBlock v-bind="props" :kind="mermaidKind">
        <template #actions>
            <DropdownMenu v-if="isEr" @update:open="onSqlMenu">
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="diagram-btn"
                        title="Turn this diagram into SQL"
                        data-test="mermaid-sql"
                    >
                        SQL
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-48">
                    <DropdownMenuLabel>Dialect</DropdownMenuLabel>
                    <DropdownMenuRadioGroup
                        :model-value="dialect"
                        @update:model-value="setDialect"
                    >
                        <DropdownMenuRadioItem
                            v-for="item in sqlDialects"
                            :key="item.id"
                            :value="item.id"
                            @select.prevent
                        >
                            {{ item.label }}
                        </DropdownMenuRadioItem>
                    </DropdownMenuRadioGroup>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        data-test="mermaid-sql-insert"
                        @select="writeSql"
                    >
                        {{
                            hasSqlBelow
                                ? 'Update SQL below'
                                : 'Insert SQL below'
                        }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        data-test="mermaid-sql-copy"
                        @select="copySql"
                    >
                        Copy SQL
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </template>
    </DiagramBlock>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { nodeViewProps } from '@tiptap/vue-3';
import { mermaidImage, renderMermaid } from '@/lib/mermaid';
import { copyToClipboard } from '@/lib/utils';
import {
    SQL_HEADER,
    erDiagramToSql,
    isErDiagram,
    sqlDialects,
} from '@/features/notes/lib/erToSql';
import type { SqlDialect } from '@/features/notes/lib/erToSql';
import type { DiagramKind } from '@/features/notes/lib/diagramKind';
import { toast } from 'vue-sonner';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    detectMermaidTemplate,
    mermaidTemplates,
} from '@/features/notes/config/mermaidTemplates';
import DiagramBlock from './DiagramBlock.vue';

// A Mermaid diagram in a note: the block itself is DiagramBlock, shared with
// every block drawn from its own text; what is Mermaid's own is here.
const props = defineProps(nodeViewProps);

const source = computed(() => props.node.textContent.trim());

const mermaidKind: DiagramKind = {
    name: 'mermaid',
    title: 'Diagram',
    custom: 'Custom diagram',
    empty: 'Empty diagram — pick a type above or click to write one.',
    groups: (['Diagrams', 'Charts'] as const).map((name) => ({
        name,
        templates: mermaidTemplates.filter(
            (template) => template.group === name,
        ),
    })),
    detect: detectMermaidTemplate,
    render: (text) => renderMermaid(text),
    image: mermaidImage,
    themed: true,
};

// ------------------------------------------------------------------------ SQL
// An erDiagram can be turned into CREATE TABLE statements. The diagram stays
// the source: running it again rewrites the SQL block it made last time
// (found by its header line) rather than stacking up another.
const isEr = computed(() => isErDiagram(source.value));

const DIALECT_KEY = 'zyrenn.mermaid.sqlDialect';
const readDialect = (): SqlDialect => {
    try {
        const saved = localStorage.getItem(DIALECT_KEY);
        if (sqlDialects.some((item) => item.id === saved)) {
            return saved as SqlDialect;
        }
    } catch {
        // Storage can be blocked; the default is fine
    }
    return 'postgres';
};

const dialect = ref<SqlDialect>(readDialect());
const setDialect = (value: unknown) => {
    dialect.value = value as SqlDialect;
    try {
        localStorage.setItem(DIALECT_KEY, dialect.value);
    } catch {
        // Remembered for this block only
    }
};

const sqlBelow = () => {
    const pos = props.getPos();
    if (typeof pos !== 'number') return null;

    const after = pos + props.node.nodeSize;
    const next = props.editor.state.doc.nodeAt(after);

    // By name: props.node comes through Vue's reactivity, so its type is a
    // proxy that ProseMirror's identity checks would not recognise
    return next?.type.name === props.node.type.name &&
        next.attrs.language === 'sql' &&
        next.textContent.startsWith(SQL_HEADER)
        ? { pos: after, node: next }
        : null;
};

const hasSqlBelow = ref(false);
const onSqlMenu = (open: boolean) => {
    if (open) hasSqlBelow.value = sqlBelow() !== null;
};

const generateSql = (): string | null => {
    try {
        return erDiagramToSql(source.value, dialect.value);
    } catch (err) {
        toast.error(err instanceof Error ? err.message : String(err));
        return null;
    }
};

const writeSql = () => {
    const pos = props.getPos();
    const sql = generateSql();
    if (typeof pos !== 'number' || sql === null) return;

    const { tr, schema } = props.editor.state;
    const existing = sqlBelow();

    if (existing) {
        tr.replaceWith(
            existing.pos + 1,
            existing.pos + 1 + existing.node.content.size,
            schema.text(sql),
        );
    } else {
        tr.insert(
            pos + props.node.nodeSize,
            schema.nodes[props.node.type.name].create(
                { language: 'sql' },
                schema.text(sql),
            ),
        );
    }
    props.editor.view.dispatch(tr);
};

const copySql = async () => {
    const sql = generateSql();
    if (sql === null) return;

    try {
        await copyToClipboard(sql);
        toast.success('SQL copied');
    } catch (err) {
        console.error('Failed to copy SQL: ', err);
    }
};
</script>
