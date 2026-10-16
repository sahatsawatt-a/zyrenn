import type { Item } from './items';

// Pencil ink pressed harder or lighter: a stylus says how hard at each point
// (0 to 1), and the stroke is drawn that heavy there. A mouse says nothing,
// and its ink is as heavy all along.

/** How hard a pointer presses: a pen's own reading, nothing for a mouse or a finger. */
export const pressureOf = (event: PointerEvent): number | null =>
    event.pointerType === 'pen'
        ? // A pen touching yet reading 0 (some do at first) is a light touch
          Math.min(1, Math.max(0.05, event.pressure || 0.05))
        : null;

/** How heavy ink is at a pressure: as its width at a usual press (about 0.5). */
const widthAt = (ink: number, pressure: number) =>
    ink * (0.25 + 1.5 * pressure);

/**
 * The width of each piece of a pressed stroke, from one point to the next:
 * by the pressure at each end, smoothed over its neighbours so a shaky
 * reading does not bead the line.
 */
export function pressedWidths(item: Item): number[] {
    const pressures = item.pressures ?? [];
    const ink = item.inkWidth ?? 3;
    const steady = pressures.map((_, at) => {
        const near = pressures.slice(Math.max(0, at - 1), at + 2);

        return near.reduce((sum, value) => sum + value, 0) / near.length;
    });

    return steady
        .slice(1)
        .map((pressure, at) => widthAt(ink, (pressure + steady[at]) / 2));
}

/** The heaviest a stroke is drawn anywhere: how far the eraser reaches it from. */
export const heaviest = (item: Item) =>
    item.pressures?.length
        ? widthAt(item.inkWidth ?? 3, Math.max(...item.pressures))
        : (item.inkWidth ?? 3);

/** Whether a stroke was pressed: one pressure for each of its points. */
export const pressed = (item: Item) =>
    !!item.pressures?.length &&
    item.pressures.length === Math.floor(item.points.length / 2);
