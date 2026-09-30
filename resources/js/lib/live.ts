// Seeing each other: who else has something open, and in which colour they
// are drawn -- in the avatars over a page, and on their cursors in a note or
// on a board.
import { echo, echoIsConfigured } from '@laravel/echo-vue';

// Someone on a presence channel (routes/channels.php)
export type Member = { id: number; name: string };

// Strong enough to read white initials on, and apart from one another
const COLOURS = [
    '#e11d48',
    '#2563eb',
    '#16a34a',
    '#9333ea',
    '#ea580c',
    '#0891b2',
    '#ca8a04',
    '#db2777',
];

/** The same colour for someone everywhere, and on every screen. */
export const colourFor = (id: number): string =>
    COLOURS[Math.abs(id) % COLOURS.length];

/**
 * Header for a save the others hear about, so the server leaves out the one
 * who made it: their screen shows the change already.
 */
export const socketHeaders = (): Record<string, string> => {
    const id = echoIsConfigured() ? echo().socketId() : undefined;

    return id ? { 'X-Socket-ID': id } : {};
};
