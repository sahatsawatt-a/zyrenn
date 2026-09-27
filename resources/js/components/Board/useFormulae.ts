import type { ComputedRef, Ref } from 'vue';
import { computed, shallowRef, watch } from 'vue';
import type { Item } from './items';

type Placing = {
    items: Ref<Item[]> | ComputedRef<Item[]>;
    /** Where the board is being looked at from. */
    camera: {
        scale: Ref<number>;
        position: Ref<{ x: number; y: number }>;
    };
    /** Whatever is being typed into, which hides its own formula meanwhile. */
    editingId: Ref<string | null>;
};

/**
 * Formulae on a board.
 *
 * Konva draws shapes, not typeset mathematics, so a formula is laid over the
 * canvas as HTML that KaTeX has set, moved and scaled with the camera. KaTeX
 * and its stylesheet are only fetched once a board actually has one on it.
 */
export function useFormulae({ items, camera, editingId }: Placing) {
    const katex = shallowRef<typeof import('katex').default | null>(null);

    const formulae = computed(() =>
        items.value.filter((item) => item.kind === 'math' && !item.hidden),
    );

    watch(
        () => formulae.value.length > 0,
        async (needed) => {
            if (!needed || katex.value) {
                return;
            }

            const [module] = await Promise.all([
                import('katex'),
                import('katex/dist/katex.min.css'),
            ]);

            katex.value = module.default;
        },
        { immediate: true },
    );

    /** One formula, set from its LaTeX. A mistake in it is shown, not thrown. */
    const mathHtml = (item: Item): string =>
        katex.value
            ? katex.value.renderToString(item.text || '\\square', {
                  throwOnError: false,
                  displayMode: true,
              })
            : '';

    /** Where that formula sits on screen, and how big, as the camera moves. */
    const mathStyle = (item: Item) => {
        const scale = camera.scale.value;

        return {
            left: `${item.x * scale + camera.position.value.x}px`,
            top: `${item.y * scale + camera.position.value.y}px`,
            width: `${item.width * scale}px`,
            height: `${item.height * scale}px`,
            fontSize: `${item.fontSize * scale}px`,
            justifyContent:
                item.align === 'left'
                    ? 'flex-start'
                    : item.align === 'right'
                      ? 'flex-end'
                      : 'center',
            alignItems:
                item.verticalAlign === 'top'
                    ? 'flex-start'
                    : item.verticalAlign === 'bottom'
                      ? 'flex-end'
                      : 'center',
            // The formula is a picture of itself; the shape under it takes the
            // clicks
            opacity: editingId.value === item.id ? 0 : 1,
        };
    };

    return { formulae, mathHtml, mathStyle };
}
