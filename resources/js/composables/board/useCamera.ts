import Konva from 'konva';
import type { Ref } from 'vue';
import { ref } from 'vue';

type Box = { x: number; y: number; width: number; height: number };

/** Screen kept clear on either side -- by a panel laid over the canvas. */
type Inset = { left?: number; right?: number };

const MIN_SCALE = 0.05;
const MAX_SCALE = 4;

/**
 * Where the board is looked at from.
 *
 * The camera is plain state rather than reading back from the Konva stage, so
 * zooming, fitting and presenting all go through one place and the readout
 * never disagrees with what is drawn. Jumps between frames are tweened by
 * Konva, then written back here when the tween lands.
 */
export function useCamera(
    stage: () => Konva.Stage | undefined,
    viewport: { width: Ref<number>; height: Ref<number> },
) {
    const scale = ref(0.6);
    const position = ref({ x: 120, y: 80 });
    const animating = ref(false);

    // The tween in flight, if any. A later focus() must cancel it: otherwise it
    // lands afterwards and writes its own -- by then wrong -- values back.
    let flight: Konva.Tween | null = null;

    const stopFlight = () => {
        flight?.destroy();
        flight = null;
        animating.value = false;
    };

    const clamp = (value: number) =>
        Math.min(MAX_SCALE, Math.max(MIN_SCALE, value));

    /** Screen point to board point. */
    const toBoard = (point: { x: number; y: number }) => ({
        x: (point.x - position.value.x) / scale.value,
        y: (point.y - position.value.y) / scale.value,
    });

    /** The board rectangle currently on screen. */
    const visibleBox = (): Box => {
        const topLeft = toBoard({ x: 0, y: 0 });

        return {
            x: topLeft.x,
            y: topLeft.y,
            width: viewport.width.value / scale.value,
            height: viewport.height.value / scale.value,
        };
    };

    const zoomBy = (factor: number, anchor?: { x: number; y: number }) => {
        stopFlight();

        const target = stage();
        const point = anchor ??
            target?.getPointerPosition() ?? {
                x: viewport.width.value / 2,
                y: viewport.height.value / 2,
            };

        const next = clamp(scale.value * factor);
        const board = toBoard(point);

        scale.value = next;
        position.value = {
            x: point.x - board.x * next,
            y: point.y - board.y * next,
        };
    };

    const cameraFor = (box: Box, padding = 80, inset: Inset = {}) => {
        const width = Math.max(box.width, 1);
        const height = Math.max(box.height, 1);

        // On a narrow canvas the panels would leave nothing, so they are
        // fitted under rather than around
        const left = inset.left ?? 0;
        const room = viewport.width.value - left - (inset.right ?? 0);
        const across = room >= viewport.width.value / 2 ? room : null;

        const next = clamp(
            Math.min(
                ((across ?? viewport.width.value) - padding * 2) / width,
                (viewport.height.value - padding * 2) / height,
            ),
        );

        return {
            scale: next,
            x:
                (across === null
                    ? (viewport.width.value - width * next) / 2
                    : left + (across - width * next) / 2) -
                box.x * next,
            y: (viewport.height.value - height * next) / 2 - box.y * next,
        };
    };

    /** Move the camera so `box` fills the viewport, optionally gliding there. */
    const focus = (
        box: Box | null,
        {
            animate = false,
            padding = 80,
            inset = {},
        }: { animate?: boolean; padding?: number; inset?: Inset } = {},
    ) => {
        if (!box || !viewport.width.value) {
            return;
        }

        const next = cameraFor(box, padding, inset);
        const target = stage();

        stopFlight();

        if (!animate || !target) {
            scale.value = next.scale;
            position.value = { x: next.x, y: next.y };

            return;
        }

        // Tween the Konva stage, then hand the values back to the refs. While
        // it runs the stage owns its own transform, so the template must not
        // fight it -- hence `animating`.
        animating.value = true;

        flight = new Konva.Tween({
            node: target,
            duration: 0.45,
            // Called through a wrapper so the easing stays bound to Konva
            easing: (t: number, from: number, by: number, duration: number) =>
                Konva.Easings.EaseInOut(t, from, by, duration),
            scaleX: next.scale,
            scaleY: next.scale,
            x: next.x,
            y: next.y,
            onFinish: () => {
                scale.value = next.scale;
                position.value = { x: next.x, y: next.y };
                flight = null;
                animating.value = false;
            },
        });

        flight.play();
    };

    const reset = () => {
        stopFlight();
        scale.value = 0.6;
        position.value = { x: 120, y: 80 };
    };

    return {
        scale,
        position,
        animating,
        toBoard,
        visibleBox,
        zoomBy,
        focus,
        reset,
    };
}
