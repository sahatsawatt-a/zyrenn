// A circuit as a picture: the symbols and wires a board would draw, written
// out as SVG. Wires take the path the canvas gives them (connectorPoints), so
// what a note shows is what a board shows.
import {
    attachmentsOf,
    connectorPoints,
    midpointOf,
} from '@/features/boards/model/connectors';
import { INK, isConnector } from '@/features/boards/model/items';
import type { Item } from '@/features/boards/model/items';
import { WARNED, besideRun } from './values';
import {
    LABEL_SIZE,
    isSymbol,
    labelBounds,
    pinTexts,
    symbolBox,
    symbolLabels,
    symbolOf,
} from './symbols';

export type Drawing = { svg: string; width: number; height: number };

export type DrawingOptions = {
    // What it is drawn in. The page's own text colour by default, so a
    // drawing in a note follows light and dark; a file needs a colour said
    ink?: string;
    // Painted behind it, for a file that has to stand on its own
    paper?: string;
    padding?: number;
    title?: string;
    // What was worked out, written on the drawing in its own colour: a line
    // under a part's words, by the part's item id, and a net's voltage on
    // one of its wires, by the wire's id
    notes?: Map<string, string>;
    wireNotes?: Map<string, string>;
    accent?: string;
    // A line under the whole drawing: why nothing could be worked out
    caption?: string;
    // Another, in the colour of a warning: what is asked for more than it
    // is good for. A part's own line is in that colour when it starts "⚠"
    alarm?: string;
    danger?: string;
};

const escaped = (text: string) =>
    text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const number = (value: number) => String(Math.round(value * 100) / 100);

/** How far under a label's own "y" its letters stand, for each way it hangs. */
const BASELINE = {
    top: LABEL_SIZE - 2,
    middle: LABEL_SIZE * 0.36,
    bottom: -3,
};

const ANCHOR = { left: 'start', center: 'middle', right: 'end' };

/** Draws the symbols and wires among these items; anything else is left out. */
export const drawCircuit = (
    items: Item[],
    options: DrawingOptions = {},
): Drawing => {
    const {
        ink = 'currentColor',
        paper,
        padding = 16,
        title = '',
        notes,
        wireNotes,
        // The app's own accent where there is one; a file gets the fallback
        accent = 'var(--primary, #4f46e5)',
        caption = '',
        alarm = '',
        danger = 'var(--destructive, #dc2626)',
    } = options;
    // Said of a line in the colour of a warning
    const alarmed = ` fill="${escaped(danger)}"`;
    const worked: string[] = [];
    const shown = items.filter((item) => !item.hidden);
    const byId = new Map(shown.map((item) => [item.id, item]));
    const attached = attachmentsOf(shown);

    // Ink is whatever the drawing is in; a part given a colour keeps it
    const stroke = (item: Item) =>
        item.stroke === INK ? '' : ` stroke="${escaped(item.stroke)}"`;
    const filled = (item: Item) =>
        item.stroke === INK ? '' : ` fill="${escaped(item.stroke)}"`;

    const lines: string[] = [];
    const shapes: string[] = [];
    const solids: string[] = [];
    const words: string[] = [];
    const boxes: { x: number; y: number; width: number; height: number }[] = [];

    for (const item of shown) {
        if (isConnector(item)) {
            const path = connectorPoints(item, byId, attached);
            const points: string[] = [];

            for (let index = 0; index + 1 < path.length; index += 2) {
                const point = `${number(path[index])},${number(path[index + 1])}`;

                if (points[points.length - 1] !== point) {
                    points.push(point);
                }

                boxes.push({
                    x: path[index],
                    y: path[index + 1],
                    width: 0,
                    height: 0,
                });
            }

            if (points.length > 1) {
                lines.push(
                    `<polyline points="${points.join(' ')}"${stroke(item)}/>`,
                );
            }

            // What its net was worked out to be at: beside the longest
            // straight run of it, clear of its corners
            const volts = wireNotes?.get(item.id);
            const at = volts ? besideRun(path) : null;

            if (at && volts) {
                const { level } = at;

                worked.push(
                    `<text x="${number(at.x)}" y="${number(at.y)}" text-anchor="${level ? 'middle' : 'start'}" font-size="11">${escaped(volts)}</text>`,
                );
                boxes.push({
                    x: at.x - (level ? 24 : 0),
                    y: at.y - 12,
                    width: 48,
                    height: 16,
                });
            }

            // A wire's words are its net's name, written just over it
            if (item.text.trim() && path.length >= 4) {
                const middle = midpointOf(path);

                words.push(
                    `<text x="${number(middle.x)}" y="${number(middle.y - 5)}" text-anchor="middle" font-size="11">${escaped(item.text.trim())}</text>`,
                );
            }

            continue;
        }

        const symbol = isSymbol(item) ? symbolOf(item.symbol) : undefined;

        if (!symbol) {
            continue;
        }

        const place = `translate(${number(item.x)} ${number(item.y)}) rotate(${number(item.rotation)}) scale(${number(item.width / symbol.width)} ${number(item.height / symbol.height)})`;

        // As heavy as a wire however the drawing is scaled: a part drawn
        // larger than the library has it has its line made up for that
        const scale =
            (item.width / symbol.width + item.height / symbol.height) / 2;
        const weight =
            Math.abs(scale - 1) < 0.001
                ? ''
                : ` stroke-width="${number(2 / scale)}"`;

        if (symbol.path) {
            shapes.push(
                `<path d="${symbol.path}" transform="${place}"${weight}${stroke(item)}/>`,
            );
        }

        if (symbol.states) {
            shapes.push(
                `<path d="${symbol.states[item.on ? 'on' : 'off']}" transform="${place}"${weight}${stroke(item)}/>`,
            );
        }

        if (symbol.fill) {
            solids.push(
                `<path d="${symbol.fill}" transform="${place}"${filled(item)}/>`,
            );
        }

        boxes.push(symbolBox(item));

        const note = notes?.get(item.id);

        for (const label of symbolLabels(item, note ? [note] : [])) {
            boxes.push(labelBounds(label));
            (label.note ? worked : words).push(
                `<text x="${number(label.x)}" y="${number(label.y + BASELINE[label.baseline])}" text-anchor="${ANCHOR[label.align]}"${label.name ? ' font-weight="600"' : ''}${label.note && label.text.startsWith(WARNED) ? alarmed : ''}>${escaped(label.text)}</text>`,
            );
        }

        // A module's pin names, inside its box
        for (const label of pinTexts(item)) {
            const size = label.size ?? LABEL_SIZE;

            words.push(
                `<text x="${number(label.x)}" y="${number(label.y + size * 0.36)}" text-anchor="${ANCHOR[label.align]}" font-size="${size}">${escaped(label.text)}</text>`,
            );
        }
    }

    const left = Math.min(0, ...boxes.map((box) => box.x));
    const top = Math.min(0, ...boxes.map((box) => box.y));
    const right = Math.max(0, ...boxes.map((box) => box.x + box.width));
    const bottom = Math.max(0, ...boxes.map((box) => box.y + box.height));

    // Its own corner, not the board's: boxes that never reach 0 start at theirs
    const x =
        (boxes.length ? Math.min(...boxes.map((box) => box.x)) : left) -
        padding;
    const heading = title ? 28 : 0;
    const y =
        (boxes.length ? Math.min(...boxes.map((box) => box.y)) : top) -
        padding -
        heading;
    const width = Math.max(1, right - x + padding);
    const height = Math.max(1, bottom - y + padding);

    if (title) {
        words.push(
            `<text x="${number(x + width / 2)}" y="${number(y + padding + 6)}" text-anchor="middle" font-size="15" font-weight="600">${escaped(title)}</text>`,
        );
    }

    // Why nothing was worked out, and what is over its rating, under the
    // drawing: broken into lines no wider than it is
    const perLine = Math.max(24, Math.floor(width / 5.6));
    const captionLines: { text: string; alarm: boolean }[] = [];

    for (const [said, isAlarm] of [
        [caption, false],
        [alarm, true],
    ] as const) {
        let line = '';

        for (const word of said.split(/\s+/).filter(Boolean)) {
            if (line !== '' && `${line} ${word}`.length > perLine) {
                captionLines.push({ text: line, alarm: isAlarm });
                line = word;
            } else {
                line = line === '' ? word : `${line} ${word}`;
            }
        }

        if (line !== '') {
            captionLines.push({ text: line, alarm: isAlarm });
        }
    }

    const footer = captionLines.length ? captionLines.length * 14 + 10 : 0;

    captionLines.forEach((line, index) =>
        worked.push(
            `<text x="${number(x + width / 2)}" y="${number(y + height + 12 + index * 14)}" text-anchor="middle" font-size="11"${line.alarm ? alarmed : ''}>${escaped(line.text)}</text>`,
        ),
    );

    const svg = [
        `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${number(x)} ${number(y)} ${number(width)} ${number(height + footer)}" width="${number(width)}" height="${number(height + footer)}" font-family="Arial, Helvetica, sans-serif" font-size="${LABEL_SIZE}">`,
        paper
            ? `<rect x="${number(x)}" y="${number(y)}" width="${number(width)}" height="${number(height + footer)}" fill="${escaped(paper)}"/>`
            : '',
        `<g fill="none" stroke="${escaped(ink)}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${lines.join('')}${shapes.join('')}</g>`,
        `<g fill="${escaped(ink)}" stroke="none">${solids.join('')}${words.join('')}</g>`,
        worked.length
            ? `<g fill="${escaped(accent)}" stroke="none">${worked.join('')}</g>`
            : '',
        '</svg>',
    ].join('');

    return { svg, width, height: height + footer };
};
