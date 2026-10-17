// Steps of the browser's history a page makes within itself -- a prototype's
// screens in Play, each its own step for the browser's Back -- heard before
// Inertia hears them. Inertia draws its page afresh for any step of its own,
// losing what the page had (what was typed, the switches flipped); a listener
// a page adds comes after Inertia's, as the browser calls them in the order
// they were added, capture or not. So this one is added first, from app.ts,
// before Inertia starts, and asks the page that has claimed its steps.

/** Answers whether the step is the page's own: then Inertia never hears it. */
type Taker = (event: PopStateEvent) => boolean;

let taker: Taker | null = null;

if (typeof window !== 'undefined') {
    window.addEventListener('popstate', (event) => {
        if (taker?.(event)) {
            event.stopImmediatePropagation();
        }
    });
}

/** The page claims its steps; what it answers with gives them back. */
export function takePageSteps(take: Taker): () => void {
    taker = take;

    return () => {
        if (taker === take) {
            taker = null;
        }
    };
}
