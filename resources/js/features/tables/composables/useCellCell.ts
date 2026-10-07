import { ref } from 'vue';

// How a cell's own input looks: no box of its own, the cell is the box
export const CELL_INPUT =
    'h-full w-full min-w-0 truncate bg-transparent px-2 text-sm text-foreground outline-none placeholder:text-muted-foreground/50';

// A cell that opens a list to pick from -- a select, a person -- drawn the same way
export const CELL_PICKER =
    'flex h-full min-h-7 w-full min-w-0 cursor-pointer items-center gap-1 overflow-hidden rounded px-2 text-left text-sm outline-none';

/** The same as a link has to be to open, whatever was typed. */
export const ensureHttp = (url: string): string =>
    !url || /^https?:\/\//i.test(url) ? url : `https://${url}`;

/** An amount as money, with the column's symbol. */
export const formatCurrency = (value: number, symbol = '$'): string =>
    isNaN(value)
        ? '-'
        : `${symbol}${value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/** The letters on a person's round badge. */
export const initialsOf = (name: string): string => {
    const parts = name.trim().split(/\s+/);

    return (
        parts.length >= 2 ? parts[0][0] + parts[1][0] : name.slice(0, 2)
    ).toUpperCase();
};

// What each cell editor keeps for itself: whether its list is open, what is
// typed into that list's search, and the star under the pointer.
export function useCellCell() {
    return {
        isOpen: ref(false),
        searchQuery: ref(''),
        hoverRating: ref<number | null>(null),
    };
}
