import { Node, mergeAttributes } from '@tiptap/core';
import { VueNodeViewRenderer } from '@tiptap/vue-3';
import MediaView from '@/features/notes/components/MediaView.vue';
import { sizeAttribute } from './ImageNode';

// A video from the Drive or a link, played in place. It shares its view, and
// its small / medium / full widths, with ImageNode.
export const VideoNode = Node.create({
    name: 'video',
    group: 'block',
    atom: true,
    draggable: true,

    addAttributes() {
        return {
            src: { default: null },
            // The file's name, shown when it is opened full size
            title: { default: null },
            size: sizeAttribute,
        };
    },

    parseHTML() {
        return [{ tag: 'video[src]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return [
            'video',
            mergeAttributes(HTMLAttributes, {
                controls: 'true',
                preload: 'metadata',
            }),
        ];
    },

    addNodeView() {
        return VueNodeViewRenderer(MediaView);
    },
});
