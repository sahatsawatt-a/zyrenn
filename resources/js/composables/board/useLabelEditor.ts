import type Konva from 'konva';
import type { Ref } from 'vue';
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import { midpointOf } from './connectors';
import type { Item } from './items';
import { hasText, isConnector } from './items';

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
}: Editing) {
    const editingId = ref<string | null>(null);
    const editorText = ref('');
    const editor = useTemplateRef<HTMLTextAreaElement>('editor');

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

        // A connector's label sits on the middle of the line
        if (isConnector(item)) {
            const middle = midpointOf(connectorPath(item));

            return {
                left: `${(middle.x - 70) * scale + x}px`,
                top: `${(middle.y - 11) * scale + y}px`,
                width: `${140 * scale}px`,
                height: `${22 * scale}px`,
                fontSize: `${13 * scale}px`,
                textAlign: 'center' as const,
            };
        }

        return {
            left: `${item.x * scale + x}px`,
            top: `${item.y * scale + y}px`,
            width: `${item.width * scale}px`,
            height: `${item.height * scale}px`,
            fontSize: `${item.fontSize * scale}px`,
            // Typing lines up the way the finished label will
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
        editorText.value = item.text;

        void nextTick(() => {
            editor.value?.focus();
            editor.value?.select();
        });
    };

    const stopEditing = (keep = true) => {
        const id = editingId.value;

        if (id && keep) {
            board.setText(id, editorText.value);
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
