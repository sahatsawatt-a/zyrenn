/**
 * An SVG drawing as a PNG, drawn at twice the size for sharp text -- short
 * of what a browser will hold in one canvas -- on white, so it reads the
 * same wherever the file is opened.
 */
export async function svgToPng(
    markup: string,
    width: number,
    height: number,
): Promise<Blob> {
    const picture = new Image();
    picture.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(markup)}`;
    await picture.decode();

    const scale = Math.min(
        2,
        8192 / width,
        8192 / height,
        Math.sqrt(32_000_000 / (width * height)),
    );
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(width * scale);
    canvas.height = Math.round(height * scale);

    const context = canvas.getContext('2d')!;
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(picture, 0, 0, canvas.width, canvas.height);

    return new Promise((resolve, reject) =>
        canvas.toBlob(
            (blob) =>
                blob
                    ? resolve(blob)
                    : reject(new Error('Couldn’t draw this diagram.')),
            'image/png',
        ),
    );
}
