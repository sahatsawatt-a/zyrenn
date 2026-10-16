import { useEventListener } from '@vueuse/core';
import type { Ref } from 'vue';
import type { Tool } from '../model/items';

/** The letter each tool answers to, as the shape library shows. */
const TOOL_KEYS: Record<string, Tool> = {
    v: 'select',
    s: 'sticky',
    t: 'text',
    e: 'math',
    r: 'rect',
    p: 'pill',
    o: 'ellipse',
    g: 'triangle',
    m: 'diamond',
    h: 'hexagon',
    k: 'star',
    b: 'cylinder',
    i: 'parallelogram',
    u: 'document',
    n: 'process',
    c: 'cloud',
    a: 'arrow',
    d: 'draw',
    l: 'path',
    f: 'frame',
};

type Keys = {
    board: {
        undo: () => void;
        redo: () => void;
        duplicate: () => void;
        reorder: (to: 'front' | 'back' | 'forward' | 'backward') => void;
        select: (ids: string[]) => void;
    };
    tool: Ref<Tool>;
    spaceHeld: Ref<boolean>;
    editingId: Ref<string | null>;
    stopEditing: (keep?: boolean) => void;
    presenting: Ref<boolean>;
    frameIndex: Ref<number>;
    showFrame: (index: number) => void;
    stopPresenting: () => void;
    removeSelection: () => void;
    /** Turns the selected parts a quarter turn; false when there are none. */
    turnSelection: () => boolean;
};

/**
 * What the keyboard does to a board: the shortcuts a drawing tool is expected
 * to have, the arrows that move a presentation along, and the space bar that
 * turns the pointer into a hand.
 */
export function useShortcuts({
    board,
    tool,
    spaceHeld,
    editingId,
    stopEditing,
    presenting,
    frameIndex,
    showFrame,
    stopPresenting,
    removeSelection,
    turnSelection,
}: Keys) {
    useEventListener(window, 'keydown', (event: KeyboardEvent) => {
        // A key pressed in something opened over the board -- a colour's
        // swatches, a dialog, a menu -- is that thing's: Esc closes it, and
        // leaves chosen whatever it was opened for
        const over = '[role="dialog"], [role="menu"], [role="listbox"]';

        if (
            (event.target instanceof Element && event.target.closest(over)) ||
            // Esc with one of them open closes it, wherever the focus went.
            // (Open: one still fading out after it was closed is no longer
            // in the way, and the next Esc is the board's)
            (event.key === 'Escape' &&
                document.querySelector(`:is(${over})[data-state="open"]`))
        ) {
            return;
        }

        // In something that takes letters. A slider or a switch just used
        // still has the focus, and is not being typed into: Esc after one
        // lets go of the selection like anywhere else
        const focused = document.activeElement;
        const typing =
            editingId.value !== null ||
            (focused instanceof HTMLInputElement &&
                !['range', 'checkbox', 'radio', 'button'].includes(
                    focused.type,
                )) ||
            focused instanceof HTMLTextAreaElement;

        if (event.code === 'Space' && !typing) {
            spaceHeld.value = true;
            event.preventDefault();
        }

        if (presenting.value) {
            if (event.key === 'ArrowRight' || event.key === 'PageDown') {
                showFrame(frameIndex.value + 1);
            }
            if (event.key === 'ArrowLeft' || event.key === 'PageUp') {
                showFrame(frameIndex.value - 1);
            }
            if (event.key === 'Escape') {
                stopPresenting();
            }

            return;
        }

        if (typing) {
            if (event.key === 'Escape') {
                stopEditing(false);
            }

            return;
        }

        const meta = event.ctrlKey || event.metaKey;

        if (meta && event.key.toLowerCase() === 'z') {
            event.preventDefault();
            if (event.shiftKey) {
                board.redo();
            } else {
                board.undo();
            }

            return;
        }

        if (meta && (event.key === ']' || event.key === '[')) {
            event.preventDefault();
            board.reorder(
                event.key === ']'
                    ? event.shiftKey
                        ? 'front'
                        : 'forward'
                    : event.shiftKey
                      ? 'back'
                      : 'backward',
            );

            return;
        }

        if (meta && event.key.toLowerCase() === 'd') {
            event.preventDefault();
            board.duplicate();

            return;
        }

        if (event.key === 'Delete' || event.key === 'Backspace') {
            event.preventDefault();
            removeSelection();

            return;
        }

        if (event.key === 'Escape') {
            board.select([]);
            tool.value = 'select';
        }

        // R turns a part, as it does in every schematic editor; with none
        // selected it is the rectangle it always was
        if (event.key.toLowerCase() === 'r' && !meta && turnSelection()) {
            return;
        }

        const next = TOOL_KEYS[event.key.toLowerCase()];

        if (next && !meta) {
            tool.value = next;
        }
    });

    useEventListener(window, 'keyup', (event: KeyboardEvent) => {
        if (event.code === 'Space') {
            spaceHeld.value = false;
        }
    });
}
