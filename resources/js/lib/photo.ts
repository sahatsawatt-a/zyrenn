// The photo editor's arithmetic: what an edit is, how the crop box moves and
// turns with the picture, and how the edited picture is drawn. An edit is
// applied in one order -- turn, then flip, then straighten, then the areas
// hidden, then crop, then light and colour, then the shape cut out -- and the
// crop and the hidden areas are in fractions of the turned picture, so they
// hold at any size.

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
    /** Areas blurred, pixelated or blacked out -- a face, a password. */
    hidden: Hidden[];
    /**
     * Degrees to straighten by, -45 to 45, clockwise -- the picture growing
     * just enough that no corner is left empty.
     */
    angle: number;
    /** The shape cut out of the crop; anything but a rectangle is see-through round it. */
    shape: Shape;
    /** The longest side to save at, in pixels, never larger; 0 for as it is. */
    maxSide: number;
}

export type Shape = 'rect' | 'rounded' | 'circle';

/** The sizes offered to save at, by their longest side. */
export const SAVE_SIZES: { value: number; label: string }[] = [
    { value: 0, label: 'Full size' },
    { value: 2560, label: 'Large' },
    { value: 1600, label: 'Medium' },
    { value: 1024, label: 'Small' },
];

export type HideStyle = 'blur' | 'pixelate' | 'fill';

export interface Hidden extends Rect {
    style: HideStyle;
    /** Only what is inside the shape is hidden: an oval for a face, say. */
    shape: Shape;
}

/** A rounded hidden area's corners, as a share of its shorter side. */
export const HIDDEN_ROUNDING = 0.25;

/**
 * Whether the middle of a pixel is inside a shape drawn in a box -- an oval
 * for a circle, corners cut round for rounded.
 */
export const inShape = (
    shape: Shape,
    box: Rect,
    x: number,
    y: number,
    rounding = HIDDEN_ROUNDING,
) => {
    const across = x + 0.5 - (box.x + box.width / 2);
    const down = y + 0.5 - (box.y + box.height / 2);

    if (shape === 'circle') {
        return (
            (across / (box.width / 2)) ** 2 + (down / (box.height / 2)) ** 2 <=
            1
        );
    }

    if (shape === 'rounded') {
        const radius = Math.min(box.width, box.height) * rounding;
        const pastX = Math.abs(across) - (box.width / 2 - radius);
        const pastY = Math.abs(down) - (box.height / 2 - radius);

        return (
            pastX <= 0 || pastY <= 0 || pastX ** 2 + pastY ** 2 <= radius ** 2
        );
    }

    return true;
};

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
    hidden: [],
    angle: 0,
    shape: 'rect',
    maxSide: 0,
};

/** An edit as kept, filled out to the whole of an edit today. */
export const fullEdit = (
    edit: Partial<PhotoEdit> | null | undefined,
): PhotoEdit => ({
    ...UNEDITED,
    ...edit,
    crop: { ...WHOLE, ...edit?.crop },
    hidden: (edit?.hidden ?? []).map((area) => ({
        ...area,
        shape: area.shape ?? 'rect',
    })),
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
            : key === 'hidden'
              ? edit.hidden.length === 0
              : edit[key] === UNEDITED[key],
    );

/** The picture's size once turned: a quarter turn swaps its sides. */
export const turnedSize = (size: Size, rotate: Turn): Size =>
    rotate % 180 ? { width: size.height, height: size.width } : size;

const clamp = (value: number, least: number, most: number) =>
    Math.min(most, Math.max(least, value));

/** A box on the picture, where it is once the picture turns a quarter. */
const turnRect = <R extends Rect>(rect: R, clockwise: boolean): R => {
    const { x, y, width, height } = rect;

    return clockwise
        ? { ...rect, x: 1 - (y + height), y: x, width: height, height: width }
        : { ...rect, x: y, y: 1 - (x + width), width: height, height: width };
};

/** A box on the picture, where it is once the picture is mirrored. */
const flipRect = <R extends Rect>(rect: R, across: 'x' | 'y'): R =>
    across === 'x'
        ? { ...rect, x: 1 - (rect.x + rect.width) }
        : { ...rect, y: 1 - (rect.y + rect.height) };

/**
 * A quarter turn of the whole picture, the crop and the hidden areas going
 * round with it. A flip turned a quarter is the other flip, so the two swap.
 */
export const turn = (edit: PhotoEdit, clockwise: boolean): PhotoEdit => ({
    ...edit,
    rotate: ((edit.rotate + (clockwise ? 90 : 270)) % 360) as Turn,
    flipX: edit.flipY,
    flipY: edit.flipX,
    crop: turnRect(edit.crop, clockwise),
    hidden: edit.hidden.map((area) => turnRect(area, clockwise)),
});

/** A mirror of the picture as it is seen, the boxes on it mirrored with it. */
export const flip = (edit: PhotoEdit, across: 'x' | 'y'): PhotoEdit => ({
    ...edit,
    ...(across === 'x' ? { flipX: !edit.flipX } : { flipY: !edit.flipY }),
    // A tilt seen in a mirror leans the other way
    angle: edit.angle ? -edit.angle : 0,
    crop: flipRect(edit.crop, across),
    hidden: edit.hidden.map((area) => flipRect(area, across)),
});

/** A new area to hide: a box in the middle of what the crop keeps. */
export const newHidden = (crop: Rect, style: HideStyle): Hidden => ({
    style,
    shape: 'rect',
    x: crop.x + crop.width * 0.35,
    y: crop.y + crop.height * 0.4,
    width: crop.width * 0.3,
    height: crop.height * 0.2,
});

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
 * Where the whole turned picture lies in pixels drawn of it: all of them for
 * the whole picture, or reaching past them when they are of the crop.
 */
export const pictureFrame = (
    size: Size,
    edit: PhotoEdit,
    scale: number,
    whole = false,
): Rect => {
    const turned = turnedSize(size, edit.rotate);
    const crop = whole ? WHOLE : edit.crop;

    return {
        x: -crop.x * turned.width * scale,
        y: -crop.y * turned.height * scale,
        width: turned.width * scale,
        height: turned.height * scale,
    };
};

/** The darkness a black box is filled with. */
const INK = 24;

/**
 * The hidden areas put into the pixels. How coarse the pixels and how wide
 * the blur go by the picture's own size (a 40th of its shorter side), not
 * the area's, so the preview and the saved picture look alike -- and only
 * what is inside an area is used, so nothing of it leaks back from around it.
 */
export const hidePixels = <
    P extends { data: Uint8ClampedArray; width: number; height: number },
>(
    pixels: P,
    hidden: Hidden[],
    frame: Rect = { x: 0, y: 0, width: pixels.width, height: pixels.height },
) => {
    const { data, width, height } = pixels;
    const block = Math.max(
        3,
        Math.round(Math.min(frame.width, frame.height) / 40),
    );

    for (const area of hidden) {
        const left = Math.max(0, Math.floor(frame.x + area.x * frame.width));
        const top = Math.max(0, Math.floor(frame.y + area.y * frame.height));
        const right = Math.min(
            width,
            Math.ceil(frame.x + (area.x + area.width) * frame.width),
        );
        const bottom = Math.min(
            height,
            Math.ceil(frame.y + (area.y + area.height) * frame.height),
        );

        if (right <= left || bottom <= top) {
            continue;
        }

        // A shape keeps what is outside it: kept now, put back after
        const box = {
            x: frame.x + area.x * frame.width,
            y: frame.y + area.y * frame.height,
            width: area.width * frame.width,
            height: area.height * frame.height,
        };
        const kept =
            area.shape === 'rect'
                ? null
                : Array.from({ length: bottom - top }, (_, row) =>
                      data.slice(
                          ((top + row) * width + left) * 4,
                          ((top + row) * width + right) * 4,
                      ),
                  );

        if (area.style === 'fill') {
            for (let y = top; y < bottom; y++) {
                for (let x = left; x < right; x++) {
                    const i = (y * width + x) * 4;
                    data[i] = data[i + 1] = data[i + 2] = INK;
                    data[i + 3] = 255;
                }
            }
        } else if (area.style === 'pixelate') {
            pixelate(data, width, left, top, right, bottom, block);
        } else {
            blur(data, width, left, top, right, bottom, block);
        }

        if (kept) {
            for (let y = top; y < bottom; y++) {
                for (let x = left; x < right; x++) {
                    if (!inShape(area.shape, box, x, y)) {
                        const from = (x - left) * 4;
                        data.set(
                            kept[y - top].subarray(from, from + 4),
                            (y * width + x) * 4,
                        );
                    }
                }
            }
        }
    }

    return pixels;
};

/** Each square of the area, from its top-left corner, made its average. */
const pixelate = (
    data: Uint8ClampedArray,
    width: number,
    left: number,
    top: number,
    right: number,
    bottom: number,
    block: number,
) => {
    for (let y0 = top; y0 < bottom; y0 += block) {
        for (let x0 = left; x0 < right; x0 += block) {
            const y1 = Math.min(bottom, y0 + block);
            const x1 = Math.min(right, x0 + block);
            const sum = [0, 0, 0, 0];

            for (let y = y0; y < y1; y++) {
                for (let x = x0; x < x1; x++) {
                    const i = (y * width + x) * 4;
                    sum[0] += data[i];
                    sum[1] += data[i + 1];
                    sum[2] += data[i + 2];
                    sum[3] += data[i + 3];
                }
            }

            const count = (y1 - y0) * (x1 - x0);

            for (let y = y0; y < y1; y++) {
                for (let x = x0; x < x1; x++) {
                    const i = (y * width + x) * 4;
                    data[i] = sum[0] / count;
                    data[i + 1] = sum[1] / count;
                    data[i + 2] = sum[2] / count;
                    data[i + 3] = sum[3] / count;
                }
            }
        }
    }
};

/**
 * A box blur, three times over each way -- near enough a Gaussian -- kept to
 * the area: at its edges it reaches only as far as the area does.
 */
const blur = (
    data: Uint8ClampedArray,
    width: number,
    left: number,
    top: number,
    right: number,
    bottom: number,
    radius: number,
) => {
    const across = right - left;
    const down = bottom - top;
    const area = new Float32Array(across * down * 4);

    for (let y = 0; y < down; y++) {
        for (let x = 0; x < across; x++) {
            const from = ((top + y) * width + left + x) * 4;
            area.set(data.subarray(from, from + 4), (y * across + x) * 4);
        }
    }

    // One run along a line of `length` pixels, `step` apart in `area`
    const line = new Float32Array(Math.max(across, down) * 4);
    const pass = (start: number, step: number, length: number) => {
        for (let k = 0; k < length; k++) {
            line.set(
                area.subarray(start + k * step, start + k * step + 4),
                k * 4,
            );
        }

        for (let channel = 0; channel < 4; channel++) {
            let sum = 0;
            let count = 0;

            for (let k = 0; k < Math.min(radius, length); k++) {
                sum += line[k * 4 + channel];
                count++;
            }

            for (let k = 0; k < length; k++) {
                const enter = k + radius;
                const leave = k - radius - 1;

                if (enter < length) {
                    sum += line[enter * 4 + channel];
                    count++;
                }

                if (leave >= 0) {
                    sum -= line[leave * 4 + channel];
                    count--;
                }

                area[start + k * step + channel] = sum / count;
            }
        }
    };

    for (let round = 0; round < 3; round++) {
        for (let y = 0; y < down; y++) {
            pass(y * across * 4, 4, across);
        }

        for (let x = 0; x < across; x++) {
            pass(x * 4, across * 4, down);
        }
    }

    for (let y = 0; y < down; y++) {
        for (let x = 0; x < across; x++) {
            const to = ((top + y) * width + left + x) * 4;
            const from = (y * across + x) * 4;
            data.set(area.subarray(from, from + 4), to);
        }
    }
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
/**
 * How much larger a picture straightened by `angle` degrees has to be drawn
 * for it to still fill its own frame, with no empty corner.
 */
export const straightenScale = (size: Size, angle: number) => {
    const turnBy = (Math.abs(angle) * Math.PI) / 180;
    const cos = Math.cos(turnBy);
    const sin = Math.sin(turnBy);

    return Math.max(
        cos + (size.height / size.width) * sin,
        cos + (size.width / size.height) * sin,
    );
};

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
    // Last first: the image is turned, then flipped, then straightened, then
    // cut to the crop
    context.translate(-crop.x * turned.width, -crop.y * turned.height);

    if (edit.angle) {
        const grow = straightenScale(turned, edit.angle);
        context.translate(turned.width / 2, turned.height / 2);
        context.rotate((edit.angle * Math.PI) / 180);
        context.scale(grow, grow);
        context.translate(-turned.width / 2, -turned.height / 2);
    }

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
export const outputType = (
    mime: string | null | undefined,
    shape: Shape = 'rect',
) =>
    mime === 'image/webp'
        ? 'image/webp'
        : mime === 'image/jpeg' && shape === 'rect'
          ? 'image/jpeg'
          : 'image/png';

/** A rounded crop's corners, as a share of its shorter side. */
export const ROUNDING = 0.08;

/** Everything outside the shape made see-through. */
const cutShape = (canvas: HTMLCanvasElement, shape: Shape) => {
    if (shape === 'rect') {
        return;
    }

    const context = canvas.getContext('2d')!;
    const { width, height } = canvas;
    context.save();
    context.setTransform(1, 0, 0, 1, 0, 0);
    context.globalCompositeOperation = 'destination-in';
    context.beginPath();

    if (shape === 'circle') {
        context.ellipse(
            width / 2,
            height / 2,
            width / 2,
            height / 2,
            0,
            0,
            Math.PI * 2,
        );
    } else {
        context.roundRect(
            0,
            0,
            width,
            height,
            Math.min(width, height) * ROUNDING,
        );
    }

    context.fill();
    context.restore();
};

/**
 * How large the edited picture is saved, against its own pixels: smaller
 * when a size to save at is chosen (never larger), or past what a phone's
 * browser will draw; a drawing (SVG) has no pixels of its own, so it is drawn
 * large enough to stay sharp.
 */
export const saveScale = (
    size: Size,
    edit: PhotoEdit,
    mime: string | null | undefined,
) => {
    const full = outputSize(size, edit);
    const longest = Math.max(full.width, full.height);

    return Math.min(
        mime === 'image/svg+xml' ? Math.max(1, 2000 / longest) : 1,
        Math.sqrt(MOST_PIXELS / (full.width * full.height)),
        edit.maxSide ? Math.min(1, edit.maxSide / longest) : Infinity,
    );
};

/** The edited picture, ready to keep. */
export const renderPhoto = async (
    image: CanvasImageSource,
    size: Size,
    edit: PhotoEdit,
    mime: string | null | undefined,
): Promise<{ blob: Blob; width: number; height: number }> => {
    const scale = saveScale(size, edit, mime);
    const canvas = drawPhoto(image, size, edit, { scale });
    const context = canvas.getContext('2d')!;
    const pixels = context.getImageData(0, 0, canvas.width, canvas.height);
    hidePixels(pixels, edit.hidden, pictureFrame(size, edit, scale));
    adjustPixels(pixels, edit);
    context.putImageData(pixels, 0, 0);
    cutShape(canvas, edit.shape);

    const type = outputType(mime, edit.shape);
    const blob = await new Promise<Blob | null>((resolve) =>
        canvas.toBlob(resolve, type, 0.92),
    );

    if (!blob) {
        throw new Error('The picture couldn’t be made.');
    }

    return { blob, width: canvas.width, height: canvas.height };
};
