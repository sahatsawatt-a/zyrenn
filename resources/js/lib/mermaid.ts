import type { Mermaid } from 'mermaid';

let mermaidPromise: Promise<Mermaid> | null = null;
let initializedWith: string | null = null;
let queue: Promise<unknown> = Promise.resolve();
let renderCount = 0;

// Mermaid is large, so it is only downloaded once a diagram is on screen
function loadMermaid(): Promise<Mermaid> {
    mermaidPromise ??= import('mermaid').then((module) => module.default);

    return mermaidPromise;
}

export function isDarkMode(): boolean {
    return document.documentElement.classList.contains('dark');
}

export type MermaidResult =
    | { ok: true; svg: string }
    | { ok: false; error: string };

export type MermaidOptions = {
    /** Light or dark; by default whichever the app is in. */
    theme?: 'default' | 'dark';
    /**
     * Labels as HTML inside the SVG (Mermaid's default) or as plain SVG text,
     * which anything that opens the file on its own can draw.
     */
    htmlLabels?: boolean;
};

/**
 * Renders Mermaid source to SVG. Renders are serialized because Mermaid's
 * config is global, and each uses a fresh element id so parallel blocks never
 * collide.
 */
export function renderMermaid(
    source: string,
    options: MermaidOptions = {},
): Promise<MermaidResult> {
    const run = async (): Promise<MermaidResult> => {
        const mermaid = await loadMermaid();
        const theme = options.theme ?? (isDarkMode() ? 'dark' : 'default');
        const htmlLabels = options.htmlLabels ?? true;
        const config = `${theme}:${htmlLabels}`;

        if (initializedWith !== config) {
            mermaid.initialize({
                startOnLoad: false,
                theme,
                htmlLabels,
                // Diagrams are user-written and injected with v-html
                securityLevel: 'strict',
            });
            initializedWith = config;
        }

        try {
            // Validate first so a syntax error never leaves Mermaid's error SVG in the page
            await mermaid.parse(source);

            const { svg } = await mermaid.render(
                `mermaid-${++renderCount}`,
                source,
            );

            return { ok: true, svg: roomForErMarkers(svg) };
        } catch (error) {
            return {
                ok: false,
                error: error instanceof Error ? error.message : String(error),
            };
        }
    };

    const result = queue.then(run, run);
    queue = result.catch(() => undefined);

    return result;
}

type Point = { x: number; y: number };

// How far back from a line's end an ER marker reaches: a zero-or-more's circle
// sits 28px back with a 6px radius, and the bend before it needs its corner
const MARKER_ROOM = 45;
const CORNER = 7;

type Moved = {
    along: 'x' | 'y';
    away: number;
    /** Where the bend now is, along the stretch into the box. */
    at: number;
    /** The run across, between the two moved points. */
    across: [number, number];
    /** As far back as the run before the bend is free: where it begins, short
     * of the far end's own marker when it begins at that end. */
    from: number;
    /** Out of a box and back into the same side of it. */
    loop: boolean;
};

/**
 * Moves a bend back from the end of a line, so the last straight stretch is
 * long enough to hold its marker. Mermaid often bends an ER line just short
 * of the box, and the marker -- drawn along that last stretch -- then floats
 * beside the line. Only a bend between two straight runs is moved, and only
 * when the run before it has the length to spare.
 */
const makeRoomAtEnd = (
    points: Point[],
    farEndMarked: boolean,
): Moved | null => {
    const count = points.length;

    if (count < 4) return null;

    const end = points[count - 1];
    const bend = points[count - 2];
    const before = points[count - 3];
    const start = points[count - 4];

    // The stretch into the box runs along one axis, the one before across it
    const along: 'x' | 'y' | null =
        Math.abs(bend.x - end.x) < 0.5
            ? 'y'
            : Math.abs(bend.y - end.y) < 0.5
              ? 'x'
              : null;

    if (!along) return null;

    const across = along === 'y' ? 'x' : 'y';
    const short = Math.abs(bend[along] - end[along]);
    const step = MARKER_ROOM - short;

    if (
        step <= 0 ||
        Math.abs(before[along] - bend[along]) >= 0.5 ||
        Math.abs(start[across] - before[across]) >= 0.5
    ) {
        return null;
    }

    const away = Math.sign(bend[along] - end[along]) || 1;
    const target = end[along] + away * MARKER_ROOM;
    const span: [number, number] = [
        Math.min(before[across], bend[across]),
        Math.max(before[across], bend[across]),
    ];

    // A U -- a line out of a box and back into the same side, as a
    // relationship with itself is drawn -- has room made by pushing its far
    // run further out, which lengthens both of its ends at once
    if ((start[along] - before[along]) * away < 0) {
        bend[along] = target;
        before[along] = target;

        return {
            along,
            away,
            at: target,
            across: span,
            from: target,
            loop: true,
        };
    }

    const free =
        start[along] - (count === 4 && farEndMarked ? away * MARKER_ROOM : 0);

    // The run before must come from further back, clear of the far marker
    if ((free - target) * away < 0) return null;

    bend[along] = target;
    before[along] = target;

    return {
        along,
        away,
        at: target,
        across: span,
        from: free,
        loop: false,
    };
};

const translateOf = (element: Element | null): Point | null => {
    const match = element
        ?.getAttribute('transform')
        ?.match(/translate\(\s*([-\d.e]+)[\s,]+([-\d.e]+)\s*\)/);

    return match ? { x: Number(match[1]), y: Number(match[2]) } : null;
};

type Box = { left: number; top: number; right: number; bottom: number };

/**
 * A line's label, slid back along the line it sits on when a moved bend
 * would now run through it -- or, for a loop, put just outside it. Answers
 * with where the label now is.
 */
const clearLabel = (host: Element, id: string, moved: Moved): Box | null => {
    const label = [...host.querySelectorAll('g.edgeLabel > g.label')].find(
        (element) => element.getAttribute('data-id') === id,
    );
    const group = label?.parentElement ?? null;
    const centre = translateOf(group);
    // The label is drawn from its top left, half its size back from the centre
    const offset = translateOf(label ?? null);

    if (!group || !centre || !offset) return null;

    const { along, away, at } = moved;
    const across = along === 'y' ? 'x' : 'y';
    const half = { x: Math.abs(offset.x), y: Math.abs(offset.y) };
    const gap = 4;

    // A loop's label is in the way anywhere inside it, or on its far run
    const reach = moved.loop
        ? (centre[along] - at) * away < half[along] + gap
        : Math.abs(centre[along] - at) < half[along] + gap;
    const crosses =
        reach &&
        centre[across] + half[across] > moved.across[0] &&
        centre[across] - half[across] < moved.across[1];

    if (!crosses) return null;

    const placed = at + away * (half[along] + gap + CORNER);
    const [left, right] = moved.across;

    if (moved.loop) {
        // Beside the loop, facing its far run
        centre[along] = at + away * (half[along] + gap);
        centre[across] = (left + right) / 2;
    } else if ((moved.from - (placed + away * half[along])) * away >= 0) {
        // Back along the run it was on, past the bend
        centre[along] = placed;
    } else if (right - left >= 2 * (half[across] + CORNER)) {
        // No room there: on the middle of the run across, as Mermaid puts
        // labels on their lines
        centre[along] = at;
        centre[across] = (left + right) / 2;
    } else {
        return null;
    }

    group.setAttribute('transform', `translate(${centre.x}, ${centre.y})`);

    return {
        left: centre.x - half.x,
        top: centre.y - half.y,
        right: centre.x + half.x,
        bottom: centre.y + half.y,
    };
};

// Grows the drawing's viewBox to take in what was moved past its edge
const fitViewBox = (host: Element, extents: Box[]): void => {
    const svg = host.querySelector('svg');
    const box = svg
        ?.getAttribute('viewBox')
        ?.trim()
        .split(/[\s,]+/)
        .map(Number);

    if (!svg || box?.length !== 4 || box.some((value) => !isFinite(value))) {
        return;
    }

    const margin = 8;
    const [x, y, width, height] = box;
    const left = Math.min(x, ...extents.map((e) => e.left - margin));
    const top = Math.min(y, ...extents.map((e) => e.top - margin));
    const right = Math.max(x + width, ...extents.map((e) => e.right + margin));
    const bottom = Math.max(
        y + height,
        ...extents.map((e) => e.bottom + margin),
    );

    if (left === x && top === y && right === x + width && bottom === y + height) {
        return;
    }

    svg.setAttribute('viewBox', `${left} ${top} ${right - left} ${bottom - top}`);

    // Mermaid caps the drawing at its own width, which has just grown
    if (svg.style.maxWidth) {
        svg.style.maxWidth = `${right - left}px`;
    }
};

// The corners of a line only: repeated points, and points partway along a
// straight run, are dropped
const corners = (points: Point[]): Point[] =>
    points.filter((point, index) => {
        const previous = points[index - 1];
        const next = points[index + 1];

        if (!previous) return true;

        if (Math.hypot(point.x - previous.x, point.y - previous.y) < 0.01) {
            return false;
        }

        if (!next) return true;

        const turn =
            (point.x - previous.x) * (next.y - point.y) -
            (point.y - previous.y) * (next.x - point.x);

        return Math.abs(turn) > 0.01;
    });

// A polyline with rounded corners, as Mermaid draws its ER lines
const roundedPath = (kept: Point[]): string => {
    const toward = (from: Point, to: Point, distance: number): Point => {
        const length = Math.hypot(to.x - from.x, to.y - from.y);

        return {
            x: from.x + ((to.x - from.x) / length) * distance,
            y: from.y + ((to.y - from.y) / length) * distance,
        };
    };

    let path = `M${kept[0].x},${kept[0].y}`;

    for (let index = 1; index < kept.length - 1; index++) {
        const [previous, corner, next] = kept.slice(index - 1, index + 2);
        const radius = Math.min(
            CORNER,
            Math.hypot(corner.x - previous.x, corner.y - previous.y) / 2,
            Math.hypot(next.x - corner.x, next.y - corner.y) / 2,
        );
        const into = toward(corner, previous, radius);
        const out = toward(corner, next, radius);

        path += `L${into.x},${into.y}Q${corner.x},${corner.y} ${out.x},${out.y}`;
    }

    const last = kept[kept.length - 1];

    return `${path}L${last.x},${last.y}`;
};

/** An ER diagram with each relationship's markers on a straight stretch of its line. */
function roomForErMarkers(svg: string): string {
    if (!svg.includes('relationshipLine')) return svg;

    const host = document.createElement('div');
    host.innerHTML = svg;

    const extents: Box[] = [];

    for (const line of host.querySelectorAll<SVGPathElement>(
        'path.relationshipLine[data-points]',
    )) {
        let points: Point[];

        try {
            points = JSON.parse(atob(line.dataset.points ?? ''));
        } catch {
            continue;
        }

        if (!Array.isArray(points)) continue;

        points = corners(points);

        if (points.length < 4) continue;

        const before = JSON.stringify(points);

        const moved: Moved[] = [];
        const room = (side: Moved | null) => side && moved.push(side);

        const startMarked = line.hasAttribute('marker-start');
        const endMarked = line.hasAttribute('marker-end');

        if (endMarked) room(makeRoomAtEnd(points, startMarked));

        if (startMarked) {
            points.reverse();
            room(makeRoomAtEnd(points, endMarked));
            points.reverse();
        }

        if (JSON.stringify(points) === before) continue;

        for (const side of moved) {
            const label = clearLabel(host, line.dataset.id ?? '', side);

            if (label) extents.push(label);
        }

        // Room for a marker's width either side of the line
        for (const point of points) {
            extents.push({
                left: point.x - 18,
                top: point.y - 18,
                right: point.x + 18,
                bottom: point.y + 18,
            });
        }

        line.setAttribute('d', roundedPath(points));
        line.dataset.points = btoa(JSON.stringify(points));
    }

    if (extents.length) fitViewBox(host, extents);

    return host.innerHTML;
}

/**
 * A diagram as a file of its own: light, on white, with its labels as plain
 * SVG text so it reads the same in an image viewer, a slide or the Drive's
 * preview as in the note. A PNG is drawn at twice the size for sharp text.
 */
export async function mermaidImage(
    source: string,
    type: 'png' | 'svg',
): Promise<Blob> {
    const drawn = await renderMermaid(source, {
        theme: 'default',
        htmlLabels: false,
    });

    if (!drawn.ok) {
        throw new Error(drawn.error);
    }

    // Read through the HTML parser, as the note shows it, and written back
    // out as XML, which is what a file (or an <img>) needs
    const host = document.createElement('div');
    host.innerHTML = drawn.svg;

    const svg = host.querySelector('svg');
    const box = svg?.viewBox.baseVal;

    if (!svg || !box?.width || !box.height) {
        throw new Error('This diagram has nothing to draw.');
    }

    // Mermaid sizes it to fill the page (width="100%"); a file needs its own size
    svg.setAttribute('width', String(Math.ceil(box.width)));
    svg.setAttribute('height', String(Math.ceil(box.height)));
    svg.style.removeProperty('max-width');

    const paper = document.createElementNS(svg.namespaceURI, 'rect');
    paper.setAttribute('x', String(box.x));
    paper.setAttribute('y', String(box.y));
    paper.setAttribute('width', String(box.width));
    paper.setAttribute('height', String(box.height));
    paper.setAttribute('fill', '#ffffff');
    svg.prepend(paper);

    const markup = new XMLSerializer().serializeToString(svg);

    if (type === 'svg') {
        return new Blob([markup], { type: 'image/svg+xml' });
    }

    const picture = new Image();
    picture.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(markup)}`;
    await picture.decode();

    // Twice the size, short of what a browser will hold in one canvas
    const scale = Math.min(
        2,
        8192 / box.width,
        8192 / box.height,
        Math.sqrt(32_000_000 / (box.width * box.height)),
    );
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(box.width * scale);
    canvas.height = Math.round(box.height * scale);

    const context = canvas.getContext('2d')!;
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(picture, 0, 0, canvas.width, canvas.height);

    return new Promise((resolve, reject) =>
        canvas.toBlob(
            (blob) =>
                blob
                    ? resolve(blob)
                    : reject(new Error('Couldn’t draw this diagram.')),
            'image/png',
        ),
    );
}
