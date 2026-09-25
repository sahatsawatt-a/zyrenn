import type { Mermaid } from 'mermaid';

let mermaidPromise: Promise<Mermaid> | null = null;
let initializedTheme: string | null = null;
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

/**
 * Renders Mermaid source to SVG. Renders are serialized because Mermaid's
 * config is global, and each uses a fresh element id so parallel blocks never
 * collide.
 */
export function renderMermaid(source: string): Promise<MermaidResult> {
    const run = async (): Promise<MermaidResult> => {
        const mermaid = await loadMermaid();
        const theme = isDarkMode() ? 'dark' : 'default';

        if (initializedTheme !== theme) {
            mermaid.initialize({
                startOnLoad: false,
                theme,
                // Diagrams are user-written and injected with v-html
                securityLevel: 'strict',
            });
            initializedTheme = theme;
        }

        try {
            // Validate first so a syntax error never leaves Mermaid's error SVG in the page
            await mermaid.parse(source);

            const { svg } = await mermaid.render(
                `mermaid-${++renderCount}`,
                source,
            );

            return { ok: true, svg };
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
