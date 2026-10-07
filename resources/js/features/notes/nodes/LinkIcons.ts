import { Extension } from '@tiptap/core';
import type { Node as ProseMirrorNode } from '@tiptap/pm/model';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';
import { hostOf, iconUrl, isSafeUrl } from '@/features/notes/lib/links';

// How a link in a note looks and opens. Each link to a site has the site's
// icon before it, so where it goes shows at a glance -- drawn beside the text,
// not in it, so the note's Markdown is the link alone. Writing, a click puts
// the caret in a link (LinkMenu then offers to open it); Ctrl or Cmd and a
// click opens it at once, and so does any click on a note that can't be edited.

const key = new PluginKey('linkIcons');

const iconsIn = (doc: ProseMirrorNode) => {
    const decorations: Decoration[] = [];
    let previous: { href: string; end: number } | null = null;

    doc.descendants((node, pos) => {
        if (!node.isText) {
            return;
        }

        const href = node.marks.find((mark) => mark.type.name === 'link')?.attrs
            .href as string | undefined;

        // One icon a link, even when bold or italic splits it into pieces
        if (
            href &&
            /^https?:/i.test(href) &&
            !(previous?.href === href && previous.end === pos)
        ) {
            const host = hostOf(href);
            decorations.push(
                Decoration.widget(
                    pos,
                    () => {
                        const icon = document.createElement('img');
                        icon.src = iconUrl(host);
                        icon.alt = '';
                        icon.className = 'link-icon';
                        icon.setAttribute('aria-hidden', 'true');
                        // A site with no icon has nothing in its place. Hidden,
                        // not removed: the editor owns this element, and one
                        // taken away under it redraws the line and loses the caret
                        icon.onerror = () => (icon.style.display = 'none');

                        return icon;
                    },
                    { side: 1, key: `icon:${host}`, ignoreSelection: true },
                ),
            );
        }

        previous = href ? { href, end: pos + node.nodeSize } : null;
    });

    return DecorationSet.create(doc, decorations);
};

export const LinkIcons = Extension.create({
    name: 'linkIcons',

    addProseMirrorPlugins() {
        const editor = this.editor;

        return [
            new Plugin({
                key,
                state: {
                    init: (_, state) => iconsIn(state.doc),
                    apply: (transaction, icons) =>
                        transaction.docChanged
                            ? iconsIn(transaction.doc)
                            : icons,
                },
                props: {
                    decorations: (state) => key.getState(state),
                    handleClick: (_view, _pos, event) => {
                        const link = (event.target as HTMLElement).closest(
                            'a[href]',
                        );
                        const href = link?.getAttribute('href') ?? '';

                        if (
                            !link ||
                            !isSafeUrl(href) ||
                            (editor.isEditable &&
                                !event.metaKey &&
                                !event.ctrlKey)
                        ) {
                            return false;
                        }

                        window.open(href, '_blank', 'noopener,noreferrer');

                        return true;
                    },
                },
            }),
        ];
    },
});
