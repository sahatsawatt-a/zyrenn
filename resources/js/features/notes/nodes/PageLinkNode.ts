import { Extension, InputRule, Node, mergeAttributes } from '@tiptap/core';
import type { Editor } from '@tiptap/core';
import { PluginKey } from '@tiptap/pm/state';
import Suggestion from '@tiptap/suggestion';
import type { SuggestionOptions } from '@tiptap/suggestion';
import { VueNodeViewRenderer } from '@tiptap/vue-3';
import PageLinkChip from '@/features/notes/components/PageLinkChip.vue';
import type { SlashCommandItem } from '@/features/notes/composables/useSlashCommands';
import { KINDS, searchPages } from '@/features/notes/lib/pageLinks';

// A link to another page by its name, [[Budget]]. The note keeps which page
// it is -- its kind and ref_id -- so the link holds when the page is renamed,
// and the chip shows what the page is called now (features/notes/lib/pageLinks).
// In Markdown it is [[Title]], found by title when the note is saved
// (App\Support\Note\PageLinks).
export const PageLinkNode = Node.create({
    name: 'pageLink',
    group: 'inline',
    inline: true,
    atom: true,
    selectable: true,

    addAttributes() {
        const kept = (name: string, attribute: string) => ({
            default: '',
            parseHTML: (element: HTMLElement) =>
                element.getAttribute(attribute) ?? '',
            renderHTML: (attributes: Record<string, string>) =>
                attributes[name] ? { [attribute]: attributes[name] } : {},
        });

        return {
            // note, board, table or trip; empty until the page is found
            kind: kept('kind', 'data-kind'),
            // The page's ref_id; empty while the link only names a page
            ref: kept('ref', 'data-ref'),
            // What the page was called when last seen, or the name wanted
            title: {
                default: '',
                parseHTML: (element: HTMLElement) =>
                    element.getAttribute('data-page-link') ?? '',
                renderHTML: (attributes: Record<string, string>) => ({
                    'data-page-link': attributes.title,
                }),
            },
            // Words to show in place of the page's name, when there are any
            text: kept('text', 'data-text'),
        };
    },

    parseHTML() {
        return [{ tag: 'span[data-page-link]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['span', mergeAttributes(HTMLAttributes)];
    },

    // Copied out as text, it reads as it is written
    renderText({ node }) {
        return node.attrs.text
            ? `[[${node.attrs.title}|${node.attrs.text}]]`
            : `[[${node.attrs.title}]]`;
    },

    addNodeView() {
        return VueNodeViewRenderer(PageLinkChip);
    },

    // Typed out in full, [[Title]] or [[Title|words]] becomes a link to the
    // page of that name: the chip finds which page that is
    addInputRules() {
        return [
            new InputRule({
                find: /\[\[([^[\]\n|]+)(?:\|([^[\]\n]+))?\]\]$/,
                handler: ({ state, range, match }) => {
                    state.tr.replaceWith(
                        range.from,
                        range.to,
                        this.type.create({
                            title: match[1].trim(),
                            text: (match[2] ?? '').trim(),
                        }),
                    );
                },
            }),
        ];
    },
});

/**
 * Typing [[ offers the pages there are to link to, by name, as the letters
 * after it narrow them; choosing one puts the link in. `shown` is handed each
 * list as it arrives, for the menu that draws it.
 */
export const PageLinkSuggest = (
    render: () => Omit<
        SuggestionOptions<SlashCommandItem>,
        'editor' | 'char'
    >['render'],
    shown: (items: SlashCommandItem[]) => void,
) =>
    Extension.create({
        name: 'pageLinkSuggest',

        addProseMirrorPlugins() {
            // The answer to the letters typed last, not to an earlier, slower one
            let latest = 0;

            return [
                Suggestion<SlashCommandItem>({
                    editor: this.editor,
                    // Its own key: the slash menu is a suggestion too
                    pluginKey: new PluginKey('pageLinkSuggest'),
                    char: '[[',
                    // A title has spaces in it, and a link can follow anything
                    allowSpaces: true,
                    allowedPrefixes: null,
                    command: ({ editor, range, props }) =>
                        props.command({ editor, range }),
                    items: async ({ query }) => {
                        const asked = ++latest;
                        const pages = await searchPages(
                            query.replace(/\]+$/, ''),
                        );
                        const items = pages.map((page): SlashCommandItem => ({
                            title: page.title,
                            description: page.folder
                                ? `${KINDS[page.kind].name} · ${page.folder}`
                                : KINDS[page.kind].name,
                            icon: KINDS[page.kind].sign,
                            command: ({
                                editor,
                                range,
                            }: {
                                editor: Editor;
                                range: { from: number; to: number };
                            }) =>
                                editor
                                    .chain()
                                    .focus()
                                    .deleteRange(range)
                                    .insertContent([
                                        {
                                            type: 'pageLink',
                                            attrs: {
                                                kind: page.kind,
                                                ref: page.ref,
                                                title: page.title,
                                            },
                                        },
                                        { type: 'text', text: ' ' },
                                    ])
                                    .run(),
                        }));

                        if (asked === latest) {
                            shown(items);
                        }

                        return items;
                    },
                    render: render as SuggestionOptions<SlashCommandItem>['render'],
                }),
            ];
        },
    });
