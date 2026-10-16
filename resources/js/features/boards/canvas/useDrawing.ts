import type Konva from 'konva';
import { huggedHeight } from '../drawing/labels';
import type { ComputedRef, Ref } from 'vue';
import { nextTick, ref, watch } from 'vue';
import { strokesTouched } from '../model/erase';
import { pressureOf } from '../model/ink';
import {
    firstWords,
    isSymbol,
    snapped,
    symbolOf,
} from '@/features/boards/circuit/symbols';
import { spotOnWire } from '@/features/boards/circuit/wiring';
import { isWire } from '../model/connectors';
import { boundsOf, overlaps } from '../model/geometry';
import type { Guide } from '../model/guides';
import { alignmentFor } from '../model/guides';
import { groupKeys } from '../model/layers';
import type { Endpoint, Item, ItemKind, Tool } from '../model/items';
import {
    drawnBy,
    hasText,
    isConnectable,
    isConnector,
    isStroke,
} from '../model/items';

type Drawing = {
    board: {
        items: Ref<Item[]>;
        byId: ComputedRef<Map<string, Item>>;
        selection: Ref<string[]>;
        selected: ComputedRef<Item[]>;
        select: (ids: string[]) => void;
        toggleInSelection: (id: string) => void;
        add: (item: Item, options?: { keepSelection?: boolean }) => void;
        remove: (ids: string[]) => void;
        commit: () => void;
        settle: (dropped?: string[]) => void;
        makeItem: (
            kind: ItemKind,
            x: number,
            y: number,
            colours?: { fill?: string; stroke?: string; symbol?: string },
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
    /** The drawn path of a connector, for joining a new wire to it. */
    connectorPath: (item: Item) => number[];
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
    connectorPath,
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
    // The frame (or "board") each thing being dragged started on
    let dragHomes: Map<string, string> | null = null;
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

    /** Where a part goes to sit with its middle under the pointer, on the grid. */
    const underPointer = (item: Item, point: { x: number; y: number }) =>
        snapped(item, {
            x: point.x - item.width / 2,
            y: point.y - item.height / 2,
        });

    /**
     * A wire's loose end let go on another wire is joined to it: a dot goes
     * down there, and the wire it landed on becomes two, one either side of
     * the dot, so all three are the same piece of the circuit.
     */
    const joinToWire = (item: Item, end: 'from' | 'to') => {
        const loose = item[end];

        if (!loose || loose.item) {
            return;
        }

        const wires = board.items.value.filter(
            (other) =>
                isConnector(other) &&
                other.id !== item.id &&
                !other.hidden &&
                !other.locked &&
                isWire(other, board.byId.value),
        );
        const spot = spotOnWire(
            wires,
            connectorPath,
            loose,
            10 / camera.scale.value,
        );

        if (!spot?.wire.to) {
            return;
        }

        const dot = board.makeItem('symbol', 0, 0, { symbol: 'junction' });
        Object.assign(
            dot,
            snapped(dot, {
                x: spot.x - dot.width / 2,
                y: spot.y - dot.height / 2,
            }),
        );

        const onDot = {
            item: dot.id,
            side: null,
            pin: '1',
            x: spot.x,
            y: spot.y,
        };
        const rest: Item = {
            ...spot.wire,
            id: board.makeItem('arrow', 0, 0).id,
            // Its words stay on the half that keeps its name
            text: '',
            from: { ...onDot },
            to: { ...spot.wire.to },
        };

        spot.wire.to = { ...onDot };
        item[end] = { ...onDot };
        board.items.value.push(dot, rest);
    };

    // ------------------------------------------------------------ Eraser
    // The pencil strokes a drag of the eraser has passed over, faded while it
    // goes on, all taken together when it is let go: one undo brings them back
    const erasing = ref<Set<string>>(new Set());
    let erasedTo: { x: number; y: number } | null = null;
    // How near a stroke the eraser has to pass, as it looks on screen
    const reach = () => 10 / camera.scale.value + 2;

    // Another tool chosen mid-drag (Escape goes back to Select): nothing is
    // taken, and nothing half-drawn is put down -- a stroke, a shape or a
    // line is on the board only once it is let go
    // (at once, not at the next tick: a stroke begun right after choosing the
    // pencil is never the one dropped)
    watch(
        tool,
        () => {
            erasedTo = null;
            erasing.value = new Set();
            draft.value = null;
            clearTarget();
        },
        { flush: 'sync' },
    );

    const eraseAlong = (to: { x: number; y: number }) => {
        const touched = strokesTouched(
            board.items.value,
            erasedTo ?? to,
            to,
            reach(),
        );
        erasedTo = to;

        if (touched.some((id) => !erasing.value.has(id))) {
            erasing.value = new Set([...erasing.value, ...touched]);
        }
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

        // The eraser -- or a pen turned over, its eraser end down, whatever
        // tool is chosen
        const penEraser =
            event.evt.pointerType === 'pen' && (event.evt.buttons & 32) !== 0;

        if (tool.value === 'eraser' || penEraser) {
            board.select([]);
            erasedTo = null;
            erasing.value = new Set();
            eraseAlong(point);

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
        const drawing = drawnBy(tool.value);
        const item = board.makeItem(drawing.kind, point.x, point.y, {
            fill: fill.value,
            stroke: stroke.value,
            symbol: drawing.symbol,
        });

        if (isSymbol(item)) {
            // A part is the size it is: it goes down where the pointer is,
            // on the grid, already named
            Object.assign(item, underPointer(item, point));
            item.text = firstWords(symbolOf(item.symbol)!, board.items.value);
        } else if (isConnector(item)) {
            // Connectors live in board coordinates: their ends decide where they are
            item.x = 0;
            item.y = 0;
            item.from = pinTo(shapeAt(point), point);
            item.to = { item: null, side: null, x: point.x, y: point.y };
        } else if (isStroke(item)) {
            item.points = [0, 0];

            // A stylus: how hard it presses, point by point, for heavier ink
            const pressure = isConnector(item) ? null : pressureOf(event.evt);

            if (pressure !== null) {
                item.pressures = [pressure];
            }
            // Nothing stays selected under the pen: its handles would take
            // the strokes meant for the board
            board.select([]);
        } else {
            item.width = 0;
            item.height = 0;
        }

        draft.value = { item, originX: point.x, originY: point.y };
    };

    /**
     * Every place the pointer has been since the last move that was heard.
     * A pen reports far more often than the screen is drawn, and the browser
     * hands over only the last of each batch: a line through those alone has
     * corners where quick handwriting had curves.
     */
    const pointsSince = (event?: Konva.KonvaEventObject<PointerEvent>) => {
        const box = stage()?.container().getBoundingClientRect();
        const moves = event?.evt.getCoalescedEvents?.() ?? [];

        return box && moves.length > 1
            ? moves.map((move) =>
                  camera.toBoard({
                      x: move.clientX - box.left,
                      y: move.clientY - box.top,
                  }),
              )
            : null;
    };

    /** How hard a pen pressed at each place pointsSince() found, one for each. */
    const pressuresSince = (
        event: Konva.KonvaEventObject<PointerEvent>,
        count: number,
    ) => {
        const moves = event.evt.getCoalescedEvents?.() ?? [];
        const read = (moves.length === count ? moves : [event.evt]).map(
            (move) => pressureOf(move) ?? 0.5,
        );

        return Array.from(
            { length: count },
            (_, at) => read[at] ?? read[read.length - 1],
        );
    };

    const onPointerMove = (event?: Konva.KonvaEventObject<PointerEvent>) => {
        const point = pointerOnBoard();

        if (!point) {
            return;
        }

        if (erasedTo) {
            eraseAlong(point);

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
            const since = pointsSince(event) ?? [point];
            current.item.points = [
                ...current.item.points,
                ...since.flatMap((at) => [
                    at.x - current.originX,
                    at.y - current.originY,
                ]),
            ];

            // As hard as it pressed at each of them: one for every point
            if (current.item.pressures && event) {
                current.item.pressures = [
                    ...current.item.pressures,
                    ...pressuresSince(event, since.length),
                ];
            }

            return;
        }

        if (isConnector(current.item)) {
            const over = shapeAt(point);

            current.item.to = pinTo(over, point);
            showTarget(point, over);

            return;
        }

        // Held, a part follows the pointer until it is let go
        if (isSymbol(current.item)) {
            Object.assign(current.item, underPointer(current.item, point));

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
        if (erasedTo) {
            const gone = [...erasing.value];
            erasedTo = null;
            erasing.value = new Set();
            board.remove(gone);

            return;
        }

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

            // A line with an end on a pin, or on a wire, is a wire: it has
            // no head, and joins the wire it was dropped on
            joinToWire(item, 'from');
            joinToWire(item, 'to');

            if (isWire(item, board.byId.value)) {
                item.startHead = 'none';
                item.endHead = 'none';
            }

            tool.value = 'select';
            drewJustNow = true;

            return;
        }

        if (isStroke(item) && item.points.length < 4) {
            return;
        }

        // The pen stays in the hand: writing is one stroke after another,
        // and each left selected would put its handles under the next
        if (item.kind === 'draw') {
            board.add(item, { keepSelection: true });
            drewJustNow = true;

            return;
        }

        board.add(item);
        tool.value = 'select';
        drewJustNow = true;

        // Something to write on is written on straight away; of the symbols,
        // only one with a value to give or a net to name
        const symbol = isSymbol(item) ? symbolOf(item.symbol) : undefined;
        // (a module already says which board or sensor it is)
        const written = symbol
            ? (!!symbol.prefix && !symbol.module) ||
              symbol.role === 'power' ||
              symbol.role === 'terminal' ||
              symbol.role === 'label'
            : hasText(item) && item.kind !== 'frame';

        if (written) {
            void nextTick(() => startEditing(item.id));
        }
    };

    /**
     * Lets go of whatever is half drawn or half chosen, keeping none of it:
     * the pointer was taken away -- by the browser, or by a second finger
     * coming down to move the board.
     */
    const cancelPointer = () => {
        // Let go of mid-sweep (Escape): nothing is taken
        erasedTo = null;
        erasing.value = new Set();
        draft.value = null;
        marqueeStart = null;
        marquee.value = null;
        clearTarget();
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
        dragHomes = groupKeys(board.items.value);

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

            // A connector is wherever its ends are, and its points are already
            // in board coordinates: giving it an x and a y as well would draw
            // the whole line over again, shifted by the drag.
            if (isConnector(item)) {
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
            // A part keeps to the grid its pins are on, however many are
            // dragged with it: they were on it already, and stay on it
            if (isSymbol(item)) {
                const scale = camera.scale.value;
                const at = snapped(item, {
                    x: (position.x - camera.position.value.x) / scale,
                    y: (position.y - camera.position.value.y) / scale,
                });

                return {
                    x: at.x * scale + camera.position.value.x,
                    y: at.y * scale + camera.position.value.y,
                };
            }

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
        // Put down on another frame, it comes up on top of that frame rather
        // than staying wherever it was in the stack -- under it, as often as not
        const homes = dragHomes;
        const now = groupKeys(board.items.value);
        const dropped = homes
            ? [...(dragOrigin?.keys() ?? [])].filter(
                  (id) => now.get(id) !== homes.get(id),
              )
            : [];

        if (dragOrigin) {
            board.settle(dropped);
        }

        dragHomes = null;
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
                // One that hugs its words is as tall as they are at its new width
                item.height =
                    huggedHeight({ ...item, width: item.width }) ??
                    Math.max(12, item.height * scaleY);
            }

            node.scaleX(1);
            node.scaleY(1);
        });
    };

    // A mouse wheel zooms, as it always has. A pinch arrives as a wheel with
    // Ctrl held and small steps, so it zooms by as much as the fingers moved;
    // a trackpad's two-finger scroll, which moves sideways as well, pans.
    const onWheel = (event: Konva.KonvaEventObject<WheelEvent>) => {
        const wheel = event.evt;

        wheel.preventDefault();

        if (wheel.ctrlKey || wheel.metaKey) {
            camera.zoomBy(Math.exp(-wheel.deltaY * 0.01));

            return;
        }

        if (wheel.deltaX !== 0 || wheel.shiftKey) {
            const across = wheel.shiftKey && !wheel.deltaX ? wheel.deltaY : 0;

            camera.position.value = {
                x: camera.position.value.x - wheel.deltaX - across,
                y: camera.position.value.y - (across ? 0 : wheel.deltaY),
            };

            return;
        }

        camera.zoomBy(wheel.deltaY > 0 ? 0.92 : 1.08);
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
        erasing,
        guides,
        marquee,
        shapeAt,
        onPointerDown,
        onPointerMove,
        onPointerUp,
        cancelPointer,
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
