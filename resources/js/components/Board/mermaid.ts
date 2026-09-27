// Turning a Mermaid flowchart into things on the board.
//
// Mermaid's SVG carries the graph, not just a picture of it: a node's id is in
// the element's id, an edge's id names the two nodes it joins, and the classes
// say how the line is drawn. So a diagram can be read back as shapes and
// connectors that behave like any others -- draggable, pinned, presentable --
// rather than a flat image nobody can edit.
//
// It is a one-way import. Once the items are on the board they are the board's,
// and the Mermaid source is not kept: keeping both in step would mean owning a
// mapping between them for ever.
import { renderMermaid } from '@/lib/mermaid';
import type { Item, ItemKind, LineStyle } from './items';
import { makeItem } from './items';

export type MermaidImport =
    | { ok: true; items: Item[] }
    /** Not a flowchart: the caller should fall back to inserting the picture. */
    | { ok: false; reason: 'unsupported'; svg: string }
    | { ok: false; reason: 'invalid'; error: string };

type Box = { x: number; y: number; width: number; height: number };

/** How much of the board a diagram may take up before it is scaled down. */
const MAX_SPAN = 2400;

/**
 * The kind that best matches what Mermaid drew.
 *
 * Mermaid says nothing about which flowchart shape it used, so the drawing
 * itself is the evidence: how many points a polygon has, whether a rounded
 * rectangle is rounded, and whether a path was drawn with arcs (a database's
 * lid) or curves (a stadium's ends).
 */
const kindOf = (shape: SVGGraphicsElement): ItemKind => {
    const tag = shape.tagName.toLowerCase();

    if (tag === 'circle' || tag === 'ellipse') {
        return 'ellipse';
    }

    if (tag === 'polygon') {
        const points =
            (shape.getAttribute('points') ?? '').split(/[\s,]+/).filter(Boolean)
                .length / 2;

        if (points >= 8) {
            return 'process';
        }

        if (points === 6) {
            return 'hexagon';
        }

        // Four points with only two distinct heights lean rather than meet at
        // a top and a bottom: a parallelogram, not a decision
        const heights = new Set(
            (shape.getAttribute('points') ?? '')
                .split(/\s+/)
                .filter(Boolean)
                .map((pair) => Math.round(Number(pair.split(',')[1]))),
        );

        return heights.size <= 2 ? 'parallelogram' : 'diamond';
    }

    if (tag === 'path') {
        return /[aA]\s*-?\d/.test(shape.getAttribute('d') ?? '')
            ? 'cylinder'
            : 'pill';
    }

    return Number(shape.getAttribute('rx') ?? 0) > 4 ? 'pill' : 'rect';
};

/** Mermaid dashes a `-.->` and thickens a `==>`. */
const lineFrom = (
    classes: string,
): { lineStyle: LineStyle; lineWidth: number } => ({
    lineStyle: /pattern-(dotted|dashed)/.test(classes) ? 'dashed' : 'solid',
    lineWidth: /thickness-thick/.test(classes) ? 4 : 2,
});

/**
 * The two nodes an edge joins, from an id like `L_A_B_0`.
 *
 * A node's own id may contain underscores, so every split is tried and the one
 * naming two nodes that exist wins.
 */
const endsOf = (id: string, nodes: Set<string>): [string, string] | null => {
    const parts = id
        .replace(/^.*?L_/, '')
        .replace(/_\d+$/, '')
        .split('_');

    for (let at = 1; at < parts.length; at++) {
        const from = parts.slice(0, at).join('_');
        const to = parts.slice(at).join('_');

        if (nodes.has(from) && nodes.has(to)) {
            return [from, to];
        }
    }

    return null;
};

/**
 * Reads a diagram Mermaid has drawn into the page.
 *
 * Positions come from where the browser actually put each shape, converted
 * back into the diagram's own units, which is steadier than unpicking the
 * transforms Mermaid nests.
 */
const readDrawing = (host: HTMLElement) => {
    const svg = host.querySelector('svg');
    const frame = svg?.getBoundingClientRect();

    if (!svg || !frame?.width) {
        return null;
    }

    const scale = (svg.viewBox.baseVal.width || frame.width) / frame.width;

    const boxOf = (element: Element): Box => {
        const box = (element as SVGGraphicsElement).getBoundingClientRect();

        return {
            x: (box.left - frame.left) * scale,
            y: (box.top - frame.top) * scale,
            width: box.width * scale,
            height: box.height * scale,
        };
    };

    // `<render id>-flowchart-<node id>-<n>`, and `<render id>-<subgraph name>`
    const nameOfNode = (id: string) =>
        id.replace(/^.*?-flowchart-/, '').replace(/-\d+$/, '');

    const nodes = [...host.querySelectorAll('g.node')]
        .map((node) => {
            const shape = node.querySelector<SVGGraphicsElement>(
                'rect, polygon, circle, ellipse, path',
            );

            return shape
                ? {
                      id: nameOfNode(node.id),
                      kind: kindOf(shape),
                      label:
                          node
                              .querySelector('.nodeLabel, text')
                              ?.textContent?.trim() ?? '',
                      box: boxOf(shape),
                  }
                : null;
        })
        .filter((node) => node !== null);

    const names = new Set(nodes.map((node) => node.id));

    const labels = new Map(
        [...host.querySelectorAll('g.edgeLabel g.label[data-id]')].map(
            (label) => [
                label.getAttribute('data-id') ?? '',
                label.textContent?.trim() ?? '',
            ],
        ),
    );

    const edges = [
        ...host.querySelectorAll<SVGPathElement>('g.edgePaths path[id]'),
    ]
        .map((path) => {
            const ends = endsOf(path.id, names);

            return ends
                ? {
                      from: ends[0],
                      to: ends[1],
                      text: labels.get(path.id.replace(/^.*?(L_)/, '$1')) ?? '',
                      ...lineFrom(path.getAttribute('class') ?? ''),
                  }
                : null;
        })
        .filter((edge) => edge !== null);

    const frames = [...host.querySelectorAll('g.cluster')].map((cluster) => ({
        // The title is a <text> or an HTML span, depending on how Mermaid is
        // set up; the id carries the subgraph's name either way
        label:
            cluster.querySelector('.cluster-label')?.textContent?.trim() ||
            cluster.id.replace(/^mermaid-\d+-/, ''),
        box: boxOf(cluster.querySelector('rect') ?? cluster),
    }));

    return { nodes, edges, frames };
};

/**
 * A Mermaid flowchart as board items, laid out the way Mermaid laid it out,
 * with the top left of the diagram at `at`.
 *
 * Anything that is not a flowchart comes back as `unsupported` with the SVG,
 * for the caller to put on the board as a picture instead.
 */
export async function itemsFromMermaid(
    source: string,
    at: { x: number; y: number },
): Promise<MermaidImport> {
    const drawn = await renderMermaid(source);

    if (!drawn.ok) {
        return { ok: false, reason: 'invalid', error: drawn.error };
    }

    // Mermaid measures text, so the diagram has to be in the page to be read;
    // it is kept out of sight and taken away again either way.
    const host = document.createElement('div');

    host.setAttribute('aria-hidden', 'true');
    host.style.cssText =
        'position:fixed;left:-10000px;top:0;opacity:0;pointer-events:none';
    host.innerHTML = drawn.svg;
    document.body.appendChild(host);

    try {
        const diagram = readDrawing(host);

        if (!diagram?.nodes.length) {
            return { ok: false, reason: 'unsupported', svg: drawn.svg };
        }

        const spread = Math.max(
            ...diagram.nodes.map((node) => node.box.x + node.box.width),
            ...diagram.nodes.map((node) => node.box.y + node.box.height),
        );
        const scale = spread > MAX_SPAN ? MAX_SPAN / spread : 1;
        const place = (box: Box) => ({
            x: at.x + box.x * scale,
            y: at.y + box.y * scale,
            width: Math.max(12, box.width * scale),
            height: Math.max(12, box.height * scale),
        });

        const items: Item[] = [];

        // Subgraphs become frames, behind whatever sits on them
        for (const frame of diagram.frames) {
            const box = place(frame.box);
            const item = makeItem('frame', box.x, box.y);

            item.width = box.width;
            item.height = box.height;
            item.text = frame.label;
            items.push(item);
        }

        const byName = new Map<string, Item>();

        for (const node of diagram.nodes) {
            const box = place(node.box);
            const item = makeItem(node.kind, box.x, box.y);

            item.width = box.width;
            item.height = box.height;
            item.text = node.label;
            items.push(item);
            byName.set(node.id, item);
        }

        for (const edge of diagram.edges) {
            const from = byName.get(edge.from);
            const to = byName.get(edge.to);

            if (!from || !to) {
                continue;
            }

            const item = makeItem('arrow', 0, 0);

            item.x = 0;
            item.y = 0;
            item.text = edge.text;
            item.lineStyle = edge.lineStyle;
            item.lineWidth = edge.lineWidth;
            // Left free, so the line re-routes as the shapes are moved about
            item.from = { item: from.id, side: null, x: 0, y: 0 };
            item.to = { item: to.id, side: null, x: 0, y: 0 };
            items.push(item);
        }

        return { ok: true, items };
    } finally {
        host.remove();
    }
}
