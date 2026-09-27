import type Konva from 'konva';
import type { ComputedRef, Ref } from 'vue';
import { nextTick, ref } from 'vue';
import { boundsOf, overlaps } from './geometry';
import type { Guide } from './guides';
import { alignmentFor } from './guides';
import type { Endpoint, Item, ItemKind, Tool } from './items';
import { hasText, isConnectable, isConnector, isStroke } from './items';

type Drawing = {
    board: {
        items: Ref<Item[]>;
        byId: ComputedRef<Map<string, Item>>;
        selection: Ref<string[]>;
        selected: ComputedRef<Item[]>;
        select: (ids: string[]) => void;
        toggleInSelection: (id: string) => void;
        add: (item: Item) => void;
        commit: () => void;
        makeItem: (
            kind: ItemKind,
            x: number,
            y: number,
            colours?: { fill?: string; stroke?: string },
        ) => Item;
    };
    camera: {
        scale: Ref<number>;
        position: Ref<{ x: number; y: number }>;
        toBoard: (point: { x: number; y: number }) => { x: number; y: number };
        zoomBy: (factor: number) => void;
    };
    /** The Konva stage, once it is mounted. */
    stage: () => Konva.Stage | null | undefined;
    tool: Ref<Tool>;
    presenting: Ref<boolean>;
    panning: ComputedRef<boolean>;
    fill: Ref<string>;
    stroke: Ref<string>;
    /** Where a connector should pin itself, and what to light up while aiming. */
    pinTo: (item: Item | null, point: { x: number; y: number }) => Endpoint;
    showTarget: (point: { x: number; y: number }, over: Item | null) => void;
    clearTarget: () => void;
    startEditing: (id: string) => void;
};

/**
 * Everything the pointer does to a board: drawing something new, picking
 * things up, dragging them into line with their neighbours, and the marquee
 * drawn round a group of them.
 */
export function useDrawing({
    board,
    camera,
    stage,
    tool,
    presenting,
    panning,
    fill,
    stroke,
    pinTo,
    showTarget,
    clearTarget,
    startEditing,
}: Drawing) {
    type Draft = { item: Item; originX: number; originY: number } | null;

    const draft = ref<Draft>(null);
    const guides = ref<Guide[]>([]);
    const marquee = ref<{
        x: number;
        y: number;
        width: number;
        height: number;
    } | null>(null);

    let marqueeStart: { x: number; y: number } | null = null;
    let dragOrigin: Map<string, { x: number; y: number }> | null = null;
    let floatingOrigin: Map<
        string,
        { from: { x: number; y: number }; to: { x: number; y: number } }
    > | null = null;

    const pointerOnBoard = () => {
        const point = stage()?.getPointerPosition();

        return point ? camera.toBoard(point) : null;
    };

    // The shape under a board point, topmost first, ignoring frames and ink so a
    // connector pins itself to something it can sensibly leave from.
    const shapeAt = (point: { x: number; y: number }): Item | null => {
        for (let index = board.items.value.length - 1; index >= 0; index--) {
            const item = board.items.value[index];

            if (
                isConnectable(item) &&
                overlaps(boundsOf(item), { ...point, width: 1, height: 1 })
            ) {
                return item;
            }
        }

        return null;
    };

    const onPointerDown = (event: Konva.KonvaEventObject<PointerEvent>) => {
        drewJustNow = false;

        if (presenting.value || panning.value) {
            return;
        }

        const point = pointerOnBoard();

        if (!point) {
            return;
        }

        const onEmptySpace = event.target === stage();

        if (tool.value === 'select') {
            if (onEmptySpace) {
                board.select([]);
                marqueeStart = point;
                marquee.value = { x: point.x, y: point.y, width: 0, height: 0 };
            }

            return;
        }

        // Every other tool draws something, sized by the drag that follows
        const item = board.makeItem(tool.value as ItemKind, point.x, point.y, {
            fill: fill.value,
            stroke: stroke.value,
        });

        if (isConnector(item)) {
            // Connectors live in board coordinates: their ends decide where they are
            item.x = 0;
            item.y = 0;
            item.from = pinTo(shapeAt(point), point);
            item.to = { item: null, side: null, x: point.x, y: point.y };
        } else if (isStroke(item)) {
            item.points = [0, 0];
        } else {
            item.width = 0;
            item.height = 0;
        }

        draft.value = { item, originX: point.x, originY: point.y };
    };

    const onPointerMove = () => {
        const point = pointerOnBoard();

        if (!point) {
            return;
        }

        if (marqueeStart) {
            marquee.value = {
                x: Math.min(marqueeStart.x, point.x),
                y: Math.min(marqueeStart.y, point.y),
                width: Math.abs(point.x - marqueeStart.x),
                height: Math.abs(point.y - marqueeStart.y),
            };

            return;
        }

        const current = draft.value;

        if (!current) {
            return;
        }

        if (current.item.kind === 'draw') {
            // A new array rather than a push: vue-konva compares the config it was
            // handed, and pushing leaves the same array reference in place, so the
            // stroke only appeared once the item was committed on release.
            current.item.points = [
                ...current.item.points,
                point.x - current.originX,
                point.y - current.originY,
            ];

            return;
        }

        if (isConnector(current.item)) {
            const over = shapeAt(point);

            current.item.to = pinTo(over, point);
            showTarget(point, over);

            return;
        }

        current.item.x = Math.min(current.originX, point.x);
        current.item.y = Math.min(current.originY, point.y);
        current.item.width = Math.abs(point.x - current.originX);
        current.item.height = Math.abs(point.y - current.originY);
    };

    /**
     * Letting go after drawing also fires a click on whatever is under the pointer
     * -- usually the frame the new shape was drawn inside, which would then take
     * the selection off the thing just drawn. That one click is not a choice.
     */
    let drewJustNow = false;

    const onPointerUp = () => {
        if (marqueeStart) {
            const box = marquee.value;

            if (box && box.width > 4 && box.height > 4) {
                board.select(
                    board.items.value
                        .filter(
                            (item) =>
                                !item.hidden &&
                                !item.locked &&
                                overlaps(boundsOf(item), box),
                        )
                        .map((item) => item.id),
                );
            }

            marqueeStart = null;
            marquee.value = null;

            return;
        }

        const current = draft.value;
        draft.value = null;

        if (!current) {
            return;
        }

        const item = current.item;

        // A click rather than a drag: give the item its default size so a tap
        // still puts something usable on the board.
        if (!isStroke(item) && item.width < 8 && item.height < 8) {
            const fresh = board.makeItem(item.kind, item.x, item.y);

            item.width = fresh.width;
            item.height = fresh.height;
        }

        if (isConnector(item)) {
            clearTarget();

            const from = item.from;
            const to = item.to;
            const tiny =
                Math.hypot(
                    (to?.x ?? 0) - (from?.x ?? 0),
                    (to?.y ?? 0) - (from?.y ?? 0),
                ) < 12;

            // A stray click, or a loop from a shape back to itself
            if (tiny || (from?.item && from.item === to?.item)) {
                return;
            }

            board.add(item);
            tool.value = 'select';
            drewJustNow = true;

            return;
        }

        if (isStroke(item) && item.points.length < 4) {
            return;
        }

        board.add(item);
        tool.value = 'select';
        drewJustNow = true;

        if (hasText(item) && item.kind !== 'frame') {
            void nextTick(() => startEditing(item.id));
        }
    };

    // Dragging one of several selected items should move the whole selection
    const onItemDragStart = (event: Konva.KonvaEventObject<DragEvent>) => {
        const id = event.target.id();

        if (!board.selection.value.includes(id)) {
            board.select([id]);
        }

        board.commit();
        dragOrigin = new Map(
            board.selected.value.map((item) => [
                item.id,
                { x: item.x, y: item.y },
            ]),
        );

        // A connector pinned to a shape follows it on its own. One floating free is
        // carried along with whatever else is being dragged.
        floatingOrigin = new Map(
            board.selected.value
                .filter(
                    (item) =>
                        isConnector(item) && !item.from?.item && !item.to?.item,
                )
                .map((item) => [
                    item.id,
                    {
                        from: { x: item.from?.x ?? 0, y: item.from?.y ?? 0 },
                        to: { x: item.to?.x ?? 0, y: item.to?.y ?? 0 },
                    },
                ]),
        );
    };

    const onItemDragMove = (event: Konva.KonvaEventObject<DragEvent>) => {
        const origins = dragOrigin;
        const id = event.target.id();
        const origin = origins?.get(id);

        if (!origins || !origin || !board.byId.value.has(id)) {
            return;
        }

        const dx = event.target.x() - origin.x;
        const dy = event.target.y() - origin.y;

        floatingOrigin?.forEach((start, connectorId) => {
            const connector = board.byId.value.get(connectorId);

            if (!connector?.from || !connector.to) {
                return;
            }

            connector.from = {
                ...connector.from,
                x: start.from.x + dx,
                y: start.from.y + dy,
            };
            connector.to = {
                ...connector.to,
                x: start.to.x + dx,
                y: start.to.y + dy,
            };
        });

        origins.forEach((start, otherId) => {
            const item = board.byId.value.get(otherId);

            if (!item) {
                return;
            }

            item.x = otherId === id ? event.target.x() : start.x + dx;
            item.y = otherId === id ? event.target.y() : start.y + dy;
        });
    };

    /**
     * Konva asks this for the position it is about to put a dragged node at, which
     * is the one place a nudge does not fight the drag: moving the node inside
     * dragmove instead makes Konva re-base the drag and the nudges pile up.
     */
    const snapWhileDragging =
        (item: Item) => (position: { x: number; y: number }) => {
            // Only a single item lines up: a group would jump to suit one member
            if (
                isConnector(item) ||
                item.kind === 'draw' ||
                dragOrigin?.size !== 1
            ) {
                return position;
            }

            const scale = camera.scale.value;
            const box = {
                ...boundsOf(item),
                x: (position.x - camera.position.value.x) / scale,
                y: (position.y - camera.position.value.y) / scale,
            };

            const others = board.items.value
                .filter(
                    (other) =>
                        other.id !== item.id &&
                        !isConnector(other) &&
                        other.kind !== 'draw',
                )
                .map(boundsOf);

            const alignment = alignmentFor(box, others, 8 / scale);
            guides.value = alignment.guides;

            return {
                x: position.x + alignment.dx * scale,
                y: position.y + alignment.dy * scale,
            };
        };

    const onItemDragEnd = () => {
        dragOrigin = null;
        floatingOrigin = null;
        guides.value = [];
    };

    // Konva resizes by scaling; fold it back into width/height so text and corner
    // radii keep their proportions.
    // Written on every frame of a resize, not just at the end: anything pinned to
    // the shape -- a connector's anchor above all -- has to keep up with it.
    const applyTransform = () => {
        board.selection.value.forEach((id) => {
            const node = stage()?.findOne(`#${id}`);
            const item = board.byId.value.get(id);

            if (!node || !item) {
                return;
            }

            const scaleX = node.scaleX();
            const scaleY = node.scaleY();

            item.x = node.x();
            item.y = node.y();
            item.rotation = node.rotation();

            if (isStroke(item)) {
                item.points = item.points.map((value, index) =>
                    index % 2 === 0 ? value * scaleX : value * scaleY,
                );
            } else {
                item.width = Math.max(12, item.width * scaleX);
                item.height = Math.max(12, item.height * scaleY);
            }

            node.scaleX(1);
            node.scaleY(1);
        });
    };

    const onWheel = (event: Konva.KonvaEventObject<WheelEvent>) => {
        event.evt.preventDefault();
        camera.zoomBy(event.evt.deltaY > 0 ? 0.92 : 1.08);
    };

    const onStageDragEnd = (event: Konva.KonvaEventObject<DragEvent>) => {
        if (event.target === stage()) {
            camera.position.value = {
                x: event.target.x(),
                y: event.target.y(),
            };
        }
    };

    const onItemClick = (event: Konva.KonvaEventObject<MouseEvent>) => {
        if (drewJustNow) {
            drewJustNow = false;

            return;
        }

        if (presenting.value || tool.value !== 'select') {
            return;
        }

        const id = event.target.id() || event.target.getParent()?.id();

        if (!id || !board.byId.value.has(id)) {
            return;
        }

        if (event.evt.shiftKey) {
            board.toggleInSelection(id);

            return;
        }

        board.select([id]);
    };

    return {
        draft,
        guides,
        marquee,
        shapeAt,
        onPointerDown,
        onPointerMove,
        onPointerUp,
        onItemDragStart,
        onItemDragMove,
        onItemDragEnd,
        snapWhileDragging,
        applyTransform,
        onWheel,
        onStageDragEnd,
        onItemClick,
    };
}
