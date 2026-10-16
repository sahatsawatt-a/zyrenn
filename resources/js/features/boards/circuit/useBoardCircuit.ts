import { useEventListener, useIntervalFn } from '@vueuse/core';
import type Konva from 'konva';
import type { Ref } from 'vue';
import { computed, ref } from 'vue';
import type { useBoard } from '../canvas/useBoard';
import type { Item, Tool } from '../model/items';
import {
    hasLogic,
    isOperable,
    isPressable,
    newMemory,
    simulate,
} from './logic';
import { isSymbol, snapped, symbolOf, turned } from './symbols';
import { boardValues } from './values';

type Circuit = {
    board: ReturnType<typeof useBoard>;
    tool: Ref<Tool>;
    presenting: Ref<boolean>;
    /** The stage, for the pointer over a part that answers a click. */
    stage: () => Konva.Stage | undefined;
    /** What a click does when it is not on a switch: the canvas's own. */
    click: (event: Konva.KonvaEventObject<MouseEvent>) => void;
};

/**
 * A circuit drawn on the board: its voltages and currents worked out for
 * whoever asks, its parts turned a quarter at a time, and its logic live --
 * a switch flipped, a push-button held, a clock ticking, and the gates
 * between them working out what follows.
 */
export function useBoardCircuit({
    board,
    tool,
    presenting,
    stage,
    click,
}: Circuit) {
    // Shown once there is a circuit to read: a board of sticky notes has none
    const hasCircuit = computed(() => board.items.value.some(isSymbol));

    // A circuit with something to drive it can be worked out: its voltages and
    // currents written on it, for whoever asks. A board with none has no button.
    const canWorkOut = computed(() =>
        board.items.value.some((item) => {
            const symbol = isSymbol(item) ? symbolOf(item.symbol) : undefined;

            return !!symbol && (symbol.source || symbol.role === 'power');
        }),
    );
    const showValues = ref(false);
    const worked = computed(() =>
        showValues.value && canWorkOut.value
            ? boardValues(board.items.value)
            : null,
    );

    /** A quarter turn for each selected part, about its own middle. */
    const turnSelection = () => {
        const parts = board.selected.value.filter(isSymbol);

        if (!parts.length) {
            return false;
        }

        board.commit();
        parts.forEach((item) => {
            Object.assign(item, turned(item));
            // A turn can leave pins between grid points: back onto it
            Object.assign(item, snapped(item));
        });

        return true;
    };

    // ---------------------------------------------------------- Live parts
    // Some things on a board do something: a logic input is a switch, a clock
    // ticks, and the gates between them work out what follows. They answer a
    // click directly -- while the board is being drawn as well as while it is
    // presented -- so there is no mode to turn on first. What is high is
    // worked out all the time, and a circuit shows what it does while it is
    // being built.
    const logical = computed(() => hasLogic(board.items.value));
    const beat = ref(false);
    const memory = newMemory();

    // The push-buttons held down just now: on for as long as they are, and
    // nothing of it kept
    const held = ref(new Map<string, boolean>());

    const signals = computed(() =>
        logical.value
            ? simulate(board.items.value, {
                  clock: beat.value,
                  inputs: held.value,
                  memory,
              })
            : null,
    );

    // The beat only runs while some clock is switched on
    const ticking = computed(() =>
        board.items.value.some(
            (item) => item.on && symbolOf(item.symbol)?.logic === 'clock',
        ),
    );

    useIntervalFn(() => {
        if (ticking.value) {
            beat.value = !beat.value;
        }
    }, 500);

    /** What is under the pointer, if it is something that answers it. */
    const partAt = (
        event: Konva.KonvaEventObject<Event>,
        answers: (item: Item) => boolean,
    ) => {
        const id = event.target.id() || event.target.getParent()?.id();
        const item = id ? board.byId.value.get(id) : undefined;

        return item && !item.locked && answers(item) ? item : null;
    };

    // A push-button is on from the moment it is pressed until the pointer is
    // let go, wherever that is
    const onStagePress = (event: Konva.KonvaEventObject<Event>) => {
        const button =
            presenting.value || tool.value === 'select'
                ? partAt(event, isPressable)
                : null;

        if (button) {
            held.value = new Map([[button.id, true]]);
        }
    };

    useEventListener(window, ['pointerup', 'pointercancel'], () => {
        if (held.value.size) {
            held.value = new Map();
        }
    });

    const onStageClick = (event: Konva.KonvaEventObject<MouseEvent>) => {
        // A click flips a switch, or starts and stops a clock. With Shift it is
        // only adding to the selection, and with a tool in hand it is drawing
        const item =
            (presenting.value || tool.value === 'select') && !event.evt.shiftKey
                ? partAt(event, isOperable)
                : null;

        // Flipped, and left where it is: a switch is not picked up by being
        // used. It is moved by dragging it, and chosen with Shift or a box
        if (item) {
            item.on = !item.on;

            return;
        }

        // Nor is a push-button by being pressed
        if (!event.evt.shiftKey && partAt(event, isPressable)) {
            return;
        }

        click(event);
    };

    // The pointer says so, over anything that answers a click
    const onStageHover = (event: Konva.KonvaEventObject<MouseEvent>) => {
        const container = stage()?.container();

        if (container) {
            container.style.cursor =
                event.type === 'mouseover' &&
                partAt(event, (item) => isOperable(item) || isPressable(item))
                    ? 'pointer'
                    : '';
        }
    };

    return {
        hasCircuit,
        canWorkOut,
        showValues,
        worked,
        signals,
        turnSelection,
        onStagePress,
        onStageClick,
        onStageHover,
    };
}
