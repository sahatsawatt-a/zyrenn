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
