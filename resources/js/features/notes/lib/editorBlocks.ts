import type { Editor } from '@tiptap/vue-3';
import type { Node as ProseMirrorNode } from '@tiptap/pm/model';

// A top-level block of the document, with the element rendering it
export interface Block {
    pos: number;
    node: ProseMirrorNode;
    dom: HTMLElement;
}

/**
 * The top-level block level with `clientY`. Probing inside the text column, so
 * the gutter to the left still maps to the block beside it.
 */
export const blockAt = (editor: Editor, clientY: number): Block | null => {
    const { view } = editor;
    const rect = view.dom.getBoundingClientRect();
    const hit = view.posAtCoords({ left: rect.left + 16, top: clientY });
    if (!hit) return null;

    const $pos = view.state.doc.resolve(hit.inside >= 0 ? hit.inside : hit.pos);
    const pos =
        $pos.depth > 0
            ? $pos.before(1)
            : hit.inside >= 0
              ? hit.inside
              : hit.pos;
    const node = view.state.doc.nodeAt(pos);
    const dom = view.nodeDOM(pos);

    return node && dom instanceof HTMLElement ? { pos, node, dom } : null;
};

/**
 * A block may have moved since it was hovered (edits above it): find it again
 * among the top-level blocks by its element.
 */
export const freshBlock = (
    editor: Editor,
    block: Block | null,
): Block | null => {
    if (!block || !block.dom.isConnected) return null;

    const { view } = editor;
    let found: Block | null = null;
    view.state.doc.forEach((node, offset) => {
        if (!found && view.nodeDOM(offset) === block.dom)
            found = { pos: offset, node, dom: block.dom };
    });

    return found;
};
