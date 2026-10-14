import type { JSONContent } from '@tiptap/core';
import { plotExamples } from '@/features/notes/plot/examples';

// The note the labs page opens: the blocks being tried out, each filled in
// with something to click. It is saved nowhere.

const text = (said: string): JSONContent => ({ type: 'text', text: said });
const p = (said: string): JSONContent => ({
    type: 'paragraph',
    content: said ? [text(said)] : [],
});
const h = (level: number, said: string): JSONContent => ({
    type: 'heading',
    attrs: { level },
    content: [text(said)],
});
const block = (language: string, source: string): JSONContent => ({
    type: 'codeBlock',
    attrs: { language },
    content: [text(source)],
});
const reveal = (title: string, ...content: JSONContent[]): JSONContent => ({
    type: 'reveal',
    attrs: { title },
    content,
});

const graph = (id: string) =>
    plotExamples.find((each) => each.id === id)?.source ?? '';

export const DIVIDER = [
    'title: A voltage divider',
    'values hidden',
    'V1: battery 5V',
    'R1: 10k',
    'R2: 10k',
    'V1.+ -- R1 -- out',
    'out -- R2 -- V1.-',
].join('\n');

export const noteDemo: JSONContent = {
    type: 'doc',
    content: [
        h(2, 'An answer kept out of sight'),
        p(
            'A 9 V battery drives a 1 kΩ and a 2 kΩ resistor in series. What current flows?',
        ),
        reveal(
            'Answer',
            p('3 mA: the two resistors add up to 3 kΩ, and 9 V ÷ 3 kΩ = 3 mA.'),
            p('So 3 V is across the 1 kΩ and 6 V across the 2 kΩ.'),
        ),
        p(
            'Click the line to show and hide it. To make one, type /reveal; in Markdown it is ":::reveal Answer", the text, then ":::".',
        ),

        h(2, 'A circuit that works itself out'),
        p(
            'What is the voltage at the point between the two resistors? Make a guess, then click Values on the drawing: it writes the voltage on each wire, and the current through and voltage across each part. Click it again to hide them.',
        ),
        block('circuit', DIVIDER),
        p(
            'In the text, a line reading "values" shows them from the start, and "values hidden" keeps them back until they are asked for, as here. Click Edit and change a resistor.',
        ),

        h(2, 'A graph with values to slide'),
        p(
            'Slide R and C and the curves follow. Point at the graph to read it. To make one, type /graph.',
        ),
        block('plot', graph('rc')),
        block('plot', graph('sine')),
        p(''),
    ],
};
