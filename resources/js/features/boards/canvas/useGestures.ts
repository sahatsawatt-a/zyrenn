import { useEventListener } from '@vueuse/core';
import Konva from 'konva';
import type { ComputedRef, Ref } from 'vue';
import type { Tool } from '../model/items';

type Point = { x: number; y: number };

type Gestures = {
    stage: () => Konva.Stage | null | undefined;
    /** What the stage is laid in: where a pointer's leaving is listened for. */
    surface: Readonly<Ref<HTMLElement | null>>;
    camera: {
        position: Ref<Point>;
        zoomBy: (factor: number, anchor?: Point) => void;
    };
    tool: Ref<Tool>;
    /** The board is being moved by other means: Space held, or presenting. */
    panning: ComputedRef<boolean>;
    select: (ids: string[]) => void;
    /** What one pointer does to the board (useDrawing). */
    onPointerDown: (event: Konva.KonvaEventObject<PointerEvent>) => void;
    onPointerMove: (event?: Konva.KonvaEventObject<PointerEvent>) => void;
    onPointerUp: () => void;
    cancelPointer: () => void;
};

/**
 * Which pointer is doing what, on a screen that is touched.
 *
 * A mouse is one pointer and needs none of this. A tablet has a pen, a hand
 * resting beside it and fingers that want to move the board about, all at
 * once: one of them draws, and only that one; a finger on the bare board
 * slides it, as it slides every other page; two fingers slide and pinch it,
 * whatever the tool, and whatever was half drawn by the first is dropped.
 */
export function useGestures({
    stage,
    surface,
    camera,
    tool,
    panning,
    select,
    onPointerDown,
    onPointerMove,
    onPointerUp,
    cancelPointer,
}: Gestures) {
    // The fingers down, by pointer, where each is on the canvas
    const fingers = new Map<number, Point>();
    // The one pointer drawing, choosing or dragging, if any
    let working: number | null = null;
    // What the fingers are doing: sliding the board with one, or pinching it
    let moving: 'slide' | 'pinch' | null = null;
    let slid = 0;
    let pinch: { centre: Point; apart: number } | null = null;

    const onCanvas = (pointer: PointerEvent): Point => {
        const box = stage()?.container().getBoundingClientRect();

        return {
            x: pointer.clientX - (box?.left ?? 0),
            y: pointer.clientY - (box?.top ?? 0),
        };
    };

    const between = () => {
        const [a, b] = [...fingers.values()];

        return {
            centre: { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 },
            apart: Math.max(1, Math.hypot(a.x - b.x, a.y - b.y)),
        };
    };

    const slideBy = (dx: number, dy: number) => {
        camera.position.value = {
            x: camera.position.value.x + dx,
            y: camera.position.value.y + dy,
        };
    };

    /** Whatever a first finger had begun is not what was meant after all. */
    const letGo = () => {
        cancelPointer();
        working = null;
        // A shape it was on is put down too, whether it had been moved yet
        // or was only held
        [...Konva.DD._dragElements.values()].forEach(({ node }) =>
            node.stopDrag(),
        );
    };

    const down = (event: Konva.KonvaEventObject<PointerEvent>) => {
        const pointer = event.evt;

        if (pointer.pointerType === 'touch' && !panning.value) {
            fingers.set(pointer.pointerId, onCanvas(pointer));

            if (fingers.size === 2) {
                letGo();
                moving = 'pinch';
                pinch = between();

                return;
            }

            if (fingers.size > 2 || moving) {
                return;
            }

            // On the bare board with nothing to draw, a finger moves it
            if (tool.value === 'select' && event.target === stage()) {
                moving = 'slide';
                slid = 0;

                return;
            }
        }

        // A palm coming down while the pen writes is not a second pen. (A
        // mouse is only ever one pointer: it is never kept waiting.)
        if (working !== null && pointer.pointerType !== 'mouse') {
            return;
        }

        working = pointer.pointerId;
        onPointerDown(event);

        // A drag that wanders over a panel laid on the canvas still belongs
        // to the canvas: without this, letting go over one is never heard,
        // and a marquee or a half-drawn shape is left hanging from the pointer
        try {
            stage()?.content.setPointerCapture(pointer.pointerId);
        } catch {
            // a pointer already gone: nothing to hold on to
        }
    };

    // The fingers are followed on the page itself, not through the stage:
    // Konva says nothing of a pointer while it believes a shape is being
    // dragged, which is just when a second finger comes down to pinch
    const fingerMoved = (pointer: PointerEvent) => {
        const before = fingers.get(pointer.pointerId);

        if (!before) {
            return;
        }

        const now = onCanvas(pointer);
        fingers.set(pointer.pointerId, now);

        if (moving === 'pinch' && pinch && fingers.size >= 2) {
            const next = between();

            camera.zoomBy(next.apart / pinch.apart, next.centre);
            slideBy(
                next.centre.x - pinch.centre.x,
                next.centre.y - pinch.centre.y,
            );
            pinch = next;
        } else if (moving === 'slide') {
            slideBy(now.x - before.x, now.y - before.y);
            slid += Math.hypot(now.x - before.x, now.y - before.y);
        }
    };

    const move = (event: Konva.KonvaEventObject<PointerEvent>) => {
        const pointer = event.evt;

        // A finger moving the board draws nothing
        if (moving && fingers.has(pointer.pointerId)) {
            return;
        }

        // With nothing held, every pointer is heard: a connector being
        // aimed lights up what the mouse is over
        if (working === null || working === pointer.pointerId) {
            onPointerMove(event);
        }
    };

    /** A pointer gone: lifted, or -- `taken` -- pulled away by the browser. */
    const ended = (pointer: PointerEvent, taken: boolean) => {
        if (fingers.delete(pointer.pointerId) && moving) {
            if (fingers.size === 0) {
                // A tap on the bare board, as a click there: nothing chosen
                if (moving === 'slide' && slid < 6 && !taken) {
                    select([]);
                }

                moving = null;
                pinch = null;
            }

            return;
        }

        if (working !== pointer.pointerId) {
            return;
        }

        working = null;

        if (taken) {
            cancelPointer();
        } else {
            onPointerUp();
        }
    };

    useEventListener(surface, 'pointermove', fingerMoved);
    useEventListener(surface, 'pointerup', (pointer) => ended(pointer, false));
    useEventListener(surface, 'pointercancel', (pointer) =>
        ended(pointer, true),
    );

    return { onStagePointerDown: down, onStagePointerMove: move };
}
