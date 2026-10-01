import Image from '@tiptap/extension-image';
import { VueNodeViewRenderer } from '@tiptap/vue-3';
import MediaView from '../components/Editor/MediaView.vue';

export type ImageSize = 'small' | 'medium' | 'full';

// How wide a picture or a video sits in the note (shared with VideoNode)
export const sizeAttribute = {
    default: 'full' as ImageSize,
    parseHTML: (element: HTMLElement) =>
        element.getAttribute('data-size') ?? 'full',
    renderHTML: (attributes: Record<string, any>) => ({
        'data-size': attributes.size,
    }),
};

// Block image with a Vue view: expand to the viewer, and small / medium / full width presets
export const ImageNode = Image.extend({
    draggable: true,

    addAttributes() {
        return {
            ...this.parent?.(),
            size: sizeAttribute,
        };
    },

    addNodeView() {
        return VueNodeViewRenderer(MediaView);
    },
}).configure({
    inline: false,
    // Images are uploaded to the Drive first, so only URLs (never base64 blobs) land in the document
    allowBase64: false,
});
