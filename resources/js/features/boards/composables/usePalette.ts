import { useLocalStorage } from '@vueuse/core';
import { PALETTE } from './items';

/** As many as three rows of swatches hold. */
export const MOST_COLOURS = 28;

// One list for every picker on the page, so a colour kept from the fill picker
// shows up under the line one too. Remembered across boards, like the panels:
// it is the person's own set of colours, not something about one board.
const colours = useLocalStorage<string[]>('board.palette', [...PALETTE]);

const same = (a: string, b: string) => a.toLowerCase() === b.toLowerCase();

/**
 * The swatches over the colour pickers: the board's own to start with, and
 * after that whatever the person keeps there.
 */
export function usePalette() {
    const has = (colour: string) =>
        colours.value.some((kept) => same(kept, colour));

    const keep = (colour: string) => {
        if (has(colour) || colours.value.length >= MOST_COLOURS) {
            return;
        }

        colours.value = [...colours.value, colour.toLowerCase()];
    };

    const drop = (colour: string) => {
        colours.value = colours.value.filter((kept) => !same(kept, colour));
    };

    const reset = () => {
        colours.value = [...PALETTE];
    };

    return { colours, has, keep, drop, reset };
}
