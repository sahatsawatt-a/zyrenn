import { computed, ref, shallowRef } from 'vue';

export type ViewerItem =
    | { type: 'image'; src: string; alt?: string }
    // Already-rendered markup, e.g. a Mermaid diagram
    | { type: 'svg'; svg: string; title?: string };

// One viewer for the whole page, so any block can open it
const items = shallowRef<ViewerItem[]>([]);
const index = ref(0);

export function useMediaViewer() {
    const isOpen = computed(() => items.value.length > 0);
    const current = computed<ViewerItem | undefined>(
        () => items.value[index.value],
    );

    const open = (list: ViewerItem[], start = 0) => {
        items.value = list;
        index.value = Math.min(Math.max(start, 0), list.length - 1);
    };

    const close = () => {
        items.value = [];
        index.value = 0;
    };

    const step = (delta: number) => {
        const count = items.value.length;

        if (count > 1) {
            index.value = (index.value + delta + count) % count;
        }
    };

    return { items, index, isOpen, current, open, close, step };
}
