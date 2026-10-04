import { useEventListener } from '@vueuse/core';
import type { Ref } from 'vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { isImageFile, isVideoFile, uploadToDrive } from '@/lib/drive';
import { mermaidImage } from '@/lib/mermaid';
import type { PickedMedia } from '@/components/media/MediaPickerDialog.vue';
import { fitOnBoard } from './geometry';
import type { Item } from './items';
import { svgSource } from './pictures';
import { useImageCache } from './useImageCache';

type Placer = {
    board: {
        makeItem: (kind: 'image' | 'video', x: number, y: number) => Item;
        add: (item: Item) => void;
    };
    /** The middle of what is on screen, in board coordinates. */
    middleOfView: () => { x: number; y: number };
    /** Whatever is being typed into, so a paste goes there instead. */
    editingId: Ref<string | null>;
};

/**
 * Pictures on a board: decoding them for the canvas, and every way one can
 * arrive -- chosen in the dialog, pasted, dropped, or written as SVG markup.
 * Videos arrive the same ways (but markup), and play where they land.
 */
export function usePictures({ board, middleOfView, editingId }: Placer) {
    const importing = ref(false);

    const { imageFor } = useImageCache();

    // Which the dialog is choosing; the board's own button says which
    const importKind = ref<'image' | 'video'>('image');

    /** Puts a picture (or a video) in the middle of what is on screen. */
    const placeImage = (
        source: {
            src: string;
            width: number;
            height: number;
        },
        kind: 'image' | 'video' = 'image',
    ) => {
        const middle = middleOfView();
        const item = board.makeItem(
            kind,
            middle.x - source.width / 2,
            middle.y - source.height / 2,
        );

        item.src = source.src;
        item.width = source.width;
        item.height = source.height;

        board.add(item);
        importing.value = false;
    };

    const addSvg = (markup: string) => placeImage(svgSource(markup));

    /**
     * A Mermaid diagram, put on as a picture: the same light-on-white drawing a
     * note saves, its labels plain SVG text so the canvas can draw them.
     */
    const addMermaid = async (source: string) => {
        try {
            addSvg(await (await mermaidImage(source, 'svg')).text());
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Mermaid could not draw that.',
            );
        }
    };

    /** How big a picture should land, from its own proportions. */
    const sizeOf = (src: string) =>
        new Promise<{ width: number; height: number }>((resolve) => {
            const probe = new window.Image();

            probe.onload = () =>
                resolve(fitOnBoard(probe.naturalWidth, probe.naturalHeight));
            probe.onerror = () => resolve({ width: 240, height: 240 });
            probe.src = src;
        });

    /** How big a video should land: its own shape, as wide as a picture. */
    const videoSizeOf = (src: string) =>
        new Promise<{ width: number; height: number }>((resolve) => {
            const probe = document.createElement('video');

            probe.preload = 'metadata';
            probe.onloadedmetadata = () =>
                resolve(fitOnBoard(probe.videoWidth, probe.videoHeight));
            // 16:9, until it can say otherwise
            probe.onerror = () => resolve({ width: 480, height: 270 });
            probe.src = src;
        });

    const placeVideo = async (src: string) =>
        placeImage({ src, ...(await videoSizeOf(src)) }, 'video');

    /**
     * Pictures or videos chosen in the dialog -- uploaded from this machine,
     * taken from the Drive, or linked. They are all just a URL by the time
     * they get here.
     */
    const onMediaPicked = async (picked: PickedMedia[]) => {
        for (const media of picked) {
            if (media.kind === 'video') {
                await placeVideo(media.src);
            } else {
                placeImage({ src: media.src, ...(await sizeOf(media.src)) });
            }
        }
    };

    /**
     * A video file dropped or pasted onto the board. Unlike a picture it is
     * never kept in the board itself -- far too big -- so it goes to the Drive
     * or not at all.
     */
    const addVideoFile = async (file: File) => {
        const loading = toast.loading(`Uploading ${file.name}…`);

        try {
            const stored = await uploadToDrive(file, (fraction) =>
                toast.loading(
                    `Uploading ${file.name}… ${Math.round(fraction * 100)}%`,
                    { id: loading },
                ),
            );
            await placeVideo(stored.url);
        } catch (error) {
            toast.error((error as Error).message);
        } finally {
            toast.dismiss(loading);
        }
    };

    /**
     * A file dropped or pasted onto the board. SVG is drawn from its markup;
     * anything else goes to the Drive and the board keeps the link, the way a
     * note does -- a screenshot carried inside the board would be sent again
     * on every save. If the upload cannot be made, the pixels are kept instead
     * so the picture is not simply lost.
     */
    const addImageFile = async (file: File) => {
        if (isVideoFile(file)) {
            await addVideoFile(file);

            return;
        }

        if (file.type === 'image/svg+xml' || file.name.endsWith('.svg')) {
            addSvg(await file.text());

            return;
        }

        if (!isImageFile(file)) {
            return;
        }

        let src: string;

        try {
            src = (await uploadToDrive(file)).url;
        } catch {
            src = await new Promise<string>((resolve, reject) => {
                const reader = new FileReader();

                // readAsDataURL always gives back a string
                reader.onload = () =>
                    resolve(
                        typeof reader.result === 'string' ? reader.result : '',
                    );
                reader.onerror = () => reject(reader.error);
                reader.readAsDataURL(file);
            });
        }

        placeImage({ src, ...(await sizeOf(src)) });
    };

    // Paste a screenshot straight onto the board, as any board app does. Markup
    // on the clipboard counts too, so an SVG copied from a page can be pasted.
    useEventListener(window, 'paste', (event: ClipboardEvent) => {
        if (editingId.value || importing.value) {
            return;
        }

        const file = Array.from(event.clipboardData?.files ?? [])[0];

        if (file) {
            event.preventDefault();
            void addImageFile(file);

            return;
        }

        const text = event.clipboardData?.getData('text/plain') ?? '';

        if (/<svg[\s>]/i.test(text)) {
            event.preventDefault();
            addSvg(text);
        }
    });

    const onDropFiles = (event: DragEvent) => {
        const files = Array.from(event.dataTransfer?.files ?? []);

        if (files.length) {
            event.preventDefault();
            files.forEach((file) => void addImageFile(file));
        }
    };

    /** Opens the dialog, for a picture or for a video. */
    const startImport = (kind: 'image' | 'video') => {
        importKind.value = kind;
        importing.value = true;
    };

    return {
        importing,
        importKind,
        startImport,
        imageFor,
        addSvg,
        addMermaid,
        onMediaPicked,
        onDropFiles,
    };
}
