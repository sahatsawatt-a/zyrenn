import Image from '@tiptap/extension-image';
import { VueNodeViewRenderer } from '@tiptap/vue-3';
import ImageView from '../components/Editor/ImageView.vue';

export type ImageSize = 'small' | 'medium' | 'full';

// Block image with a Vue view: expand to the viewer, and small / medium / full width presets
export const ImageNode = Image.extend({
    draggable: true,

    addAttributes() {
        return {
            ...this.parent?.(),
            size: {
                default: 'full' as ImageSize,
                parseHTML: (element: HTMLElement) =>
                    element.getAttribute('data-size') ?? 'full',
                renderHTML: (attributes: Record<string, any>) => ({
                    'data-size': attributes.size,
                }),
            },
        };
    },

    addNodeView() {
        return VueNodeViewRenderer(ImageView);
    },
}).configure({
    inline: false,
    // Images are uploaded to the Drive first, so only URLs (never base64 blobs) land in the document
    allowBase64: false,
});
