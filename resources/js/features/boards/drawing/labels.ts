import Konva from 'konva';
import type { Item } from '../model/items';
import { FONT_FAMILIES } from '../model/items';
import { hasHeader, headerDepth, headerSide } from '../model/rows';
import { lookOf } from '../model/shapes';

// Where a label is written on an item, and how much room its words take.
// The server works the same out in App\Support\Board\LabelFit, to warn an MCP
// client before a label runs out of its box; keep the two in step.

/** Lines are this far apart, in font sizes; the label editor's CSS agrees. */
export const LINE_HEIGHT = 1.3;

export const INK = '#0f172a';

/** The box a label is written in, relative to the item. */
export const labelBox = (item: Item) => {
    const padding = Math.max(0, item.padding ?? 12);

    // A table, a class or a lane is named in its band
    if (hasHeader(item)) {
        const depth = headerDepth(item);

        return headerSide(item) === 'left'
            ? { x: 0, y: 0, width: depth, height: item.height }
            : {
                  x: padding,
                  y: 0,
                  width: Math.max(1, item.width - padding * 2),
                  height: depth,
              };
    }

    // A shape from the library says where its words go, as shares of its
    // box: clear of a folder's tab, inside a screen, under a figure
    const room = lookOf(item).text;

    if (room) {
        const left = (room.left ?? 0) * item.width + padding;
        const top = (room.top ?? 0) * item.height + padding;

        return {
            x: left,
            y: top,
            width: Math.max(
                1,
                item.width * (1 - (room.left ?? 0) - (room.right ?? 0)) -
                    padding * 2,
            ),
            height: Math.max(
                1,
                item.height * (1 - (room.top ?? 0) - (room.bottom ?? 0)) -
                    padding * 2,
            ),
        };
    }

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
    (item.kind === 'text' && !item.rich) || hasHeader(item) ? '600' : 'normal';

export const fontOf = (item: Item) => FONT_FAMILIES[item.fontFamily] ?? 'Arial';

// --------------------------------------------------------------- Rich text

/** A stretch of words in one style; code is set in mono on a tint. */
type Run = { text: string; bold: boolean; italic: boolean; code?: boolean };

/** One line of the label as written: a heading, a bullet, or words. */
type Paragraph = {
    /** Its size, against the item's own. */
    scale: number;
    bold: boolean;
    /** What stands before a list item: "•" or "1." */
    marker: string | null;
    runs: Run[];
    /** A line of dashes: a rule right across the item, as a class box has. */
    rule?: boolean;
};

/** A piece of a laid-out line, ready to draw. */
export type Fragment = {
    text: string;
    x: number;
    font: string;
    /** Inline code: drawn on a tint, its run with room either side. */
    code?: boolean;
};

export type Line = {
    /** Its top, from the top of the label. */
    y: number;
    height: number;
    size: number;
    fragments: Fragment[];
    /** Not words but a rule across the item, drawn at the line's middle. */
    rule?: boolean;
};

/** How tall a rule's line is, in font sizes. */
const RULE_HEIGHT = 0.7;

const HEADINGS: Record<number, number> = { 1: 1.6, 2: 1.3, 3: 1.1 };

/**
 * `code`, a mark escaped with a backslash, **bold**, *italic* and _italic_.
 * An underscore inside a word is only an underscore, as in Markdown, so a
 * snake_case name stays as it is. The server reads the same (LabelFit::runs).
 */
const MARKS =
    /(`[^`]+`|\\[\\`*_#~[\]-]|\*\*[^*]+\*\*|\*[^*\s][^*]*\*|(?<![\p{L}\p{N}_])_[^_\s](?:[^_]*[^_\s])?_(?![\p{L}\p{N}_]))/gu;

/** **bold**, *italic* and _italic_ and `code`, inside one line. */
export const runsOf = (line: string, bold: boolean): Run[] => {
    const runs: Run[] = [];
    const plain = (text: string) => {
        const before = runs[runs.length - 1];

        // Text either side of an escaped mark is one word, not three
        if (before && !before.code && !before.italic && before.bold === bold) {
            before.text += text;
        } else {
            runs.push({ text, bold, italic: false });
        }
    };
    let last = 0;

    for (const match of line.matchAll(MARKS)) {
        if (match.index > last) {
            plain(line.slice(last, match.index));
        }

        const token = match[0];
        const strong = token.startsWith('**');

        if (token.startsWith('\\')) {
            plain(token.slice(1));
        } else if (token.startsWith('`')) {
            runs.push({
                text: token.slice(1, -1),
                bold,
                italic: false,
                code: true,
            });
        } else {
            // Read inside too: `code` and escapes in bold or italic words
            for (const run of runsOf(
                token.slice(strong ? 2 : 1, strong ? -2 : -1),
                bold || strong,
            )) {
                runs.push({ ...run, italic: run.italic || !strong });
            }
        }

        last = match.index + token.length;
    }

    if (last < line.length) {
        plain(line.slice(last));
    }

    return runs;
};

/** The room either side of inline code, for its tint, in font sizes. */
const CODE_PAD = 0.25;

/**
 * The label as light Markdown, a line at a time: "# ", "## " and "### " make
 * headings, "- ", "* " or "• " a bullet, "1. " a numbered item, and
 * **bold** or *italic* words inside any of them. A line of nothing but
 * dashes ("---") is a rule across the whole item.
 */
export const paragraphsOf = (text: string): Paragraph[] =>
    text.split('\n').map((line) => {
        if (/^\s*-{3,}\s*$/.test(line)) {
            return {
                scale: 1,
                bold: false,
                marker: null,
                runs: [],
                rule: true,
            };
        }

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
// The room added after each letter of the label being laid out
let spacing = 0;

const widthOf = (text: string, font: string) => {
    measurer ??= document.createElement('canvas').getContext('2d');

    if (!measurer) {
        return text.length * (8 + spacing);
    }

    measurer.font = font;

    return measurer.measureText(text).width + spacing * text.length;
};

/** The words as shown: in capitals, when the label is set that way. */
export const shownText = (item: Item): string =>
    item.caps ? item.text.toUpperCase() : item.text;

const fontFor = (run: Run, size: number, family: string) =>
    `${run.italic ? 'italic ' : ''}${run.bold ? 'bold ' : ''}${size}px ${run.code ? FONT_FAMILIES.mono : family}`;

type Laid = { lines: Line[]; height: number };

/**
 * What a label's look depends on, as one string: the same words in the same
 * box and type are laid out and measured once, not on every redraw.
 */
const labelKey = (item: Item, width: number) =>
    `${width}|${item.fontSize}|${item.fontFamily}|${item.rich}|${item.align}|${labelStyle(item)}|${item.letterSpacing ?? 0}|${item.caps ? 1 : 0}|${item.text}`;

/** Labels are typed a letter at a time; don't keep every step of that. */
const MAX_KEPT = 2000;

const keep = <Value>(kept: Map<string, Value>, key: string, value: Value) => {
    if (kept.size > MAX_KEPT) {
        kept.clear();
    }

    kept.set(key, value);

    return value;
};

const layouts = new Map<string, Laid>();

/**
 * A rich label laid out in its box: each paragraph wrapped at word breaks to
 * the box's width -- a list item hanging off its marker -- and each line
 * placed by the label's alignment. Kept by what it depends on, since the
 * canvas draws it again on every drag, pan and zoom: what comes back is
 * shared, and read only.
 */
export const layoutRich = (item: Item): Laid => {
    const width = labelBox(item).width;
    const key = labelKey(item, width);

    return layouts.get(key) ?? keep(layouts, key, layOut(item, width));
};

const layOut = (item: Item, width: number): Laid => {
    const family = fontOf(item);
    const lines: Line[] = [];
    let y = 0;

    spacing = item.letterSpacing ?? 0;

    for (const paragraph of paragraphsOf(shownText(item))) {
        if (paragraph.rule) {
            const height = item.fontSize * RULE_HEIGHT;

            lines.push({
                y,
                height,
                size: item.fontSize,
                fragments: [],
                rule: true,
            });
            y += height;

            continue;
        }

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
            const code = run.code ? { code: true } : {};

            if (run.code) {
                x += size * CODE_PAD;
            }

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

                    line.push({ text: rest.slice(0, cut), x, font, ...code });
                    finish();
                    rest = rest.slice(cut);
                    wide = widthOf(rest, font);
                }

                line.push({ text: rest, x, font, ...code });
                x += wide;
            }

            if (run.code) {
                x += size * CODE_PAD;
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

/**
 * Forget every label laid out and measured so far: a font they were measured
 * in has only now come (drawing/fonts.ts).
 */
export const forgetLayouts = () => {
    layouts.clear();
    heights.clear();
};

/** How tall the label's words are once wrapped to the width of its box. */
export const labelHeight = (item: Item): number => {
    if (!item.text) {
        return 0;
    }

    // A rich label's height comes with its layout, kept there
    if (item.rich) {
        return layoutRich(item).height;
    }

    const width = labelBox(item).width;
    const key = labelKey(item, width);
    const height = heights.get(key);

    if (height !== undefined) {
        return height;
    }

    probe ??= new Konva.Text({ lineHeight: LINE_HEIGHT, wrap: 'word' });
    probe.setAttrs({
        text: shownText(item),
        letterSpacing: item.letterSpacing ?? 0,
        width,
        fontSize: item.fontSize,
        fontFamily: fontOf(item),
        fontStyle: labelStyle(item),
    });

    return keep(heights, key, probe.height());
};

/**
 * Draws a rich label, for a Konva Shape: each line's pieces in their own
 * fonts, the block placed down the box by the label's vertical alignment.
 */
export const drawRich =
    (item: Item, ink: string = INK, ruled: string = ink) =>
    (context: Konva.Context) => {
        const { lines, height } = layoutRich(item);
        const box = labelBox(item);
        const top =
            item.verticalAlign === 'bottom'
                ? box.height - height
                : item.verticalAlign === 'middle'
                  ? (box.height - height) / 2
                  : 0;

        // Words that fade across the label, from their colour to another
        if (item.colorTo) {
            const fade = context.createLinearGradient(0, 0, box.width, 0);

            fade.addColorStop(0, ink);
            fade.addColorStop(1, item.colorTo);
            context.setAttr('fillStyle', fade);
        } else {
            context.setAttr('fillStyle', ink);
        }

        context.setAttr('textBaseline', 'middle');
        context.setAttr('letterSpacing', `${item.letterSpacing ?? 0}px`);

        for (const line of lines) {
            if (line.rule) {
                // From one edge of the item to the other, whatever room the
                // words are given inside it
                const at = Math.max(0, top) + line.y + line.height / 2;

                context.beginPath();
                context.moveTo(-box.x, at);
                context.lineTo(item.width - box.x, at);
                context.setAttr('strokeStyle', ruled);
                context.setAttr('lineWidth', Math.max(1, item.lineWidth));
                context.stroke();

                continue;
            }

            const middle = Math.max(0, top) + line.y + line.height / 2;
            const pad = line.size * CODE_PAD;

            // Inline code on a light tint of the words' own colour: one for
            // each stretch of it on the line
            for (let at = 0; at < line.fragments.length; at++) {
                const first = line.fragments[at];

                if (!first.code) continue;

                let end = at;

                while (line.fragments[end + 1]?.code) end++;

                const last = line.fragments[end];
                const right = last.x + widthOf(last.text, last.font) + pad;

                context.save();
                context.setAttr(
                    'globalAlpha',
                    context._context.globalAlpha * 0.12,
                );
                context.beginPath();
                context.roundRect(
                    first.x - pad,
                    middle - line.size * 0.65,
                    right - first.x + pad,
                    line.size * 1.3,
                    line.size * 0.2,
                );
                context.fill();
                context.restore();
                at = end;
            }

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

/** Kinds that can be as tall as their words: their label sits a padding in. */
export const HUGGING: Item['kind'][] = ['rect', 'sticky', 'pill', 'process'];

/**
 * The height an item that hugs its words should have -- the words and the
 * padding above and below them -- or null for one that doesn't hug.
 */
export const huggedHeight = (item: Item): number | null =>
    item.hug && HUGGING.includes(item.kind) && item.text
        ? Math.ceil(labelHeight(item) + item.padding * 2)
        : null;

/**
 * The height an item should have for its words: as tall as they are for one
 * that hugs them; a sticky at least as tall -- its paper grows to hold what
 * is written on it, never leaving words out on the board, and keeps any room
 * below them it was given; anything else, the height it has.
 */
export const fittedHeight = (item: Item): number => {
    const hugged = huggedHeight(item);

    if (hugged !== null) {
        return hugged;
    }

    return item.kind === 'sticky' && item.text
        ? Math.max(
              item.height,
              Math.ceil(labelHeight(item) + labelBox(item).y * 2),
          )
        : item.height;
};
