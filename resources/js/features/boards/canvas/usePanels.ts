import { useLocalStorage } from '@vueuse/core';
import type { Ref } from 'vue';
import { computed, ref } from 'vue';

/**
 * The panels on the right and on the left, open or put away; remembered
 * across boards, since it is how a person likes to work rather than
 * something about this board.
 *
 * On a phone the canvas is all there is room for: the panels start put away
 * whatever a wider screen remembers, come up over the bottom of the board one
 * at a time, and are not remembered there.
 */
export function usePanels(width: Ref<number>) {
    const panelKept = useLocalStorage('board.panel', true);
    const layersKept = useLocalStorage('board.layers', true);

    const compact = computed(() => width.value > 0 && width.value < 720);
    const sheet = ref<'layers' | 'panel' | null>(null);

    const openable = (name: 'layers' | 'panel', kept: typeof panelKept) =>
        computed({
            get: () => (compact.value ? sheet.value === name : kept.value),
            set: (open: boolean) => {
                if (compact.value) {
                    sheet.value = open ? name : null;
                } else {
                    kept.value = open;
                }
            },
        });

    const panelOpen = openable('panel', panelKept);
    const layersOpen = openable('layers', layersKept);

    /** The part of the canvas the floating panels and the tool bar leave uncovered. */
    const uncovered = () => ({
        left: layersOpen.value && !compact.value ? 256 : 0,
        right: panelOpen.value && !compact.value ? 280 : 0,
        bottom: 56,
    });

    return { compact, sheet, panelOpen, layersOpen, uncovered };
}
