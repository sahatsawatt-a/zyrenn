// What a recent card shows of a note, a board or a table, as the server sends
// it (App\Support\Dashboard\Previews), and the board's boxes placed on the
// card: the drawing fitted into it whole, in the card's own pixels.

export type NotePreview = {
    kind: 'note';
    heading: string | null;
    text: string;
};

export type BoardBox = {
    // 0 to 1 across and down the drawing
    x: number;
    y: number;
    w: number;
    h: number;
    fill: string;
    stroke: string;
    kind: string;
};

export type BoardPreview = {
    kind: 'board';
    // The drawing's width over its height
    aspect: number;
    boxes: BoardBox[];
    // From one box's middle to another's: x1, y1, x2, y2, 0 to 1
    lines: [number, number, number, number][];
};

export type TablePreview = { kind: 'table'; columns: string[]; rows: number };

export type Preview = NotePreview | BoardPreview | TablePreview;

/** A recent thing, as the dashboard lists it. */
export type RecentItem = {
    kind: 'note' | 'board' | 'table';
    ref_id: string;
    title: string;
    updated_at: string;
    edited_by?: string | null;
    project?: { ref_id: string; name: string } | null;
};

/** How a box is drawn on the card. */
export type DrawnBox = {
    x: number;
    y: number;
    width: number;
    height: number;
    radius: number;
    fill: string;
    stroke: string;
    // Words are a bar where they sit; a picture a block of shade
    look: 'shape' | 'words' | 'picture';
};

/** The least a box is drawn at, so a small one still shows. */
export const LEAST = 2;

/** Room kept round the drawing inside the card. */
export const INSET = 8;

const WORDS = new Set(['text', 'math']);
const PICTURES = new Set(['image', 'video']);

const none = (colour: string) =>
    !colour || colour === 'transparent' || colour === 'none';

/**
 * A board's drawing fitted into a card `width` by `height` pixels, whole and
 * centred, with INSET round it: each box in pixels, never smaller than
 * LEAST, and each line between box middles.
 */
export const fitBoard = (
    preview: BoardPreview,
    width: number,
    height: number,
): { boxes: DrawnBox[]; lines: [number, number, number, number][] } => {
    const roomX = Math.max(1, width - INSET * 2);
    const roomY = Math.max(1, height - INSET * 2);
    const aspect = preview.aspect > 0 ? preview.aspect : 1;

    // As big as fits both ways
    const drawnW = Math.min(roomX, roomY * aspect);
    const drawnH = drawnW / aspect;
    const left = (width - drawnW) / 2;
    const top = (height - drawnH) / 2;

    const px = (value: number) => Math.round(value * 100) / 100;

    const boxes = preview.boxes.map((box): DrawnBox => {
        const look = WORDS.has(box.kind)
            ? 'words'
            : PICTURES.has(box.kind)
              ? 'picture'
              : 'shape';
        const boxW = Math.max(LEAST, box.w * drawnW);
        const boxH = Math.max(LEAST, box.h * drawnH);
        const x = left + box.x * drawnW;
        const y = top + box.y * drawnH;

        // Words: a bar across the middle of where they sit
        if (look === 'words') {
            const bar = Math.min(boxH, Math.max(LEAST, boxH * 0.4));

            return {
                x: px(x),
                y: px(y + (boxH - bar) / 2),
                width: px(boxW * 0.85),
                height: px(bar),
                radius: px(bar / 2),
                fill: '',
                stroke: '',
                look,
            };
        }

        return {
            x: px(x),
            y: px(y),
            width: px(boxW),
            height: px(boxH),
            radius: px(Math.min(3, boxW / 4, boxH / 4)),
            fill: look === 'picture' || none(box.fill) ? '' : box.fill,
            stroke: none(box.stroke) ? '' : box.stroke,
            look,
        };
    });

    const lines = preview.lines.map(
        ([x1, y1, x2, y2]): [number, number, number, number] => [
            px(left + x1 * drawnW),
            px(top + y1 * drawnH),
            px(left + x2 * drawnW),
            px(top + y2 * drawnH),
        ],
    );

    return { boxes, lines };
};

/** "Note", "Board", "Table". */
export const kindLabel = (kind: RecentItem['kind']) =>
    kind.charAt(0).toUpperCase() + kind.slice(1);

/** "1 row", "9 rows". */
export const rowsLabel = (rows: number) =>
    `${rows.toLocaleString()} ${rows === 1 ? 'row' : 'rows'}`;
