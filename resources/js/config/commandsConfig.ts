// resources/js/config/commandsConfig.ts
import type { Editor, Range } from '@tiptap/core';
import type { SlashCommandItem } from '../composables/useSlashCommands';
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
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).toggleTaskList().run(),
    },
    {
        title: 'Callout',
        description: 'Make writing stand out.',
        icon: '💡',
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).wrapIn('callout').run(),
    },
    {
        title: 'Numbered List',
        description: 'Create a list with sequential numbers.',
        icon: '1.',
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).toggleOrderedList().run(),
    },
    {
        title: 'Quote',
        description: 'Capture a quote or citation.',
        icon: '”',
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).toggleBlockquote().run(),
    },
    // 💡 Premium Upgraded Code Block option
    {
        title: 'Code Block',
        description: 'Snippets with syntax highlighting.',
        icon: '‹›',
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).toggleCodeBlock().run(),
    },
    {
        title: 'Table',
        description: 'Rows and columns with a header row.',
        icon: '▦',
        keywords: ['grid', 'spreadsheet', 'rows', 'columns'],
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
        keywords: ['picture', 'photo', 'upload', 'img', 'drive'],
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
        keywords: ['movie', 'clip', 'film', 'mp4', 'upload', 'drive'],
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
        keywords: ['math', 'latex', 'katex', 'formula'],
        command: ({ editor, range }) =>
            insertMath(editor, range, 'inline', 'E = mc^2'),
    },
    {
        title: 'Equation',
        description: 'Centered LaTeX equation block.',
        icon: '∫',
        keywords: ['math', 'latex', 'katex', 'formula', 'block'],
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
        command: ({ editor, range }) =>
            editor.chain().focus().deleteRange(range).toggleBulletList().run(),
    },
    {
        title: 'Text',
        description: 'Just start writing plain text.',
        icon: '📄',
        command: ({ editor, range }) =>
            editor
                .chain()
                .focus()
                .deleteRange(range)
                .toggleNode('paragraph', 'paragraph')
                .run(),
    },
    {
        title: 'Board',
        description: 'Show a board, or one of its frames, in this note.',
        icon: '▦',
        keywords: ['board', 'canvas', 'frame', 'slide', 'diagram'],
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
