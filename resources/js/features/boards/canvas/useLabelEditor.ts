import type Konva from 'konva';
import type { Ref } from 'vue';
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import {
    LABEL_LINE,
    LABEL_SIZE,
    isSymbol,
    labelBounds,
    labelWidth,
    symbolLabels,
    symbolOf,
    withWords,
    wordsOf,
} from '@/features/boards/circuit/symbols';
import { isSwept, midpointOf } from '../model/connectors';
import type { Item } from '../model/items';
import { hasText, isConnector } from '../model/items';
import { fontOf, labelBox, labelHeight, labelStyle } from '../drawing/labels';

/** The name the frame's own title carries, so a double-click can tell them apart. */
export const FRAME_TITLE = 'frame-title';

type Editing = {
    board: {
        byId: Ref<Map<string, Item>>;
        setText: (id: string, text: string) => void;
    };
    camera: {
        scale: Ref<number>;
        position: Ref<{ x: number; y: number }>;
    };
    presenting: Ref<boolean>;
    /** The drawn path of a connector, for putting the editor on its middle. */
    connectorPath: (item: Item) => number[];
    /** Where a connector's label is put, clear of the items about it. */
    connectorLabel: (item: Item) => { x: number; y: number } | null;
};

/**
 * Writing on something. Konva has no text input of its own, so a real textarea
 * is laid over the canvas, in the place and at the size the finished label
 * will have.
 */
export function useLabelEditor({
    board,
    camera,
    presenting,
    connectorPath,
    connectorLabel,
}: Editing) {
    const editingId = ref<string | null>(null);
    const editorText = ref('');
    const editor = useTemplateRef<HTMLTextAreaElement>('editor');

    /** A part with a designator: what is typed on it is its value. */
    const valued = (item: Item) =>
        isSymbol(item) && !!symbolOf(item.symbol)?.prefix;

    const editingItem = computed(() =>
        editingId.value
            ? (board.byId.value.get(editingId.value) ?? null)
            : null,
    );

    /** Where the textarea goes, in screen coordinates that follow the camera. */
    const editorStyle = computed(() => {
        const item = editingItem.value;

        if (!item) {
            return { display: 'none' };
        }

        const scale = camera.scale.value;
        const { x, y } = camera.position.value;

        // A frame's title is written above it, not on it
        if (item.kind === 'frame') {
            return {
                left: `${item.x * scale + x}px`,
                top: `${(item.y - 30) * scale + y}px`,
                width: `${Math.min(item.width, 420) * scale}px`,
                height: `${24 * scale}px`,
                fontSize: `${18 * scale}px`,
                textAlign: 'left' as const,
            };
        }

        // A connector's label sits where it is drawn: on the middle of the
        // line, unless an item is there
        if (isConnector(item)) {
            const path = connectorPath(item);
            const middle =
                connectorLabel(item) ?? midpointOf(path, isSwept(item, path));

            return {
                left: `${(middle.x - 70) * scale + x}px`,
                top: `${(middle.y - 11) * scale + y}px`,
                width: `${140 * scale}px`,
                height: `${22 * scale}px`,
                fontSize: `${13 * scale}px`,
                textAlign: 'center' as const,
            };
        }

        // A symbol's words are written beside it, where they will stay
        if (isSymbol(item)) {
            const typed = editorText.value || '0';
            const labels = symbolLabels({
                ...item,
                text: valued(item)
                    ? withWords(wordsOf(item).name || '?', typed)
                    : typed,
            });
            const spot = labels[labels.length - 1];

            if (spot) {
                const wide = Math.max(64, labelWidth(typed) + 20);
                const box = labelBounds(spot);
                const left =
                    spot.align === 'left'
                        ? spot.x
                        : spot.align === 'right'
                          ? spot.x - wide
                          : spot.x - wide / 2;

                return {
                    left: `${left * scale + x}px`,
                    top: `${box.y * scale + y}px`,
                    width: `${wide * scale}px`,
                    height: `${LABEL_LINE * scale}px`,
                    fontSize: `${LABEL_SIZE * scale}px`,
                    fontWeight: valued(item) ? 'normal' : '600',
                    textAlign: spot.align,
                };
            }
        }

        // Typing wraps and lines up the way the finished label will: in the
        // same box, at the same size and weight
        const box = labelBox(item);

        return {
            left: `${(item.x + box.x) * scale + x}px`,
            top: `${(item.y + box.y) * scale + y}px`,
            width: `${box.width * scale}px`,
            height: `${Math.max(box.height, labelHeight({ ...item, text: editorText.value })) * scale}px`,
            fontSize: `${item.fontSize * scale}px`,
            fontWeight: labelStyle(item),
            fontFamily: fontOf(item),
            textAlign: item.align,
        };
    });

    const startEditing = (id: string) => {
        const item = board.byId.value.get(id);

        // Ink has nowhere to put a label; everything else, connectors included,
        // does
        if (!item || (!hasText(item) && !isConnector(item))) {
            return;
        }

        editingId.value = id;
        // On a part, only its value: its name is kept as it is
        editorText.value = valued(item) ? wordsOf(item).value : item.text;

        void nextTick(() => {
            editor.value?.focus();
            editor.value?.select();
        });
    };

    const stopEditing = (keep = true) => {
        const id = editingId.value;

        const item = id ? board.byId.value.get(id) : null;

        if (id && item && keep) {
            board.setText(
                id,
                valued(item)
                    ? withWords(wordsOf(item).name, editorText.value)
                    : editorText.value,
            );
        }

        editingId.value = null;
    };

    const onItemDoubleClick = (event: Konva.KonvaEventObject<MouseEvent>) => {
        const id = event.target.id() || event.target.getParent()?.id();
        const item = id ? board.byId.value.get(id) : null;

        if (!item || presenting.value) {
            return;
        }

        // Double-clicking inside a frame is for whatever sits in it; the title
        // is renamed by double-clicking the title itself.
        if (item.kind === 'frame' && event.target.name() !== FRAME_TITLE) {
            return;
        }

        startEditing(item.id);
    };

    return {
        editingId,
        editingItem,
        editorText,
        editorStyle,
        startEditing,
        stopEditing,
        onItemDoubleClick,
    };
}
