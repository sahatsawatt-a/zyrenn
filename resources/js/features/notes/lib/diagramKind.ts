/** A starting point for a diagram: a few lines of source to change. */
export type DiagramTemplate = { id: string; label: string; source: string };

export type DiagramResult =
    | { ok: true; svg: string }
    | { ok: false; error: string };

/**
 * A kind of block drawn from its own text -- a Mermaid diagram, a circuit.
 * DiagramBlock is the block; this is what differs from one kind to the next.
 */
export type DiagramKind = {
    // The start of its class names ("mermaid-block"), for print styles
    name: string;
    // What one is called when its source matches no template
    title: string;
    custom: string;
    empty: string;
    groups: { name: string; templates: DiagramTemplate[] }[];
    /** The template this source is still a version of, if any. */
    detect: (source: string) => DiagramTemplate | undefined;
    render: (source: string) => Promise<DiagramResult>;
    /** The drawing as a file of its own, light on white. */
    image: (source: string, type: 'png' | 'svg') => Promise<Blob>;
    // Drawn in the theme's own colours, so drawn again when the theme flips
    themed: boolean;
};
