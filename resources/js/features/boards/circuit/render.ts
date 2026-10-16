// Circuit text to a drawing, in one step: read, laid out, drawn -- and, when
// the text asks for its values, worked out, with the answers written on it.
import { isConnector } from '@/features/boards/model/items';
import type { Item } from '@/features/boards/model/items';
import { layOut } from './layout';
import { parseCircuit } from './parse';
import type { Circuit } from './parse';
import { solveCircuit } from './solve';
import type { Solved } from './solve';
import { drawCircuit } from './svg';
import type { DrawingOptions } from './svg';
import { WARNED, noteFor, voltagesOn } from './values';

export type Rendered =
    | {
          ok: true;
          svg: string;
          width: number;
          height: number;
          items: Item[];
          circuit: Circuit;
          // What the circuit was worked out to, when that was asked for
          solved: Solved | null;
      }
    | { ok: false; error: string };

export type RenderOptions = DrawingOptions & {
    // Work it out whether or not the text asks: true, or leave it to the text
    values?: boolean;
};

/** Draws a circuit from its text, or says what is wrong with the text. */
export const renderCircuit = (
    source: string,
    options: RenderOptions = {},
): Rendered => {
    const parsed = parseCircuit(source);

    if (!parsed.ok) {
        return parsed;
    }

    const { circuit } = parsed;

    if (!circuit.parts.length) {
        return {
            ok: false,
            error: 'No parts yet. A part is written "R1: 10k", a wire "R1 -- C1".',
        };
    }

    const solved =
        (options.values ?? circuit.values) ? solveCircuit(circuit) : null;

    // Under each part, by its name: laid out with room for it
    const byName = new Map<string, string>();

    if (solved?.ok) {
        for (const [name, answer] of solved.parts) {
            const note = noteFor(
                answer,
                circuit.parts.some(
                    (part) => part.name === name && !!part.symbol.source,
                ),
            );

            if (note) {
                byName.set(name, note);
            }
        }
    }

    const items = layOut(circuit, byName);
    const notes = new Map<string, string>();

    for (const item of items) {
        const note = byName.get(item.text.split('\n')[0]);

        if (note && !isConnector(item)) {
            notes.set(item.id, note);
        }
    }

    // A part of the circuit is the item whose first line is its name
    const named = new Map(
        items
            .filter((item) => !isConnector(item))
            .map((item) => [item.text.split('\n')[0], item]),
    );

    return {
        ok: true,
        items,
        circuit,
        solved,
        ...drawCircuit(items, {
            title: circuit.title,
            ...options,
            notes,
            wireNotes: solved?.ok
                ? voltagesOn(items, circuit.nets, solved, (part) =>
                      named.get(part),
                  )
                : undefined,
            caption: solved && !solved.ok ? solved.error : '',
            alarm: solved?.ok
                ? solved.warnings.map((each) => WARNED + each).join(' ')
                : '',
        }),
    };
};
