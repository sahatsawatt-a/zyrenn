import { useEventListener } from '@vueuse/core';
import type { Ref } from 'vue';
import { ref } from 'vue';
import { isImageFile, uploadToDrive } from '@/lib/drive';
import type { PickedImage } from '@/components/media/ImagePickerDialog.vue';
import { fitOnBoard } from './geometry';
import type { Item } from './items';
import { svgSource } from './pictures';

type Placer = {
    board: {
        makeItem: (kind: 'image', x: number, y: number) => Item;
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
 */
export function usePictures({ board, middleOfView, editingId }: Placer) {
    const importing = ref(false);

    // Each picture is decoded once and kept by its URL. The map is replaced
    // rather than mutated, so the canvas redraws when one finishes loading.
    const decoded = ref(new Map<string, HTMLImageElement>());
    const loading = new Set<string>();

    const imageFor = (item: Item): HTMLImageElement | undefined => {
        if (!item.src) {
            return undefined;
        }

        const ready = decoded.value.get(item.src);

        if (ready || loading.has(item.src)) {
            return ready;
        }

        loading.add(item.src);

        const image = new window.Image();

        image.onload = () => {
            decoded.value = new Map(decoded.value).set(item.src, image);
            loading.delete(item.src);
        };
        image.onerror = () => loading.delete(item.src);
        image.src = item.src;

        return undefined;
    };

    /** Puts a picture in the middle of what is on screen. */
    const placeImage = (source: {
        src: string;
        width: number;
        height: number;
    }) => {
        const middle = middleOfView();
        const item = board.makeItem(
            'image',
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

    /** How big a picture should land, from its own proportions. */
    const sizeOf = (src: string) =>
        new Promise<{ width: number; height: number }>((resolve) => {
            const probe = new window.Image();

            probe.onload = () =>
                resolve(fitOnBoard(probe.naturalWidth, probe.naturalHeight));
            probe.onerror = () => resolve({ width: 240, height: 240 });
            probe.src = src;
        });

    /**
     * Pictures chosen in the dialog -- uploaded from this machine, taken from
     * the Drive, or linked. They are all just a URL by the time they get here.
     */
    const onImagesPicked = async (images: PickedImage[]) => {
        for (const image of images) {
            placeImage({ src: image.src, ...(await sizeOf(image.src)) });
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

    return {
        importing,
        imageFor,
        addSvg,
        onImagesPicked,
        onDropFiles,
    };
}
