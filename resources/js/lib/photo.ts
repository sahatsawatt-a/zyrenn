// The photo editor's arithmetic: what an edit is, how the crop box moves and
// turns with the picture, and how the edited picture is drawn. An edit is
// applied in one order -- turn, then flip, then crop, then light and colour --
// and the crop is in fractions of the turned picture, so it holds at any size.

export interface Rect {
    x: number;
    y: number;
    width: number;
    height: number;
}

export type Turn = 0 | 90 | 180 | 270;

export interface PhotoEdit {
    crop: Rect;
    /** Clockwise, in degrees. */
    rotate: Turn;
    /** Mirrored left to right, and top to bottom, after turning. */
    flipX: boolean;
    flipY: boolean;
    /** Percentages, 100 leaving the picture as it is. */
    brightness: number;
    contrast: number;
    saturation: number;
    /** Cooler below 0, warmer above, from -100 to 100. */
    warmth: number;
    /** How dark the corners are drawn, from 0 to 100. */
    vignette: number;
}

/** The parts of an edit that are light and colour rather than shape. */
export type LightKey =
    | 'brightness'
    | 'contrast'
    | 'saturation'
    | 'warmth'
    | 'vignette';

export type Light = Pick<PhotoEdit, LightKey>;

export interface Size {
    width: number;
    height: number;
}

export const WHOLE: Rect = { x: 0, y: 0, width: 1, height: 1 };

export const UNEDITED: PhotoEdit = {
    crop: WHOLE,
    rotate: 0,
    flipX: false,
    flipY: false,
    brightness: 100,
    contrast: 100,
    saturation: 100,
    warmth: 0,
    vignette: 0,
};

/** An edit as kept, filled out to the whole of an edit today. */
export const fullEdit = (edit: Partial<PhotoEdit> | null | undefined) => ({
    ...UNEDITED,
    ...edit,
    crop: { ...WHOLE, ...edit?.crop },
});

/** Looks to start from: light and colour only, the crop left alone. */
export const LOOKS: { id: string; label: string; light: Partial<Light> }[] = [
    { id: 'none', label: 'Original', light: {} },
    { id: 'mono', label: 'B&W', light: { saturation: 0, contrast: 115 } },
    { id: 'vivid', label: 'Vivid', light: { saturation: 145, contrast: 112 } },
    { id: 'warm', label: 'Warm', light: { warmth: 35, saturation: 110 } },
    { id: 'cool', label: 'Cool', light: { warmth: -35, brightness: 104 } },
    {
        id: 'fade',
        label: 'Fade',
        light: { contrast: 80, brightness: 108, saturation: 75 },
    },
    {
        id: 'drama',
        label: 'Drama',
        light: { contrast: 130, saturation: 90, vignette: 45 },
    },
];

/** A look's whole light, everything it doesn't name left as it was taken. */
export const lookLight = (look: Partial<Light>): Light => ({
    brightness: UNEDITED.brightness,
    contrast: UNEDITED.contrast,
    saturation: UNEDITED.saturation,
    warmth: UNEDITED.warmth,
    vignette: UNEDITED.vignette,
    ...look,
});

/** The edit changes nothing at all. */
export const isUnedited = (edit: PhotoEdit) =>
    (Object.keys(UNEDITED) as (keyof PhotoEdit)[]).every((key) =>
        key === 'crop'
            ? (['x', 'y', 'width', 'height'] as const).every(
                  (side) => Math.abs(edit.crop[side] - WHOLE[side]) < 1e-6,
              )
            : edit[key] === UNEDITED[key],
    );

/** The picture's size once turned: a quarter turn swaps its sides. */
export const turnedSize = (size: Size, rotate: Turn): Size =>
    rotate % 180 ? { width: size.height, height: size.width } : size;

const clamp = (value: number, least: number, most: number) =>
    Math.min(most, Math.max(least, value));

/**
 * A quarter turn of the whole picture, the crop going round with it. A flip
 * turned a quarter is the other flip, so the two swap.
 */
export const turn = (edit: PhotoEdit, clockwise: boolean): PhotoEdit => {
    const { x, y, width, height } = edit.crop;

    return {
        ...edit,
        rotate: ((edit.rotate + (clockwise ? 90 : 270)) % 360) as Turn,
        flipX: edit.flipY,
        flipY: edit.flipX,
        crop: clockwise
            ? { x: 1 - (y + height), y: x, width: height, height: width }
            : { x: y, y: 1 - (x + width), width: height, height: width },
    };
};

/** A mirror of the picture as it is seen, the crop mirrored with it. */
export const flip = (edit: PhotoEdit, across: 'x' | 'y'): PhotoEdit => {
    const { crop } = edit;

    return across === 'x'
        ? {
              ...edit,
              flipX: !edit.flipX,
              crop: { ...crop, x: 1 - (crop.x + crop.width) },
          }
        : {
              ...edit,
              flipY: !edit.flipY,
              crop: { ...crop, y: 1 - (crop.y + crop.height) },
          };
};

/**
 * A width-to-height ratio in pixels, as a ratio of the crop's fractions --
 * which differ unless the picture is square.
 */
export const fractionRatio = (ratio: number, turned: Size) =>
    (ratio * turned.height) / turned.width;

/** The largest crop of a ratio (in fractions) there is room for, centred. */
export const largestCrop = (ratio: number): Rect => {
    const width = Math.min(1, ratio);
    const height = width / ratio;

    return { x: (1 - width) / 2, y: (1 - height) / 2, width, height };
};

export type Handle = 'move' | 'n' | 's' | 'e' | 'w' | 'ne' | 'nw' | 'se' | 'sw';

export const HANDLES: Exclude<Handle, 'move'>[] = [
    'nw',
    'n',
    'ne',
    'e',
    'se',
    's',
    'sw',
    'w',
];

/** The smallest a crop can be made, as a fraction of a side. */
const LEAST = 0.02;

/**
 * The crop box, dragged by its middle or a handle by dx and dy (fractions of
 * the picture), kept on the picture -- and, given a ratio (in fractions), to
 * that shape, growing from the corner or side opposite the handle.
 */
export const dragCrop = (
    start: Rect,
    handle: Handle,
    dx: number,
    dy: number,
    ratio: number | null = null,
): Rect => {
    if (handle === 'move') {
        return {
            ...start,
            x: clamp(start.x + dx, 0, 1 - start.width),
            y: clamp(start.y + dy, 0, 1 - start.height),
        };
    }

    const west = handle.includes('w');
    const east = handle.includes('e');
    const north = handle.includes('n');
    const south = handle.includes('s');

    let left = start.x;
    let right = start.x + start.width;
    let top = start.y;
    let bottom = start.y + start.height;

    if (west) left = clamp(left + dx, 0, right - LEAST);
    if (east) right = clamp(right + dx, left + LEAST, 1);
    if (north) top = clamp(top + dy, 0, bottom - LEAST);
    if (south) bottom = clamp(bottom + dy, top + LEAST, 1);

    if (!ratio) {
        return { x: left, y: top, width: right - left, height: bottom - top };
    }

    let width = right - left;
    let height = bottom - top;
    const across = west || east;
    const down = north || south;

    if (across && !down) {
        height = width / ratio;
    } else if (down && !across) {
        width = height * ratio;
    } else if (width / ratio > height) {
        height = width / ratio;
    } else {
        width = height * ratio;
    }

    // Grown from the opposite corner, or the opposite side's middle
    const anchorX = west
        ? start.x + start.width
        : east
          ? start.x
          : start.x + start.width / 2;
    const anchorY = north
        ? start.y + start.height
        : south
          ? start.y
          : start.y + start.height / 2;
    const roomX = west
        ? anchorX
        : east
          ? 1 - anchorX
          : 2 * Math.min(anchorX, 1 - anchorX);
    const roomY = north
        ? anchorY
        : south
          ? 1 - anchorY
          : 2 * Math.min(anchorY, 1 - anchorY);
    const fit = Math.min(1, roomX / width, roomY / height);
    width *= fit;
    height *= fit;

    return {
        x: west ? anchorX - width : east ? anchorX : anchorX - width / 2,
        y: north ? anchorY - height : south ? anchorY : anchorY - height / 2,
        width,
        height,
    };
};

/**
 * Light and colour put into the pixels: brightness, then contrast, then
 * saturation (as CSS filters work them), then warmth, then the vignette --
 * darkening towards the corners of `frame`, the part of the pixels that is
 * the picture as cropped (all of them, unless said).
 */
export const adjustPixels = <
    P extends { data: Uint8ClampedArray; width: number; height: number },
>(
    pixels: P,
    light: Light,
    frame: Rect = { x: 0, y: 0, width: pixels.width, height: pixels.height },
) => {
    const { data, width } = pixels;
    const b = light.brightness / 100;
    const c = light.contrast / 100;
    const s = light.saturation / 100;
    const warm = light.warmth * 0.35;
    const vignette = light.vignette / 100;

    if (b === 1 && c === 1 && s === 1 && !warm && !vignette) {
        return pixels;
    }

    // One table for brightness and contrast, which treat each channel alike
    const tone = new Uint8ClampedArray(256);

    for (let value = 0; value < 256; value++) {
        const bright = Math.min(255, value * b);
        tone[value] = (bright - 127.5) * c + 127.5;
    }

    const middleX = frame.x + frame.width / 2;
    const middleY = frame.y + frame.height / 2;

    for (let i = 0; i < data.length; i += 4) {
        let r = tone[data[i]];
        let g = tone[data[i + 1]];
        let bl = tone[data[i + 2]];

        if (s !== 1) {
            [r, g, bl] = [
                (0.213 + 0.787 * s) * r +
                    (0.715 - 0.715 * s) * g +
                    (0.072 - 0.072 * s) * bl,
                (0.213 - 0.213 * s) * r +
                    (0.715 + 0.285 * s) * g +
                    (0.072 - 0.072 * s) * bl,
                (0.213 - 0.213 * s) * r +
                    (0.715 - 0.715 * s) * g +
                    (0.072 + 0.928 * s) * bl,
            ];
        }

        r += warm;
        bl -= warm;

        if (vignette) {
            // 0 in the middle, 1 in the corners, darkening from halfway out
            const pixel = i / 4;
            const across =
                ((pixel % width) + 0.5 - middleX) / (frame.width / 2);
            const down =
                (Math.floor(pixel / width) + 0.5 - middleY) /
                (frame.height / 2);
            const out = Math.min(1, Math.hypot(across, down) / Math.SQRT2);
            const fall = Math.max(0, (out - 0.35) / 0.65);
            const keep = 1 - vignette * 0.8 * fall * fall * (3 - 2 * fall);
            r *= keep;
            g *= keep;
            bl *= keep;
        }

        data[i] = r;
        data[i + 1] = g;
        data[i + 2] = bl;
    }

    return pixels;
};

/** The size the edited picture comes out at, in pixels. */
export const outputSize = (size: Size, edit: PhotoEdit, scale = 1): Size => {
    const turned = turnedSize(size, edit.rotate);

    return {
        width: Math.max(1, Math.round(edit.crop.width * turned.width * scale)),
        height: Math.max(
            1,
            Math.round(edit.crop.height * turned.height * scale),
        ),
    };
};

/**
 * The picture turned, flipped and cropped onto a canvas, at `scale` of its
 * own pixels. `whole` leaves the crop out, for showing the crop box over it.
 */
export const drawPhoto = (
    image: CanvasImageSource,
    size: Size,
    edit: PhotoEdit,
    { scale = 1, whole = false }: { scale?: number; whole?: boolean } = {},
): HTMLCanvasElement => {
    const turned = turnedSize(size, edit.rotate);
    const crop = whole ? WHOLE : edit.crop;
    const out = outputSize(size, { ...edit, crop }, scale);
    const canvas = document.createElement('canvas');
    canvas.width = out.width;
    canvas.height = out.height;

    const context = canvas.getContext('2d')!;
    context.imageSmoothingQuality = 'high';
    context.scale(scale, scale);
    // Last first: the image is turned, then flipped, then cut to the crop
    context.translate(-crop.x * turned.width, -crop.y * turned.height);

    if (edit.flipX) {
        context.translate(turned.width, 0);
        context.scale(-1, 1);
    }

    if (edit.flipY) {
        context.translate(0, turned.height);
        context.scale(1, -1);
    }

    context.translate(turned.width / 2, turned.height / 2);
    context.rotate((edit.rotate * Math.PI) / 180);
    context.drawImage(
        image,
        -size.width / 2,
        -size.height / 2,
        size.width,
        size.height,
    );

    return canvas;
};

/** Browsers on phones refuse canvases much past this many pixels. */
const MOST_PIXELS = 16_777_216;

/** What an edited picture is saved as: PNG keeps a see-through background. */
export const outputType = (mime: string | null | undefined) =>
    mime === 'image/jpeg'
        ? 'image/jpeg'
        : mime === 'image/webp'
          ? 'image/webp'
          : 'image/png';

/**
 * The edited picture, ready to keep. A drawing (SVG) has no pixels of its
 * own, so it is drawn large enough to stay sharp.
 */
export const renderPhoto = async (
    image: CanvasImageSource,
    size: Size,
    edit: PhotoEdit,
    mime: string | null | undefined,
): Promise<{ blob: Blob; width: number; height: number }> => {
    const full = outputSize(size, edit);
    const drawing = mime === 'image/svg+xml';
    const scale = Math.min(
        drawing ? Math.max(1, 2000 / Math.max(full.width, full.height)) : 1,
        Math.sqrt(MOST_PIXELS / (full.width * full.height)),
    );

    const canvas = drawPhoto(image, size, edit, { scale });
    const context = canvas.getContext('2d')!;
    const pixels = context.getImageData(0, 0, canvas.width, canvas.height);
    adjustPixels(pixels, edit);
    context.putImageData(pixels, 0, 0);

    const type = outputType(mime);
    const blob = await new Promise<Blob | null>((resolve) =>
        canvas.toBlob(resolve, type, 0.92),
    );

    if (!blob) {
        throw new Error('The picture couldn’t be made.');
    }

    return { blob, width: canvas.width, height: canvas.height };
};
