// How a shape with a structure inside it is drawn: the titled band of a
// table, a class or a lane, and under it a row for each column or member --
// its key or its sign, its name, and what it holds set against the far edge.
// Where each row lies is worked out in rows.ts, which a connector reads too.
import type Konva from 'konva';
import type { Item } from './items';
import { FONT_FAMILIES } from './items';
import {
    headerDepth,
    headerSide,
    laidRows,
    markShown,
    rowsKind,
    sectionRule,
} from './rows';

/** How round the corners of a table, a class or a lane are. */
export const CARD_RADIUS = 6;

/** What a key is written in: a primary key stands out, the others less. */
const KEY_COLOURS: Record<string, string> = {
    pk: '#b45309',
    fk: '#4f46e5',
    uk: '#0f766e',
};

/** As much of the words as fits, with a mark where they were cut. */
const fitted = (context: Konva.Context, words: string, room: number) => {
    if (room <= 0) {
        return '';
    }

    if (context.measureText(words).width <= room) {
        return words;
    }

    let cut = words.length - 1;

    while (
        cut > 1 &&
        context.measureText(`${words.slice(0, cut)}…`).width > room
    ) {
        cut--;
    }

    return `${words.slice(0, cut)}…`;
};

/**
 * Draws the structure, for a Konva Shape. `ink` is what the words are
 * written in and `line` what the band and its rules are drawn in; a title
 * down the side of a lane is drawn here too, unless it is being typed.
 */
export const drawStructure =
    (
        item: Item,
        { ink, line, editing }: { ink: string; line: string; editing: boolean },
    ) =>
    (context: Konva.Context) => {
        const { width, height } = item;
        const family = FONT_FAMILIES[item.fontFamily] ?? 'Arial';
        const depth = headerDepth(item);
        const side = headerSide(item);
        const ruled = line && line !== 'transparent';
        const rule = (x1: number, y1: number, x2: number, y2: number) => {
            if (!ruled) {
                return;
            }

            context.beginPath();
            context.moveTo(x1, y1);
            context.lineTo(x2, y2);
            context.setAttr('strokeStyle', line);
            context.setAttr('lineWidth', Math.max(1, item.lineWidth * 0.75));
            context.stroke();
        };

        context.save();
        // Nothing is drawn past the shape's own edge, however short it is made
        const round = Math.min(CARD_RADIUS, width / 2, height / 2);

        context.beginPath();
        context.moveTo(round, 0);
        context.arcTo(width, 0, width, height, round);
        context.arcTo(width, height, 0, height, round);
        context.arcTo(0, height, 0, 0, round);
        context.arcTo(0, 0, width, 0, round);
        context.closePath();
        context.clip();

        // The band: a wash of the line's colour, ruled off from the rest
        if (ruled) {
            context.setAttr('globalAlpha', 0.1);
            context.setAttr('fillStyle', line);
            context.fillRect(
                0,
                0,
                side === 'left' ? depth : width,
                side === 'left' ? height : depth,
            );
            context.setAttr('globalAlpha', 1);
        }

        if (side === 'left') {
            rule(depth, 0, depth, height);

            // A lane's name reads up its side
            if (!editing && item.text) {
                context.save();
                context.translate(depth / 2, height / 2);
                context.rotate(-Math.PI / 2);
                context.setAttr('font', `600 ${item.fontSize + 1}px ${family}`);
                context.setAttr('fillStyle', ink);
                context.setAttr('textAlign', 'center');
                context.setAttr('textBaseline', 'middle');
                context.fillText(
                    fitted(context, item.text.split('\n')[0], height - 12),
                    0,
                    0,
                );
                context.restore();
            }

            context.restore();

            return;
        }

        rule(0, depth, width, depth);

        const kind = rowsKind(item);

        if (kind) {
            const rows = laidRows(item);
            const edge = Math.max(6, item.padding);
            const small = Math.max(8, item.fontSize - 3);
            // Room for the mark only where some row has one
            const marked = rows.some(({ row }) => markShown(kind, row.mark));
            const gutter = !marked
                ? 0
                : kind === 'table'
                  ? small * 2.1
                  : item.fontSize * 0.95;

            context.setAttr('textBaseline', 'middle');

            for (const { row, y, height: tall } of rows) {
                const middle = y + tall / 2;
                const mark = markShown(kind, row.mark);

                if (mark) {
                    context.setAttr(
                        'font',
                        kind === 'table'
                            ? `700 ${small}px ${family}`
                            : `${item.fontSize}px ${family}`,
                    );
                    context.setAttr(
                        'fillStyle',
                        kind === 'table' ? (KEY_COLOURS[row.mark] ?? ink) : ink,
                    );
                    context.setAttr('textAlign', 'left');
                    context.fillText(mark, edge, middle);
                }

                // What it holds, against the far edge and quieter than its name
                const type =
                    kind === 'table'
                        ? `${row.type}${row.nullable && row.type ? '?' : ''}`
                        : '';
                let typeWide = 0;

                context.setAttr('font', `${item.fontSize}px ${family}`);

                if (type) {
                    const shown = fitted(context, type, width * 0.45);

                    typeWide = context.measureText(shown).width + 10;
                    context.setAttr('globalAlpha', 0.6);
                    context.setAttr('fillStyle', ink);
                    context.setAttr('textAlign', 'right');
                    context.fillText(shown, width - edge, middle);
                    context.setAttr('globalAlpha', 1);
                }

                // A member is written as it is read: "area(): number"
                const name =
                    kind === 'class' && row.type
                        ? `${row.name}: ${row.type}`
                        : row.name;

                context.setAttr(
                    'font',
                    `${row.mark === 'pk' ? '600 ' : ''}${item.fontSize}px ${family}`,
                );
                context.setAttr('fillStyle', ink);
                context.setAttr('textAlign', 'left');
                context.fillText(
                    fitted(context, name, width - edge * 2 - gutter - typeWide),
                    edge + gutter,
                    middle,
                );
            }

            const under = sectionRule(item);

            if (under !== null) {
                rule(0, under, width, under);
            }
        }

        context.restore();
    };
