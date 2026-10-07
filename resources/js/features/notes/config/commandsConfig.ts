import type { Editor, Range } from '@tiptap/core';
import type { SlashCommandItem } from '@/features/notes/composables/useSlashCommands';
import { defaultMermaidTemplate, mermaidTemplates } from './mermaidTemplates';

// Fired on the editor DOM so TiptapEditor opens the LaTeX popover for a new formula
export const MATH_EDIT_EVENT = 'math:edit';

// Fired on the editor DOM so TiptapEditor opens its picker; the detail says
// for what ('image' or 'video')
export const MEDIA_PICK_EVENT = 'media:pick';

const insertMath = (
    editor: Editor,
    range: Range,
    type: 'inline' | 'block',
    latex: string,
) => {
    const nodeName = type === 'block' ? 'blockMath' : 'inlineMath';

    editor
        .chain()
        .focus()
        .deleteRange(range)
        .insertContent({ type: nodeName, attrs: { latex } })
        .run();

    // Find the formula just inserted (it sits right before the cursor)
    const { doc, selection } = editor.state;
    let pos: number | null = null;
    doc.nodesBetween(
        Math.max(0, range.from - 2),
        Math.min(doc.content.size, selection.to + 1),
        (node, nodePos) => {
            if (node.type.name === nodeName) pos = nodePos;
        },
    );

    if (pos !== null) {
        editor.view.dom.dispatchEvent(
            new CustomEvent(MATH_EDIT_EVENT, { detail: { type, pos } }),
        );
    }
};

export const commandItems: SlashCommandItem[] = [
    {
        title: 'Heading 1',
        description: 'Big section heading.',
        icon: 'H1',
        keywords: ['h1', '#', 'title', 'heading', 'header', 'big'],
        command: ({ editor, range }) =>
            editor
                .chain()
                .focus()
                .deleteRange(range)
                .setNode('heading', { level: 1 })
                .run(),
    },
    {
        title: 'Heading 2',
        description: 'Medium section heading.',
        icon: 'H2',
        keywords: ['h2', '##', 'subtitle', 'subheading', 'heading', 'header'],
        command: ({ editor, range }) =>
            editor
                .chain()
                .focus()
                .deleteRange(range)
                .setNode('heading', { level: 2 })
                .run(),
    },
    {
        title: 'Heading 3',
        description: 'Small section heading.',
        icon: 'H3',
        keywords: ['h3', '###', 'heading', 'header', 'small heading'],
        command: ({ editor, range }) =>
            editor
                .chain()
                .focus()
                .deleteRange(range)
                .setNode('heading', { level: 3 })
                .run(),
    },
    {
        title: 'To-do List',
        description: 'Track tasks with checkboxes.',
        icon: '☑️',
        keywords: [
            'todo',
            'task',
            'tasks',
            'checkbox',
            'checklist',
            'check',
            '[]',
        ],
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).toggleTaskList().run(),
    },
    {
        title: 'Callout',
        description: 'Make writing stand out.',
        icon: '💡',
        keywords: [
            'note',
            'info',
            'tip',
            'warning',
            'alert',
            'highlight',
            'box',
            'aside',
        ],
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).wrapIn('callout').run(),
    },
    {
        title: 'Numbered List',
        description: 'Create a list with sequential numbers.',
        icon: '1.',
        keywords: ['ol', '1.', 'ordered', 'numbers', 'steps', 'list'],
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).toggleOrderedList().run(),
    },
    {
        title: 'Quote',
        description: 'Capture a quote or citation.',
        icon: '”',
        keywords: ['blockquote', 'citation', 'cite', '>', 'quotation'],
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).toggleBlockquote().run(),
    },
    // 💡 Premium Upgraded Code Block option
    {
        title: 'Code Block',
        description: 'Snippets with syntax highlighting.',
        icon: '‹›',
        keywords: ['code', '```', 'snippet', 'pre', 'program', 'source'],
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).toggleCodeBlock().run(),
    },
    {
        title: 'Table',
        description: 'Rows and columns with a header row.',
        icon: '▦',
        keywords: ['grid', 'spreadsheet', 'rows', 'columns', 'tbl', 'cells'],
        command: ({ editor, range }) =>
            editor
                .chain()
                .focus()
                .deleteRange(range)
                .insertTable({ rows: 3, cols: 3, withHeaderRow: true })
                .run(),
    },
    {
        title: 'Image',
        description: 'Upload, pick from Drive, or link.',
        icon: '🖼️',
        keywords: [
            'picture',
            'photo',
            'upload',
            'img',
            'drive',
            'pic',
            'image',
            'png',
            'jpg',
            'gif',
        ],
        command: ({ editor, range }) => {
            editor.chain().focus().deleteRange(range).run();
            editor.view.dom.dispatchEvent(
                new CustomEvent(MEDIA_PICK_EVENT, { detail: 'image' }),
            );
        },
    },
    {
        title: 'Video',
        description: 'Play a video from Drive, an upload, or a link.',
        icon: '🎬',
        keywords: [
            'movie',
            'clip',
            'film',
            'mp4',
            'upload',
            'drive',
            'youtube',
            'vid',
        ],
        command: ({ editor, range }) => {
            editor.chain().focus().deleteRange(range).run();
            editor.view.dom.dispatchEvent(
                new CustomEvent(MEDIA_PICK_EVENT, { detail: 'video' }),
            );
        },
    },
    {
        title: 'Inline Math',
        description: 'LaTeX formula inside a line of text.',
        icon: '∑',
        keywords: ['math', 'latex', 'katex', 'formula', '$', 'inline'],
        command: ({ editor, range }) =>
            insertMath(editor, range, 'inline', 'E = mc^2'),
    },
    {
        title: 'Equation',
        description: 'Centered LaTeX equation block.',
        icon: '∫',
        keywords: [
            'math',
            'latex',
            'katex',
            'formula',
            'block',
            '$$',
            'formula block',
        ],
        command: ({ editor, range }) =>
            insertMath(
                editor,
                range,
                'block',
                '\\int_0^1 x^2\\,dx = \\frac{1}{3}',
            ),
    },
    {
        title: 'Bullet List',
        description: 'Create a simple bulleted list.',
        icon: '•',
        keywords: ['ul', '-', '*', 'unordered', 'bullets', 'points', 'list'],
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).toggleBulletList().run(),
    },
    {
        title: 'Text',
        description: 'Just start writing plain text.',
        icon: '📄',
        keywords: ['p', 'paragraph', 'plain', 'normal', 'body', 'words'],
        command: ({ editor, range }) =>
            editor
                .chain()
                .focus()
                .deleteRange(range)
                .toggleNode('paragraph', 'paragraph')
                .run(),
    },
    {
        title: 'Page Break',
        description: 'Start a new page here in the PDF.',
        icon: '⤓',
        keywords: ['break', 'new page', 'pdf', 'print', 'pagebreak'],
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).setPageBreak().run(),
    },
    {
        title: 'Live value',
        description: 'A number from a trip or table, kept up to date: {{ … }}.',
        icon: '=',
        keywords: [
            'value',
            'formula',
            'total',
            'sum',
            'live',
            'variable',
            '{{',
            'number',
            'calc',
            'computed',
        ],
        command: ({ editor, range }) =>
            editor
                .chain()
                .focus()
                .deleteRange(range)
                // Opens to be written (see FormulaChip)
                .insertContent({ type: 'formula', attrs: { expression: '' } })
                .run(),
    },
    {
        title: 'Board',
        description: 'Show a board, or one of its frames, in this note.',
        icon: '▦',
        keywords: [
            'board',
            'canvas',
            'frame',
            'slide',
            'diagram',
            'whiteboard',
            'drawing',
            'sketch',
        ],
        command: ({ editor, range }) => {
            editor
                .chain()
                .focus()
                .deleteRange(range)
                .insertContent({
                    type: 'codeBlock',
                    attrs: { language: 'board' },
                    // Filled in from the block's own dropdowns
                    content: [{ type: 'text', text: 'ref: \nframe: all' }],
                })
                .run();
        },
    },
    {
        title: 'Trip',
        description: 'Show a trip, or one day of it, in this note.',
        icon: '✈',
        keywords: [
            'trip',
            'travel',
            'itinerary',
            'day',
            'plan',
            'holiday',
            'journey',
        ],
        command: ({ editor, range }) => {
            editor
                .chain()
                .focus()
                .deleteRange(range)
                .insertContent({
                    type: 'codeBlock',
                    attrs: { language: 'trip' },
                    // Filled in from the block's own dropdowns
                    content: [{ type: 'text', text: 'ref: \nday: all' }],
                })
                .run();
        },
    },
    {
        title: 'Link',
        description:
            'A link shown as a card, with its page’s title and picture.',
        icon: '🔗',
        keywords: [
            'url',
            'link',
            'web',
            'website',
            'site',
            'href',
            'bookmark',
            'card',
            'embed',
            'preview',
            'http',
        ],
        command: ({ editor, range }) => {
            editor
                .chain()
                .focus()
                .deleteRange(range)
                // Asks for the link itself (see LinkCard)
                .insertContent({
                    type: 'codeBlock',
                    attrs: { language: 'link' },
                })
                .run();
        },
    },
    {
        title: 'Map',
        description: 'Show a place on a map in this note.',
        icon: '📍',
        keywords: [
            'map',
            'place',
            'location',
            'address',
            'pin',
            'where',
            'gps',
            'coordinates',
            'spot',
            'venue',
            'directions',
            'geo',
        ],
        command: ({ editor, range }) => {
            editor
                .chain()
                .focus()
                .deleteRange(range)
                // Chosen in the block's own search (see MapBlock)
                .insertContent({
                    type: 'codeBlock',
                    attrs: { language: 'map' },
                })
                .run();
        },
    },
    {
        title: 'Mermaid Diagram',
        description: 'Flowcharts, sequence, gantt, pie and 20+ more.',
        icon: '◇',
        // e.g. "/pie" or "/gantt" finds this item; pick the type from the block's menu
        keywords: [
            'diagram',
            'chart',
            'graph',
            ...mermaidTemplates.map((template) => template.label),
        ],
        command: ({ editor, range }) => {
            editor
                .chain()
                .focus()
                .deleteRange(range)
                .insertContent({
                    type: 'codeBlock',
                    attrs: { language: 'mermaid' },
                    content: [
                        { type: 'text', text: defaultMermaidTemplate.source },
                    ],
                })
                .run();
        },
    },
];
