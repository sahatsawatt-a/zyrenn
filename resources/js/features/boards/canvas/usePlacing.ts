import type { Ref } from 'vue';
import { boundsOf, boundsOfAll, overlaps } from '../model/geometry';
import type { Item, Tool } from '../model/items';
import { isConnector } from '../model/items';
import type { useBoard } from './useBoard';
import type { useCamera } from './useCamera';

type Placing = {
    board: ReturnType<typeof useBoard>;
    camera: ReturnType<typeof useCamera>;
    width: Ref<number>;
    height: Ref<number>;
    tool: Ref<Tool>;
    /** The part of the canvas the panels leave uncovered. */
    uncovered: () => { left: number; right: number; bottom: number };
};

/**
 * Something made whole elsewhere -- a template's diagram, a circuit laid out
 * from text -- put down on the board where it can be seen.
 */
export function usePlacing({
    board,
    camera,
    width,
    height,
    tool,
    uncovered,
}: Placing) {
    const middleOfView = () =>
        camera.toBoard({ x: width.value / 2, y: height.value / 2 });

    /** A circuit laid out from text, put down in the middle of what is in view. */
    const placeCircuit = (items: Item[]) => {
        const box = boundsOfAll(items.filter((item) => !isConnector(item)));
        const middle = middleOfView();

        if (!box) {
            return;
        }

        // Moved by whole grid steps, so its pins stay on the grid
        const step = 20;
        const dx = Math.round((middle.x - box.x - box.width / 2) / step) * step;
        const dy =
            Math.round((middle.y - box.y - box.height / 2) / step) * step;

        board.place(
            items.map((item) =>
                isConnector(item)
                    ? item
                    : { ...item, x: item.x + dx, y: item.y + dy },
            ),
        );
        tool.value = 'select';
    };

    /**
     * A template's diagram, put down in the middle of what is in view -- or,
     * where that would be on top of something, in the clear under everything
     * there is -- and the view brought to show all of it.
     */
    const placeTemplate = (items: Item[]) => {
        const box = boundsOfAll(items.filter((item) => !isConnector(item)));
        const middle = middleOfView();

        if (!box) {
            return;
        }

        let dx = Math.round(middle.x - box.x - box.width / 2);
        let dy = Math.round(middle.y - box.y - box.height / 2);

        const there = board.items.value.filter((item) => !isConnector(item));
        const taken = boundsOfAll(there);

        if (
            taken &&
            there.some((item) =>
                overlaps(boundsOf(item), {
                    ...box,
                    x: box.x + dx,
                    y: box.y + dy,
                }),
            )
        ) {
            dx = Math.round(taken.x - box.x);
            dy = Math.round(taken.y + taken.height + 120 - box.y);
        }
        // An end at a point of its own moves with the rest; one on a shape is
        // wherever the shape is
        const moved = <End extends { x: number; y: number } | null>(
            end: End,
        ) => (end ? { ...end, x: end.x + dx, y: end.y + dy } : end);

        board.place(
            items.map((item) =>
                isConnector(item)
                    ? { ...item, from: moved(item.from), to: moved(item.to) }
                    : { ...item, x: item.x + dx, y: item.y + dy },
            ),
        );
        tool.value = 'select';
        camera.reveal(
            { ...box, x: box.x + dx, y: box.y + dy },
            { inset: uncovered() },
        );
    };

    return { placeCircuit, placeTemplate };
}
