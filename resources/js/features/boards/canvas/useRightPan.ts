import { useEventListener } from '@vueuse/core';
import { ref } from 'vue';
import type { Ref } from 'vue';
import type { useCamera } from './useCamera';

type RightPan = {
    // The canvas's own element: what is pressed on
    surface: Ref<HTMLElement | null | undefined>;
    camera: ReturnType<typeof useCamera>;
    /**
     * A right click that did not move: what it opens there, by its point
     * on the canvas (a frame's menu). Answers whether it opened anything.
     */
    click: (point: { x: number; y: number }) => boolean;
};

// How far the pointer may wander and still be a click, in screen pixels
const SLACK = 4;

/**
 * The right button, held and dragged with a mouse or a pen, moves the board
 * about -- as Space and a drag do -- and, let go where it was pressed, opens
 * what a right click opens (a frame's menu). A finger is left as it was.
 *
 * Chrome on Linux and macOS says "contextmenu" as the button goes down, before
 * a drag can be told from a click, so the browser's own menu is never shown
 * on the board: the click is answered as the button comes up instead. The
 * press is kept from the canvas too, so no tool starts drawing with it.
 */
export function useRightPan({ surface, camera, click }: RightPan) {
    const dragging = ref(false);

    let pressed: {
        id: number;
        x: number;
        y: number;
        from: { x: number; y: number };
    } | null = null;

    const mine = (event: PointerEvent | MouseEvent) =>
        !('pointerType' in event) || event.pointerType !== 'touch';

    /** Kept from the canvas beneath, and from the browser's own menu. */
    const keep = (event: Event) => {
        event.preventDefault();
        event.stopPropagation();
    };

    useEventListener(
        surface,
        'pointerdown',
        (event: PointerEvent) => {
            if (event.button !== 2 || !mine(event) || camera.animating.value) {
                return;
            }

            keep(event);
            (event.currentTarget as HTMLElement).setPointerCapture?.(
                event.pointerId,
            );
            pressed = {
                id: event.pointerId,
                x: event.clientX,
                y: event.clientY,
                from: { ...camera.position.value },
            };
        },
        { capture: true },
    );

    useEventListener(
        surface,
        'pointermove',
        (event: PointerEvent) => {
            if (!pressed || event.pointerId !== pressed.id) {
                return;
            }

            keep(event);
            const across = event.clientX - pressed.x;
            const down = event.clientY - pressed.y;

            if (!dragging.value && Math.hypot(across, down) <= SLACK) {
                return;
            }

            dragging.value = true;
            camera.position.value = {
                x: pressed.from.x + across,
                y: pressed.from.y + down,
            };
        },
        { capture: true },
    );

    const letGo = (event: PointerEvent) => {
        if (!pressed || event.pointerId !== pressed.id) {
            return;
        }

        keep(event);
        const area = surface.value?.getBoundingClientRect();
        const still = !dragging.value;

        pressed = null;
        dragging.value = false;

        if (still && area && event.type === 'pointerup') {
            click({
                x: event.clientX - area.left,
                y: event.clientY - area.top,
            });
        }
    };

    useEventListener(surface, 'pointerup', letGo, { capture: true });
    useEventListener(surface, 'pointercancel', letGo, { capture: true });

    // The mouse's own events of the same press, which some of the canvas
    // listens to as well
    for (const name of ['mousedown', 'mouseup'] as const) {
        useEventListener(
            surface,
            name,
            (event: MouseEvent) => event.button === 2 && keep(event),
            { capture: true },
        );
    }

    // Never the browser's menu on the board, from a mouse or a pen; a long
    // press of a finger is still the canvas's to answer
    useEventListener(
        surface,
        'contextmenu',
        (event: MouseEvent) => mine(event) && keep(event),
        { capture: true },
    );

    return { dragging };
}
