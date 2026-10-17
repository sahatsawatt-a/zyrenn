// What a prototype is made for, and how big its screens are -- shared by the
// prototype's editor bar, Play mode and making a new one. A screen is a frame
// on the prototype's board at its device's size.

export type Device = 'phone' | 'tablet' | 'desktop';

export const DEVICES: Record<
    Device,
    { label: string; width: number; height: number; radius: number }
> = {
    phone: { label: 'Phone', width: 390, height: 844, radius: 44 },
    tablet: { label: 'Tablet', width: 834, height: 1194, radius: 28 },
    desktop: { label: 'Desktop', width: 1440, height: 900, radius: 12 },
};

/** A prototype's own settings (boards.prototype), beside its board. */
export type PrototypeSettings = {
    device: Device;
    // The screen Play opens on: a frame's id; none, the first
    start: string | null;
    // What saving to a table in Play changes (phase 3): each play's own copy,
    // starting from the tables as they are ('play', the default -- a demo
    // never changes the real rows); or the tables themselves ('tables'), for
    // those who may change the project -- anyone else plays on a copy
    data?: 'play' | 'tables';
};

/**
 * Where tapping an item leads in Play: another screen (a frame's id), or
 * back to the screen before. Kept on the item as `link`.
 */
export type ItemLink = { to: string } | { back: true };
