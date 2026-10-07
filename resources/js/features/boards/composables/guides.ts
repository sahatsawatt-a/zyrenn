// The invisible ruler: what a dragged box should line up with, and the lines
// to draw while it does.

/** A line the board shows while something is being dragged into line. */
export type Guide = {
    axis: 'x' | 'y';
    at: number;
    from: number;
    to: number;
};

type Box = { x: number; y: number; width: number; height: number };

/**
 * The nudge that puts a dragged box in line with the ones around it, and the
 * guides to draw for it: the "invisible ruler" of a board app. Each box offers
 * three lines per axis -- its two edges and its middle -- and the closest match
 * within `tolerance` wins.
 */
export const alignmentFor = (
    moving: Box,
    others: Box[],
    tolerance: number,
): { dx: number; dy: number; guides: Guide[] } => {
    const verticals = (box: Box) => [
        box.x,
        box.x + box.width / 2,
        box.x + box.width,
    ];
    const horizontals = (box: Box) => [
        box.y,
        box.y + box.height / 2,
        box.y + box.height,
    ];

    const best = (
        mine: number[],
        theirs: (box: Box) => number[],
    ): { shift: number; at: number; box: Box } | null => {
        let found: { shift: number; at: number; box: Box } | null = null;

        for (const box of others) {
            for (const line of theirs(box)) {
                for (const own of mine) {
                    const shift = line - own;

                    if (
                        Math.abs(shift) <= tolerance &&
                        (!found || Math.abs(shift) < Math.abs(found.shift))
                    ) {
                        found = { shift, at: line, box };
                    }
                }
            }
        }

        return found;
    };

    const vertical = best(verticals(moving), verticals);
    const horizontal = best(horizontals(moving), horizontals);

    const guides: Guide[] = [];

    // The guide runs the length of both boxes, so it reads as a ruler held
    // against them rather than a line crossing the whole board.
    if (vertical) {
        guides.push({
            axis: 'x',
            at: vertical.at,
            from: Math.min(moving.y, vertical.box.y) - 16,
            to:
                Math.max(
                    moving.y + moving.height,
                    vertical.box.y + vertical.box.height,
                ) + 16,
        });
    }

    if (horizontal) {
        guides.push({
            axis: 'y',
            at: horizontal.at,
            from: Math.min(moving.x, horizontal.box.x) - 16,
            to:
                Math.max(
                    moving.x + moving.width,
                    horizontal.box.x + horizontal.box.width,
                ) + 16,
        });
    }

    return {
        dx: vertical?.shift ?? 0,
        dy: horizontal?.shift ?? 0,
        guides,
    };
};
