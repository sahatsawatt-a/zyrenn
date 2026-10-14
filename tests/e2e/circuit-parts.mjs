// What the circuit suites share: getting a symbol out of the shapes flyout,
// where the electrical ones are folded away until somebody asks for them.

/** Helpers for placing symbols on the board a suite has open. */
export function circuitParts({ page, at, fields }) {
    const flyout = page.locator('[data-test="shape-flyout"]');

    /** Picks a symbol out of the flyout, unfolding the electrical ones first. */
    const pickSymbol = async (symbol) => {
        await page.locator('[data-test="tool-shapes"]').click();
        await flyout.waitFor();

        const fold = flyout.locator('[data-test="electrical-toggle"]');

        if ((await fold.getAttribute('aria-expanded')) !== 'true') {
            await fold.click();
        }

        await flyout.locator(`[data-test="tool-symbol:${symbol}"]`).click();
        await flyout.waitFor({ state: 'detached' });
    };

    /** Puts a part down with a click, and types its value if it is given one. */
    const place = async (symbol, across, down, value) => {
        await pickSymbol(symbol);
        await page.mouse.click(at(across, down).x, at(across, down).y);
        await page.waitForTimeout(300);

        if (value !== undefined) {
            await page.keyboard.type(value);
            await page.keyboard.press('Enter');
            await page.waitForTimeout(250);
        }

        return fields();
    };

    return { flyout, pickSymbol, place };
}

/**
 * Which of the board's telling colours are anywhere in a patch of the screen:
 * the green of a high signal, the violet of a value worked out, the red of a
 * part asked for more than it is good for.
 */
const coloursIn = async (page, clip) => {
    const shot = await page.screenshot({ clip });

    return page.evaluate(async (base64) => {
        const picture = new Image();
        picture.src = `data:image/png;base64,${base64}`;
        await picture.decode();
        const canvas = document.createElement('canvas');
        canvas.width = picture.width;
        canvas.height = picture.height;
        const context = canvas.getContext('2d');
        context.drawImage(picture, 0, 0);
        const { data } = context.getImageData(
            0,
            0,
            canvas.width,
            canvas.height,
        );
        const found = { live: false, worked: false, over: false };

        // Any of it: a wire or a letter is a pixel wide from this far out
        for (let index = 0; index < data.length; index += 4) {
            const [red, green, blue] = data.slice(index, index + 3);

            found.live ||= green > red + 25 && green > blue + 25;
            found.worked ||= blue > red + 40 && blue > green + 40;
            found.over ||= red > green + 60 && red > blue + 60;
        }

        return found;
    }, shot.toString('base64'));
};

/**
 * Whether what is drawn at a board point is the green of a high signal: a
 * wire that carries a 1, a lamp that is lit.
 */
export const liveAt = (page, screenOf) => async (x, y) => {
    const point = await screenOf(x, y);

    return (
        await coloursIn(page, {
            x: point.x - 3,
            y: point.y - 3,
            width: 6,
            height: 6,
        })
    ).live;
};

/**
 * What is written in a part of the board, by its colours: between two
 * corners given as board points.
 */
export const inksIn = (page, screenOf) => async (left, top, right, bottom) => {
    const from = await screenOf(left, top);
    const to = await screenOf(right, bottom);

    return coloursIn(page, {
        x: from.x,
        y: from.y,
        width: to.x - from.x,
        height: to.y - from.y,
    });
};
