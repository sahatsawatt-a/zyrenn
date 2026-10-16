import type Konva from 'konva';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import type { Item } from '../model/items';
import { savedOf } from '../model/myShapes';
import type { useBoard } from './useBoard';
import type { useMyShapes } from './useMyShapes';

// How wide the picture kept with a shape is, in pixels
const PREVIEW = 160;

/**
 * The selection kept as a shape of one's own: a picture of it taken from
 * the board as it is drawn -- without the handles round it -- then named in
 * a dialog and kept.
 */
export function useShapeSaving({
    board,
    stage,
    transformer,
    shapes,
}: {
    board: ReturnType<typeof useBoard>;
    stage: () => Konva.Stage | undefined;
    transformer: () => Konva.Transformer | undefined;
    shapes: ReturnType<typeof useMyShapes>;
}) {
    const open = ref(false);
    const preview = ref<string | null>(null);
    const saving = ref(false);
    // What is kept, as it was when the dialog opened
    let picked: Item[] = [];

    /** A small picture of the items, as the board draws them. */
    function pictureOf(items: Item[]): string | null {
        const target = stage();

        if (!target) {
            return null;
        }

        const boxes = items
            .map((item) => target.findOne(`#${item.id}`)?.getClientRect())
            .filter((box): box is NonNullable<typeof box> => !!box);

        if (!boxes.length) {
            return null;
        }

        const left = Math.min(...boxes.map((box) => box.x));
        const top = Math.min(...boxes.map((box) => box.y));
        const width = Math.max(...boxes.map((box) => box.x + box.width)) - left;
        const height =
            Math.max(...boxes.map((box) => box.y + box.height)) - top;
        const handles = transformer();
        const shown = handles?.visible();

        try {
            handles?.visible(false);

            return target.toDataURL({
                x: left - 4,
                y: top - 4,
                width: width + 8,
                height: height + 8,
                pixelRatio: Math.min(2, PREVIEW / Math.max(1, width + 8)),
                mimeType: 'image/png',
            });
        } catch {
            // A picture from elsewhere that taints the canvas: kept without one
            return null;
        } finally {
            handles?.visible(shown ?? true);
        }
    }

    function start() {
        if (!board.selected.value.length) {
            return;
        }

        // Plain copies: what the board holds are Vue proxies
        picked = JSON.parse(
            JSON.stringify(savedOf(board.selected.value, board.items.value)),
        );
        preview.value = pictureOf(picked);
        open.value = true;
    }

    async function save(name: string, forProject: boolean) {
        saving.value = true;

        try {
            const made = await shapes.save(
                name,
                picked,
                preview.value,
                forProject,
            );

            if (made) {
                open.value = false;
                toast.success(`“${made.name}” is under My shapes.`);
            }
        } finally {
            saving.value = false;
        }
    }

    return { open, preview, saving, start, save };
}
