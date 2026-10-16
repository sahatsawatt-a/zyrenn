import { useElementSize } from '@vueuse/core';
import { computed } from 'vue';
import type { Ref } from 'vue';

// How much room the workflow editor has: on a narrow screen the canvas has
// the width to itself, and the palette and a step's settings open over it

// Narrower than this, the panels beside the canvas would leave none of it
const COMPACT_BELOW = 900;
// Wide enough for panels beside the canvas, but not for all of them at once
const TIGHT_BELOW = 1200;

export function useEditorSpace(editor: Ref<HTMLElement | null>) {
    const { width } = useElementSize(editor);
    const compact = computed(
        () =>
            (width.value ||
                (typeof window === 'undefined' ? 1200 : window.innerWidth)) <
            COMPACT_BELOW,
    );
    const tight = computed(() => !compact.value && width.value < TIGHT_BELOW);

    return { compact, tight };
}

// What is shown beside the canvas is remembered, as a convenience
export function kept(name: string) {
    try {
        return localStorage.getItem(name) !== 'hidden';
    } catch {
        return true;
    }
}

export function keep(name: string, shown: boolean) {
    try {
        localStorage.setItem(name, shown ? 'shown' : 'hidden');
    } catch {
        // Only a convenience
    }
}
