import type Konva from 'konva';
import type { Ref } from 'vue';
import { nextTick, onMounted, watch } from 'vue';
import { isSymbol, snapped } from '../circuit/symbols';
import type { Item } from '../model/items';
import { isConnector } from '../model/items';
import type { useBoard } from './useBoard';

type Handles = {
    board: ReturnType<typeof useBoard>;
    transformer: () => Konva.Transformer | undefined;
    stage: () => Konva.Stage | undefined;
    presenting: Ref<boolean>;
    editingId: Ref<string | null>;
    /** Folds Konva's scaling back into the items' own sizes. */
    applyTransform: () => void;
};

/**
 * The handles for resizing and turning, bound to whatever is selected, and
 * what a resize or a turn does to the items under them.
 */
export function useTransformer({
    board,
    transformer,
    stage,
    presenting,
    editingId,
    applyTransform,
}: Handles) {
    // The handles follow their nodes as those move or resize by themselves;
    // what they are bound to only changes with the selection or what is on
    // the board
    watch(
        [
            board.selection,
            () => board.items.value.map((item) => item.id).join(),
            presenting,
            editingId,
        ],
        async () => {
            // Something chosen from off the screen (from the layers, say) is
            // only drawn now: its node is there once this update has mounted
            await nextTick();

            const handles = transformer();
            const target = stage();

            if (!handles || !target) {
                return;
            }

            // While typing, the caret is the only frame worth showing: the
            // transformer's box and handles sat behind the editor as a second,
            // slightly larger box around the same words.
            const nodes =
                presenting.value || editingId.value
                    ? []
                    : board.selection.value
                          .filter(
                              (id) =>
                                  !isConnector(
                                      board.byId.value.get(id) ?? ({} as Item),
                                  ),
                          )
                          .map((id) => target.findOne(`#${id}`))
                          .filter((node): node is Konva.Node => !!node);

            handles.nodes(nodes);
        },
        { flush: 'post' },
    );

    // Bound on the transformer itself: Konva announces a transform with
    // node._fire(), which does not bubble, so a layer-level handler never
    // hears it -- which is why a resize only landed when the handle was let go.
    onMounted(() => {
        const handles = transformer();

        // The undo step is taken before the first frame changes anything
        handles?.on('transformstart', () => board.commit());
        handles?.on('transform', () => {
            applyTransform();
            // Konva measures its handles straight after this, before the shape
            // has been drawn at its new size, and works the next frame out
            // from that stale box: the shape then ran ahead of the pointer and
            // back, like a shaking hand. Measured again once it is drawn, they
            // agree.
            void nextTick(() => handles.forceUpdate());
        });
        handles?.on('transformend', () => {
            applyTransform();
            // A part let go of goes back onto the grid its pins belong on
            board.selected.value
                .filter(isSymbol)
                .forEach((item) => Object.assign(item, snapped(item)));
            // A frame stretched over things already there would hide them
            board.settle();
        });
    });
}
