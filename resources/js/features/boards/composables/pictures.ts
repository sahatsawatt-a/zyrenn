// Pictures on the board: SVG markup turned into something an <img> can draw.

/**
 * An SVG document as a data URL, with the size it asks for.
 *
 * The markup is never put in the page: it is handed to an <img>, which is the
 * one place a browser draws SVG without running anything inside it.
 */
export const svgSource = (
    markup: string,
): { src: string; width: number; height: number } => {
    const attribute = (name: string) =>
        markup.match(new RegExp(`<svg[^>]*\\s${name}="([^"]+)"`, 'i'))?.[1];

    const viewBox = attribute('viewBox')
        ?.trim()
        .split(/[\s,]+/)
        .map(Number);
    const number = (value: string | undefined) => {
        const parsed = parseFloat(value ?? '');

        return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
    };

    const width =
        number(attribute('width')) ??
        (viewBox?.length === 4 ? viewBox[2] : null) ??
        200;
    const height =
        number(attribute('height')) ??
        (viewBox?.length === 4 ? viewBox[3] : null) ??
        200;

    // Scaled so a 24px icon is usable on a board, keeping its proportions
    const longest = Math.max(width, height);
    const factor = longest < 120 ? 200 / longest : 1;

    // Inline in a page the parser infers the namespace, but an <img> loads the
    // document on its own and refuses it without one -- which is why markup
    // pasted straight from a web page can arrive lacking it.
    const document = /<svg[^>]*\sxmlns=/i.test(markup)
        ? markup
        : markup.replace(/<svg/i, '<svg xmlns="http://www.w3.org/2000/svg"');

    return {
        src: `data:image/svg+xml;base64,${btoa(unescape(encodeURIComponent(document)))}`,
        width: Math.round(width * factor),
        height: Math.round(height * factor),
    };
};
