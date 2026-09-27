<script setup lang="ts">
import { NodeViewWrapper } from '@tiptap/vue-3';
import type { NodeViewProps } from '@tiptap/vue-3';
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue';
import type { Item } from '@/components/Board/items';
import { nameOf } from '@/components/Board/items';
import type { BoardContent, BoardSummary } from '@/lib/boards';
import { boardContent, listBoards } from '@/lib/boards';
import { show } from '@/routes/boards';

// A board shown in a note: pick the board, pick a frame, and there it is.
//
// It is kept as a ```board fence holding the two references, so it travels
// through Markdown like any other block and an agent can read what a note
// points at. Nothing of the board itself is copied in: the note shows whatever
// the board says today.
const props = defineProps<NodeViewProps>();

// Konva is heavy and most notes hold no board at all
const BoardView = defineAsyncComponent(
    () => import('@/components/Board/BoardView.vue'),
);

const WHOLE_BOARD = 'all';

const source = computed(() => props.node.textContent);

// Each reference is on its own line, and may be empty: the space after the
// colon must not be allowed to run past the newline and swallow the next line
const reference = computed(() => ({
    ref: /^[ \t]*ref:[ \t]*(\S+)[ \t]*$/m.exec(source.value)?.[1] ?? '',
    frame:
        /^[ \t]*frame:[ \t]*(\S+)[ \t]*$/m.exec(source.value)?.[1] ??
        WHOLE_BOARD,
}));

const boards = ref<BoardSummary[]>([]);
const board = ref<BoardContent | null>(null);
const loading = ref(false);
const problem = ref('');

const frames = computed(() =>
    (board.value?.items ?? []).filter((item: Item) => item.kind === 'frame'),
);

/** Writes the two references back into the fence, which is what is saved. */
const write = (next: { ref?: string; frame?: string }) => {
    const chosen = { ...reference.value, ...next };
    const text = `ref: ${chosen.ref}\nframe: ${chosen.frame}`;
    const from = props.getPos();

    if (typeof from !== 'number') {
        return;
    }

    props.editor
        .chain()
        .focus()
        .insertContentAt(
            { from: from + 1, to: from + props.node.nodeSize - 1 },
            text,
        )
        .run();
};

const load = async (refId: string) => {
    if (!refId) {
        board.value = null;

        return;
    }

    loading.value = true;
    problem.value = '';

    try {
        board.value = await boardContent(refId);
    } catch (error) {
        board.value = null;
        problem.value = (error as Error).message;
    } finally {
        loading.value = false;
    }
};

onMounted(async () => {
    try {
        boards.value = await listBoards();
    } catch {
        // The picker simply stays empty; the board itself may still load
    }

    await load(reference.value.ref);
});

watch(
    () => reference.value.ref,
    (refId) => void load(refId),
);

const onBoardChosen = (event: Event) => {
    write({
        ref: (event.target as HTMLSelectElement).value,
        frame: WHOLE_BOARD,
    });
};

const onFrameChosen = (event: Event) =>
    write({ frame: (event.target as HTMLSelectElement).value });

const shownFrame = computed(() =>
    reference.value.frame === WHOLE_BOARD ? null : reference.value.frame,
);

const link = computed(() =>
    reference.value.ref ? show.url(reference.value.ref) : '',
);
</script>

<template>
    <NodeViewWrapper class="board-block" data-test="board-block">
        <!-- Toolbar: which board, and which frame of it -->
        <div class="board-toolbar" contenteditable="false">
            <select
                class="board-select"
                aria-label="Board"
                data-test="board-choose"
                :value="reference.ref"
                @change="onBoardChosen"
            >
                <option value="" disabled>Choose a board…</option>
                <option
                    v-for="option in boards"
                    :key="option.ref_id"
                    :value="option.ref_id"
                >
                    {{ option.title || 'Untitled board' }}
                </option>
            </select>

            <select
                class="board-select"
                aria-label="Frame"
                data-test="frame-choose"
                :value="reference.frame"
                :disabled="!frames.length"
                @change="onFrameChosen"
            >
                <option :value="WHOLE_BOARD">Whole board</option>
                <option
                    v-for="(frame, index) in frames"
                    :key="frame.id"
                    :value="frame.id"
                >
                    {{ index + 1 }} · {{ nameOf(frame) }}
                </option>
            </select>

            <a
                v-if="link"
                class="board-open"
                :href="link"
                target="_blank"
                rel="noopener"
                >Open</a
            >
        </div>

        <!-- The board itself, drawn the way its own page draws it -->
        <div class="board-stage" contenteditable="false">
            <p v-if="problem" class="board-message">{{ problem }}</p>
            <p v-else-if="loading" class="board-message">Loading…</p>
            <p v-else-if="!reference.ref" class="board-message">
                Choose a board to show it here.
            </p>
            <BoardView
                v-else-if="board"
                :items="board.items"
                :frame="shownFrame"
            />
        </div>

        <!-- The fence's own text: the two references, kept out of sight -->
        <pre class="board-source"><code /></pre>
    </NodeViewWrapper>
</template>

<style scoped>
.board-block {
    margin: 1rem 0;
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background-color: var(--card);
}

.board-toolbar {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.375rem 0.5rem;
    border-bottom: 1px solid var(--border);
    background-color: var(--muted);
}

.board-select {
    max-width: 16rem;
    flex: 1;
    height: 1.75rem;
    padding: 0 0.375rem;
    font-size: 0.75rem;
    color: var(--foreground);
    background-color: var(--background);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
}
.board-select:disabled {
    opacity: 0.6;
    cursor: default;
}

.board-open {
    margin-left: auto;
    padding: 0 0.5rem;
    font-size: 0.75rem;
    color: var(--muted-foreground);
    text-decoration: none;
}
.board-open:hover {
    color: var(--foreground);
}

.board-stage {
    height: 22rem;
}

.board-message {
    display: flex;
    height: 100%;
    align-items: center;
    justify-content: center;
    font-size: 0.8125rem;
    color: var(--muted-foreground);
}

/* The fence still holds the references; nobody needs to read them here */
.board-source {
    display: none;
}
</style>
