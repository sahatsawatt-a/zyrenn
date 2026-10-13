// Logic on a board: gates between switches and lamps, worked out as it is
// drawn. A click flips a switch or starts a clock without picking it up, and
// the board says what the circuit puts out for everything that can go in.
import { circuitParts } from './circuit-parts.mjs';
import { SHOTS, runBoard } from './harness.mjs';

await runBoard('/demo/konva', async (b) => {
    const { page, check, join, screenOf, inspectorTitle } = b;
    const { place } = circuitParts(b);

    /** Whether what is drawn at a board point is the green of a high signal. */
    const isLive = async (x, y) => {
        const point = await screenOf(x, y);
        const shot = await page.screenshot({
            clip: { x: point.x - 3, y: point.y - 3, width: 6, height: 6 },
        });

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

            // Any of it green: a wire is a pixel wide from this far out
            for (let index = 0; index < data.length; index += 4) {
                if (
                    data[index + 1] > data[index] + 25 &&
                    data[index + 1] > data[index + 2] + 25
                ) {
                    return true;
                }
            }

            return false;
        }, shot.toString('base64'));
    };

    const click = async (x, y, { shift = false } = {}) => {
        const point = await screenOf(x, y);

        if (shift) {
            await page.keyboard.down('Shift');
        }

        await page.mouse.click(point.x, point.y);

        if (shift) {
            await page.keyboard.up('Shift');
        }

        await page.waitForTimeout(250);
    };

    // Somewhere clear of the sample board, close in enough to hit a pin
    await page.keyboard.press('Escape');
    const a = await place('input', 0.3, 0.7);
    const bIn = await place('input', 0.3, 0.8);
    const gate = await place('and', 0.42, 0.75);
    const lamp = await place('output', 0.56, 0.75);
    await page.keyboard.press('Escape');

    await join(null, null, {
        from: await screenOf(a.x + 60, a.y + 20),
        to: await screenOf(gate.x, gate.y + 20),
    });
    await join(null, null, {
        from: await screenOf(bIn.x + 60, bIn.y + 20),
        to: await screenOf(gate.x, gate.y + 60),
    });
    await join(null, null, {
        from: await screenOf(gate.x + 100, gate.y + 40),
        to: await screenOf(lamp.x, lamp.y + 20),
    });
    await page.keyboard.press('Escape');
    await page.waitForTimeout(200);

    const lampMiddle = [lamp.x + 26, lamp.y + 20];

    check(
        'with both switches off the lamp is dark',
        !(await isLive(...lampMiddle)),
    );

    // ----------------------------------------------------- A click flips it
    await click(a.x + 20, a.y + 20);
    check(
        'a click on a switch flips it without picking it up',
        (await inspectorTitle()) === 'Nothing Selected',
        await inspectorTitle(),
    );
    check('the switch shows it is on', await isLive(a.x + 12, a.y + 12));
    check('and its wire is high', await isLive(a.x + 66, a.y + 20));
    check('one switch is not enough for AND', !(await isLive(...lampMiddle)));

    await click(bIn.x + 20, bIn.y + 20);
    check('both switches on light the lamp', await isLive(...lampMiddle));
    // A closer picture, with a gate picked up: its frame is the part's own
    await click(gate.x + 50, gate.y + 40);
    await page.mouse.move(
        (await screenOf(gate.x + 50, gate.y + 40)).x,
        (await screenOf(gate.x + 50, gate.y + 40)).y,
    );
    for (let step = 0; step < 6; step++) {
        await page.mouse.wheel(0, -120);
    }
    await page.waitForTimeout(500);
    await page.screenshot({ path: `${SHOTS}/logic-and.png` });
    for (let step = 0; step < 6; step++) {
        await page.mouse.wheel(0, 120);
    }
    await page.waitForTimeout(500);
    await page.keyboard.press('Escape');

    await click(a.x + 20, a.y + 20);
    check('and one off puts it out again', !(await isLive(...lampMiddle)));

    // --------------------------------------- Chosen with Shift, not flipped
    await click(bIn.x + 20, bIn.y + 20, { shift: true });
    check(
        'Shift and a click picks a switch up instead',
        (await inspectorTitle()).toLowerCase() === 'logic input (switch)' &&
            (await page
                .locator('[data-test="symbol-on"]')
                .getAttribute('data-state')) === 'checked',
        await inspectorTitle(),
    );
    check(
        'named for you',
        (await page.locator('[data-test="symbol-value"]').inputValue()) === 'B',
    );
    await page.keyboard.press('Escape');

    // ------------------------------------------------------- Truth table
    await page.locator('[data-test="board-circuit"]').click();
    const dialog = page.locator('[data-test="circuit-reading"]');
    await dialog.waitFor();
    await dialog.locator('[data-test="circuit-tab-truth"]').click();
    const rows = (
        await dialog.locator('[data-test="circuit-truth"] tr').allInnerTexts()
    ).map((row) => row.replace(/\s+/g, ''));
    check(
        'the board works out the truth table',
        rows.join(' ') === '000 010 100 111',
        rows.join(' '),
    );
    await page.keyboard.press('Escape');
    await dialog.waitFor({ state: 'detached' });

    // -------------------------------------------------------------- A clock
    const clock = await place('clock', 0.3, 0.9);
    const tick = await place('output', 0.42, 0.9);
    await page.keyboard.press('Escape');
    await join(null, null, {
        from: await screenOf(clock.x + 60, clock.y + 20),
        to: await screenOf(tick.x, tick.y + 20),
    });
    await page.keyboard.press('Escape');

    const seen = async () => {
        const levels = new Set();

        for (let sample = 0; sample < 8; sample++) {
            levels.add(await isLive(tick.x + 26, tick.y + 20));
            await page.waitForTimeout(260);
        }

        return levels;
    };

    check('a clock is still until it is started', (await seen()).size === 1);
    await click(clock.x + 20, clock.y + 20);
    check('a click starts it ticking', (await seen()).size === 2);
    await page.screenshot({ path: `${SHOTS}/logic-clock.png` });
    await click(clock.x + 20, clock.y + 20);
    check('and another stops it', (await seen()).size === 1);
});
