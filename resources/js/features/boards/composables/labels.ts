import Konva from 'konva';
import type { Item } from './items';
import { FONT_FAMILIES } from './items';

// Where a label is written on an item, and how much room its words take.
// The server works the same out in App\Support\Board\LabelFit, to warn an MCP
// client before a label runs out of its box; keep the two in step.

/** Lines are this far apart, in font sizes; the label editor's CSS agrees. */
export const LINE_HEIGHT = 1.3;

export const INK = '#0f172a';

/** The box a label is written in, relative to the item. */
export const labelBox = (item: Item) => {
    const padding = Math.max(0, item.padding ?? 12);

    // A cylinder's lid and a triangle's point leave no room at the edges, so
    // their text starts lower; a predefined process keeps clear of its rails
    const top =
        item.kind === 'cylinder'
            ? Math.max(padding, item.height * 0.2)
            : item.kind === 'triangle'
              ? Math.max(padding, item.height * 0.35)
              : padding;
    const side =
        item.kind === 'process' ? item.width * 0.14 + padding : padding;

    return {
        x: side,
        y: top,
        width: Math.max(1, item.width - side * 2),
        height: Math.max(1, item.height - top * 2),
    };
};

/** Bold for a plain text item, which is a heading more often than not. */
export const labelStyle = (item: Item) =>
    item.kind === 'text' && !item.rich ? '600' : 'normal';

export const fontOf = (item: Item) => FONT_FAMILIES[item.fontFamily] ?? 'Arial';

// --------------------------------------------------------------- Rich text

/** A stretch of words in one style. */
type Run = { text: string; bold: boolean; italic: boolean };

/** One line of the label as written: a heading, a bullet, or words. */
type Paragraph = {
    /** Its size, against the item's own. */
    scale: number;
    bold: boolean;
    /** What stands before a list item: "•" or "1." */
    marker: string | null;
    runs: Run[];
};

/** A piece of a laid-out line, ready to draw. */
export type Fragment = {
    text: string;
    x: number;
    font: string;
};

export type Line = {
    /** Its top, from the top of the label. */
    y: number;
    height: number;
    size: number;
    fragments: Fragment[];
};

const HEADINGS: Record<number, number> = { 1: 1.6, 2: 1.3, 3: 1.1 };

/** **bold**, *italic* and _italic_, inside one line. */
const runsOf = (line: string, bold: boolean): Run[] => {
    const runs: Run[] = [];
    const pattern = /(\*\*[^*]+\*\*|\*[^*\s][^*]*\*|_[^_\s][^_]*_)/g;
    let last = 0;

    for (const match of line.matchAll(pattern)) {
        if (match.index > last) {
            runs.push({
                text: line.slice(last, match.index),
                bold,
                italic: false,
            });
        }

        const token = match[0];
        const strong = token.startsWith('**');

        runs.push({
            text: token.slice(strong ? 2 : 1, strong ? -2 : -1),
            bold: bold || strong,
            italic: !strong,
        });
        last = match.index + token.length;
    }

    if (last < line.length) {
        runs.push({ text: line.slice(last), bold, italic: false });
    }

    return runs;
};

/**
 * The label as light Markdown, a line at a time: "# ", "## " and "### " make
 * headings, "- ", "* " or "• " a bullet, "1. " a numbered item, and
 * **bold** or *italic* words inside any of them.
 */
export const paragraphsOf = (text: string): Paragraph[] =>
    text.split('\n').map((line) => {
        const heading = line.match(/^(#{1,3})\s+(.*)$/);

        if (heading) {
            return {
                scale: HEADINGS[heading[1].length],
                bold: true,
                marker: null,
                runs: runsOf(heading[2], true),
            };
        }

        const bullet = line.match(/^\s*[-*•]\s+(.*)$/);
        const numbered = line.match(/^\s*(\d+[.)])\s+(.*)$/);

        return {
            scale: 1,
            bold: false,
            marker: bullet ? '•' : numbered ? numbered[1] : null,
            runs: runsOf(
                bullet ? bullet[1] : numbered ? numbered[2] : line,
                false,
            ),
        };
    });

let measurer: CanvasRenderingContext2D | null = null;

const widthOf = (text: string, font: string) => {
    measurer ??= document.createElement('canvas').getContext('2d');

    if (!measurer) {
        return text.length * 8;
    }

    measurer.font = font;

    return measurer.measureText(text).width;
};

const fontFor = (run: Run, size: number, family: string) =>
    `${run.italic ? 'italic ' : ''}${run.bold ? 'bold ' : ''}${size}px ${family}`;

/**
 * A rich label laid out in its box: each paragraph wrapped at word breaks to
 * the box's width -- a list item hanging off its marker -- and each line
 * placed by the label's alignment.
 */
export const layoutRich = (item: Item): { lines: Line[]; height: number } => {
    const width = labelBox(item).width;
    const family = fontOf(item);
    const lines: Line[] = [];
    let y = 0;

    for (const paragraph of paragraphsOf(item.text)) {
        const size = item.fontSize * paragraph.scale;
        const plain = { text: '', bold: paragraph.bold, italic: false };
        const markerFont = fontFor(plain, size, family);
        const indent = paragraph.marker
            ? widthOf(`${paragraph.marker} `, markerFont)
            : 0;

        let line: Fragment[] = paragraph.marker
            ? [{ text: paragraph.marker, x: 0, font: markerFont }]
            : [];
        let x = indent;

        const finish = () => {
            lines.push({
                y,
                height: size * LINE_HEIGHT,
                size,
                fragments: line,
            });
            y += size * LINE_HEIGHT;
            line = [];
            x = indent;
        };

        for (const run of paragraph.runs) {
            const font = fontFor(run, size, family);

            // Words and the spaces between them, so a line breaks at a space
            for (const piece of run.text.split(/(\s+)/)) {
                if (!piece) {
                    continue;
                }

                const space = /^\s+$/.test(piece);
                let wide = widthOf(piece, font);

                if (space && x === indent) {
                    continue;
                }

                if (!space && x + wide > width && x > indent) {
                    finish();
                }

                // A word longer than a whole line is broken where it runs out
                let rest = piece;

                while (!space && wide > width - indent && rest.length > 1) {
                    let cut = rest.length - 1;

                    while (
                        cut > 1 &&
                        widthOf(rest.slice(0, cut), font) > width - x
                    ) {
                        cut--;
                    }

                    line.push({ text: rest.slice(0, cut), x, font });
                    finish();
                    rest = rest.slice(cut);
                    wide = widthOf(rest, font);
                }

                line.push({ text: rest, x, font });
                x += wide;
            }
        }

        finish();
    }

    // Placed across the box by the label's alignment
    for (const placed of lines) {
        const last = placed.fragments[placed.fragments.length - 1];
        const used = last ? last.x + widthOf(last.text, last.font) : 0;
        const shift =
            item.align === 'right'
                ? width - used
                : item.align === 'center'
                  ? (width - used) / 2
                  : 0;

        placed.fragments.forEach((fragment) => (fragment.x += shift));
    }

    return { lines, height: y };
};

// --------------------------------------------------------------- Measuring

// One unseen Konva text measures every plain label, the way the canvas wraps it
let probe: Konva.Text | null = null;
const heights = new Map<string, number>();

/** How tall the label's words are once wrapped to the width of its box. */
export const labelHeight = (item: Item): number => {
    if (!item.text) {
        return 0;
    }

    const width = labelBox(item).width;
    const key = `${width}|${item.fontSize}|${item.fontFamily}|${item.rich}|${item.align}|${labelStyle(item)}|${item.text}`;
    let height = heights.get(key);

    if (height === undefined) {
        if (item.rich) {
            height = layoutRich(item).height;
        } else {
            probe ??= new Konva.Text({ lineHeight: LINE_HEIGHT, wrap: 'word' });
            probe.setAttrs({
                text: item.text,
                width,
                fontSize: item.fontSize,
                fontFamily: fontOf(item),
                fontStyle: labelStyle(item),
            });
            height = probe.height();
        }

        // Labels are typed a letter at a time; don't keep every step of that
        if (heights.size > 2000) {
            heights.clear();
        }

        heights.set(key, height);
    }

    return height;
};

/**
 * Draws a rich label, for a Konva Shape: each line's pieces in their own
 * fonts, the block placed down the box by the label's vertical alignment.
 */
export const drawRich = (item: Item) => (context: Konva.Context) => {
    const { lines, height } = layoutRich(item);
    const box = labelBox(item);
    const top =
        item.verticalAlign === 'bottom'
            ? box.height - height
            : item.verticalAlign === 'middle'
              ? (box.height - height) / 2
              : 0;

    context.setAttr('fillStyle', INK);
    context.setAttr('textBaseline', 'middle');

    for (const line of lines) {
        for (const fragment of line.fragments) {
            context.setAttr('font', fragment.font);
            context.fillText(
                fragment.text,
                fragment.x,
                Math.max(0, top) + line.y + line.height / 2,
            );
        }
    }
};
