// Circuit text to a drawing, in one step: read, laid out, drawn.
import type { Item } from '@/features/boards/composables/items';
import { layOut } from './layout';
import { parseCircuit } from './parse';
import { drawCircuit } from './svg';
import type { DrawingOptions } from './svg';

export type Rendered =
    | { ok: true; svg: string; width: number; height: number; items: Item[] }
    | { ok: false; error: string };

/** Draws a circuit from its text, or says what is wrong with the text. */
export const renderCircuit = (
    source: string,
    options: DrawingOptions = {},
): Rendered => {
    const parsed = parseCircuit(source);

    if (!parsed.ok) {
        return parsed;
    }

    if (!parsed.circuit.parts.length) {
        return {
            ok: false,
            error: 'No parts yet. A part is written "R1: 10k", a wire "R1 -- C1".',
        };
    }

    const items = layOut(parsed.circuit);

    return {
        ok: true,
        items,
        ...drawCircuit(items, { title: parsed.circuit.title, ...options }),
    };
};
