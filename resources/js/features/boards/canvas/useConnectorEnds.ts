import type Konva from 'konva';
import type { ComputedRef, Ref } from 'vue';
import { computed, ref } from 'vue';
import {
    isSymbol,
    nearestPin,
    wordsOf,
} from '@/features/boards/circuit/symbols';
import { anchorsOf } from '../model/geometry';
import type { Anchor } from '../model/geometry';
import type { Endpoint, Item, Side } from '../model/items';
import { isConnectable, isConnector } from '../model/items';
import { hasRows, rowNamed } from '../model/rows';
import { shapeOf } from '../model/shapes';

/** The name a handle carries, so a drag can tell which end it has hold of. */
export const ENDPOINT = 'endpoint';

/** How near an anchor dot the pointer has to come to catch it, in pixels. */
const ANCHOR_GRAB = 14;

type Ends = {
    board: {
        items: Ref<Item[]>;
        byId: ComputedRef<Map<string, Item>>;
        selected: ComputedRef<Item[]>;
    };
    camera: { scale: Ref<number> };
    /** What is under a point on the board, if a connector may pin to it. */
    shapeAt: (point: { x: number; y: number }) => Item | null;
    /** No handles while presenting: a slide is not for editing. */
    presenting: Ref<boolean>;
    /** The drawn path of a connector, from what it is pinned to. */
    connectorPath: (item: Item) => number[];
};

/**
 * Where a connector's ends are: the dots it can hold on to, the handles for
 * moving them, and what happens when one is dropped.
 */
export function useConnectorEnds({
    board,
    camera,
    shapeAt,
    presenting,
    connectorPath,
}: Ends) {
    /** The shapes a connector may pin itself to, hidden ones aside. */
    const connectable = computed(() =>
        board.items.value.filter((item) => isConnectable(item) && !item.hidden),
    );

    /** The shape being aimed at, and the dot under the pointer. */
    const hoveredTarget = ref<string | null>(null);
    const hoveredAnchor = ref<{
        id: string;
        side: Side | null;
        pin?: string;
    } | null>(null);

    /** Whether this is the dot the pointer is about to drop an end on. */
    const isAimedAt = (id: string, side: Side) =>
        hoveredAnchor.value?.id === id && hoveredAnchor.value.side === side;

    /** Lights the shape being aimed at, and the dot under the pointer if there is one. */
    const showTarget = (point: { x: number; y: number }, over: Item | null) => {
        const anchor = anchorNear(point);

        hoveredTarget.value = anchor?.id ?? over?.id ?? null;
        hoveredAnchor.value = anchor
            ? { id: anchor.id, side: anchor.side, pin: anchor.pin }
            : null;
    };

    /** The anchor dot under the pointer, if it is close enough to one. */
    const anchorNear = (point: { x: number; y: number }) => {
        let closest = ANCHOR_GRAB / camera.scale.value;
        let found: (Anchor & { id: string }) | null = null;

        for (const shape of connectable.value) {
            for (const anchor of anchorsOf(shape)) {
                const away = Math.hypot(anchor.x - point.x, anchor.y - point.y);

                if (away <= closest) {
                    closest = away;
                    found = { id: shape.id, ...anchor };
                }
            }
        }

        return found;
    };

    /**
     * Where a connector should pin itself when it meets the board at `point`.
     *
     * Dropped on one of a shape's anchor dots, the end keeps that face however the
     * shapes move afterwards -- the same bargain a diagram tool makes with its
     * ports. Dropped anywhere else on a shape, the end stays free to turn, and
     * leaves by whichever face is pointing at the other end at the time.
     */
    const pinTo = (
        item: Item | null,
        point: { x: number; y: number },
    ): Endpoint => {
        // On a lifeline an end stays as far down it as it was dropped: that
        // is where in the order of things its message comes
        if (item && shapeOf(item.kind)?.slide) {
            return {
                item: item.id,
                side: null,
                along: Math.min(
                    1,
                    Math.max(0, (point.y - item.y) / Math.max(1, item.height)),
                ),
                x: point.x,
                y: point.y,
            };
        }

        const anchor = anchorNear(point);

        // On a symbol the dots are its pins, and an end is always on one:
        // which pins a wire joins is the circuit, and must not change
        // because a part was moved. The way it leaves is the pin's own.
        if (anchor?.pin) {
            return {
                item: anchor.id,
                side: null,
                pin: anchor.pin,
                x: anchor.x,
                y: anchor.y,
            };
        }

        if (anchor) {
            return {
                item: anchor.id,
                side: anchor.side,
                x: anchor.x,
                y: anchor.y,
            };
        }

        if (item && isSymbol(item)) {
            const pin = nearestPin(item, point);

            return {
                item: item.id,
                side: null,
                pin: pin?.name ?? null,
                x: pin?.x ?? point.x,
                y: pin?.y ?? point.y,
            };
        }

        return {
            item: item?.id ?? null,
            side: null,
            x: point.x,
            y: point.y,
        };
    };

    // One selected connector gets a handle on each end, so it can be re-aimed at a
    // different shape without redrawing it.
    const draggingEnd = ref(false);

    const soleConnector = computed(() => {
        const selected = board.selected.value;

        return selected.length === 1 && isConnector(selected[0])
            ? selected[0]
            : null;
    });

    // What the selected connector joins, so the panel can say so and a re-aimed
    // end is visible without hunting for it on the canvas.
    const connectorLink = computed(() => {
        const item = soleConnector.value;

        if (!item) {
            return undefined;
        }

        const name = (end: 'from' | 'to') => {
            const host = item[end]?.item
                ? board.byId.value.get(item[end]!.item!)
                : null;

            if (host && isSymbol(host)) {
                const pin = item[end]?.pin;

                return `${wordsOf(host).name || host.symbol}${pin ? `.${pin}` : ''}`;
            }

            // On a table or a class, the row it is on: "orders.customer_id"
            const row =
                host && hasRows(host) && item[end]?.pin
                    ? rowNamed(host, item[end]!.pin!)
                    : undefined;

            if (host && row) {
                return `${host.text || host.kind}.${row.name}`;
            }

            return host ? host.text || host.kind : 'a point';
        };

        return `${name('from')} → ${name('to')}`;
    });

    const endpointHandles = computed(() => {
        const item = soleConnector.value;

        if (!item || presenting.value) {
            return [];
        }

        // Taken from the drawn path, not from the stored sides: the line picks its
        // sides from where the shapes are now, and a handle that used the stored
        // side sat inside the shape instead of on the end of the line.
        const points = connectorPath(item);

        if (points.length < 4) {
            return [];
        }

        return [
            { end: 'from' as const, x: points[0], y: points[1] },
            {
                end: 'to' as const,
                x: points[points.length - 2],
                y: points[points.length - 1],
            },
        ];
    });

    const onEndpointDragMove = (event: Konva.KonvaEventObject<DragEvent>) => {
        const item = soleConnector.value;
        // Which end is being dragged rides in the node's name: reading it back off
        // a typed attr fights Konva's own generics for no gain.
        const end = event.target.name().endsWith('from') ? 'from' : 'to';

        if (!item) {
            return;
        }

        const point = { x: event.target.x(), y: event.target.y() };
        const over = shapeAt(point);

        item[end] = pinTo(over, point);

        showTarget(point, over);

        // Konva keeps a dragged node under the pointer, which would leave the
        // handle sitting inside the shape while the line snapped to its edge. Put
        // it back on the end of the line every frame, so it clings to the anchor.
        const settled = connectorPath(item);

        if (settled.length >= 4) {
            event.target.position(
                end === 'from'
                    ? { x: settled[0], y: settled[1] }
                    : {
                          x: settled[settled.length - 2],
                          y: settled[settled.length - 1],
                      },
            );
        }
    };

    return {
        ENDPOINT,
        connectable,
        hoveredTarget,
        hoveredAnchor,
        draggingEnd,
        soleConnector,
        connectorLink,
        endpointHandles,
        isAimedAt,
        showTarget,
        pinTo,
        onEndpointDragMove,
    };
}
