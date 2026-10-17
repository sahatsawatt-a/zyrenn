// Circuits on a board: parts from the symbol library, wires between their
// pins, the dot a wire dropped on a wire makes, and what the board then reads
// back as a circuit -- its parts, its nets and what is left unconnected.
import { circuitParts } from './circuit-parts.mjs';
import { SHOTS, runBoard } from './harness.mjs';

await runBoard('/demo/konva', async (b) => {
    const {
        page,
        check,
        fields,
        join,
        screenOf,
        itemCount,
        inspectorTitle,
        link,
    } = b;

    const { flyout, place } = circuitParts(b);

    const value = (test) => page.locator(`[data-test="${test}"]`).inputValue();

    /** What the circuit dialog says, read and closed again. */
    const reading = async () => {
        await page.locator('[data-test="board-circuit"]').click();
        const dialog = page.locator('[data-test="circuit-reading"]');
        await dialog.waitFor();
        await dialog.locator('[data-test="circuit-tab-parts"]').click();

        const parts = await dialog
            .locator('[data-test="circuit-parts"]')
            .innerText();
        const problems = (await dialog
            .locator('[data-test="circuit-problems"]')
            .count())
            ? await dialog.locator('[data-test="circuit-problems"]').innerText()
            : '';

        await dialog.locator('[data-test="circuit-tab-nets"]').click();
        const nets = await dialog
            .locator('[data-test="circuit-nets"]')
            .innerText();

        await page.keyboard.press('Escape');
        await dialog.waitFor({ state: 'detached' });

        return { parts, nets, problems };
    };

    // Somewhere clear of the sample board, close enough in to hit a pin
    await page
        .locator('[data-test="layers-close"]')
        .click()
        .catch(() => {});

    // ------------------------------------------- A board for any diagram
    await page.locator('[data-test="tool-shapes"]').click();
    await flyout.waitFor();
    check(
        'the electrical symbols are folded away until asked for',
        (await flyout.locator('[data-test="tool-rect"]').count()) === 1 &&
            (await flyout.locator('[data-test^="tool-symbol:"]').count()) === 0,
        `${await flyout.locator('[data-test^="tool-symbol:"]').count()} symbols showing`,
    );
    await flyout.locator('[data-test="shape-search"]').fill('resist');
    check(
        'though a search finds them',
        (await flyout.locator('[data-test="tool-symbol:resistor"]').count()) ===
            1,
    );
    await flyout.locator('[data-test="shape-search"]').fill('');
    await page.keyboard.press('Escape');
    check(
        'and a board with no circuit on it has no Circuit button',
        (await page.locator('[data-test="board-circuit"]').count()) === 0,
    );

    const before = await itemCount();

    // ------------------------------------------------------------ Parts
    const r1 = await place('resistor', 0.3, 0.72, '10k');
    check(
        'a resistor goes down with a click',
        (await itemCount()) === before + 1,
        `${before} → ${await itemCount()}`,
    );
    check(
        'at the size the library draws it',
        r1.w === 80 && r1.h === 40,
        `${r1.w}×${r1.h}`,
    );
    check(
        'on the grid',
        r1.x % 20 === 0 && (r1.y + 20) % 20 === 0,
        `${r1.x},${r1.y}`,
    );
    check(
        'the inspector names it',
        (await inspectorTitle()) === 'Resistor',
        await inspectorTitle(),
    );
    check(
        'it is numbered for you',
        (await value('symbol-name')) === 'R1',
        await value('symbol-name'),
    );
    check(
        'and what was typed is its value',
        (await value('symbol-value')) === '10k',
        await value('symbol-value'),
    );

    const c1 = await place('capacitor', 0.3, 0.72, '100n');
    // It landed on the resistor: drag it clear, to the right
    const grab = await screenOf(c1.x + 40, c1.y + 20);
    const there = await screenOf(r1.x + 240 + 40, r1.y + 20);
    await page.mouse.move(grab.x, grab.y);
    await page.mouse.down();
    await page.mouse.move(there.x + 3, there.y + 2, { steps: 10 });
    await page.mouse.up();
    await page.waitForTimeout(300);

    const moved = await fields();
    check(
        'a dragged part stays on the grid',
        moved.x > r1.x + 200 && moved.x % 20 === 0 && (moved.y + 20) % 20 === 0,
        `${moved.x},${moved.y}`,
    );

    await page.keyboard.press('Escape');
    const r2 = await place('resistor', 0.3, 0.72);
    await page.keyboard.press('Escape');
    check(
        'a second resistor takes the next number',
        (await value('symbol-name').catch(() => '')) === 'R2' ||
            (await (async () => {
                await page.mouse.click(
                    (await screenOf(r2.x + 40, r2.y + 20)).x,
                    (await screenOf(r2.x + 40, r2.y + 20)).y,
                );
                await page.waitForTimeout(200);

                return value('symbol-name');
            })()) === 'R2',
    );
    await page.keyboard.press('Delete');
    await page.waitForTimeout(250);

    // ------------------------------------------------------------- Wires
    await join(null, null, {
        from: await screenOf(r1.x + 80, r1.y + 20),
        to: await screenOf(moved.x, moved.y + 20),
    });
    check(
        'a connector between two pins says which pins',
        (await link()) === 'R1.2 → C1.1',
        await link(),
    );
    check(
        'and is a wire, with no head',
        (
            await page
                .locator(
                    '[data-test="ends-config"] [data-fold-toggle] span:last-of-type',
                )
                .innerText()
        ).trim() === 'none → none',
        await page
            .locator(
                '[data-test="ends-config"] [data-fold-toggle] span:last-of-type',
            )
            .innerText(),
    );

    let read = await reading();
    check(
        'the board reads the parts back',
        /Resistor\s+10k\s+1\s+R1/.test(read.parts) &&
            /Capacitor\s+100n\s+1\s+C1/.test(read.parts),
        read.parts.replace(/\s+/g, ' '),
    );
    check(
        'and the net the wire makes',
        /C1\.1, R1\.2/.test(read.nets),
        read.nets.replace(/\s+/g, ' '),
    );
    check(
        'and says which pins are left open',
        /R1 pin 1 is not connected/.test(read.problems) &&
            /C1 pin 2 is not connected/.test(read.problems),
        read.problems.replace(/\s+/g, ' '),
    );

    // ---------------------------------------------- A wire onto a wire
    const ground = await place('ground', 0.3, 0.72);
    const from = await screenOf(ground.x + 20, ground.y + 20);
    const below = await screenOf(r1.x + 160, r1.y + 140);
    await page.mouse.move(from.x, from.y);
    await page.mouse.down();
    await page.mouse.move(below.x, below.y, { steps: 10 });
    await page.mouse.up();
    await page.waitForTimeout(300);

    const placed = await fields();
    const joined = await itemCount();
    await join(null, null, {
        from: await screenOf(placed.x + 20, placed.y),
        to: await screenOf(r1.x + 160, r1.y + 20),
    });
    check(
        'a wire dropped on a wire puts a dot there and splits it',
        (await itemCount()) === joined + 3,
        `${joined} → ${await itemCount()}`,
    );

    read = await reading();
    check(
        'and the three are one net, on ground',
        /gnd\s+C1\.1, R1\.2/i.test(read.nets),
        read.nets.replace(/\s+/g, ' '),
    );

    // -------------------------------------------------------------- Turning
    const spot = await screenOf(moved.x + 40, moved.y + 8);
    await page.keyboard.press('Escape');
    await page.mouse.click(spot.x, spot.y);
    await page.waitForTimeout(200);
    await page.keyboard.press('r');
    await page.waitForTimeout(250);

    const turnedTo = await fields();
    check(
        'R turns the selected part about its middle',
        turnedTo.x === moved.x + 60 && turnedTo.y === moved.y - 20,
        `${moved.x},${moved.y} → ${turnedTo.x},${turnedTo.y}`,
    );

    read = await reading();
    check(
        'and it stays wired by the same pin',
        /gnd\s+C1\.1, R1\.2/i.test(read.nets),
        read.nets.replace(/\s+/g, ' '),
    );

    await page.screenshot({ path: `${SHOTS}/circuit-board.png` });

    // ------------------------------------------------ Boards and chips
    await page.keyboard.press('Escape');
    const esp = await place('esp32', 0.62, 0.3);
    check(
        'a microcontroller board goes down with its pins by name',
        (await inspectorTitle()) === 'ESP32 DevKit' &&
            esp.w === 160 &&
            esp.h === 320 &&
            (await value('symbol-pins')).startsWith('EN, GPIO36'),
        `${await inspectorTitle()} ${esp.w}×${esp.h}`,
    );
    check(
        'named for what it is',
        (await value('symbol-name')) === 'U1' &&
            (await value('symbol-value')) === 'ESP32',
        `${await value('symbol-name')} ${await value('symbol-value')}`,
    );

    const led = await place('led', 0.3, 0.72);
    await join(null, null, {
        from: await screenOf(esp.x + 160, esp.y + 220),
        to: await screenOf(led.x, led.y + 20),
    });
    check(
        'a wire from one of its pins says which',
        (await link()) === 'U1.GPIO4 → D1.a',
        await link(),
    );

    // Its pins typed anew make it a chip of one's own
    const chipAt = await screenOf(esp.x + 80, esp.y + 12);
    await page.keyboard.press('Escape');
    await page.mouse.click(chipAt.x, chipAt.y);
    await page.waitForTimeout(200);
    await page
        .locator('[data-test="symbol-pins"]')
        .fill('VCC, GND, GPIO4 | TX, RX');
    await page.locator('[data-test="symbol-pins"]').press('Enter');
    await page.waitForTimeout(300);
    const chip = await fields();
    check(
        'typing its pins makes it a chip the size they need',
        chip.h === 80 &&
            (await value('symbol-pins')) === 'VCC, GND, GPIO4 | TX, RX',
        `${chip.w}×${chip.h} ${await value('symbol-pins')}`,
    );
    read = await reading();
    check(
        'and a wire on a pin it still has stays on it',
        /D1\.a, U1\.GPIO4/.test(read.nets),
        read.nets.replace(/\s+/g, ' '),
    );
    await page.screenshot({ path: `${SHOTS}/circuit-chip.png` });

    // ------------------------------------------------------------ From text
    const drawnBefore = await itemCount();
    await page.keyboard.press('Escape');
    await page.locator('[data-test="tool-shapes"]').click();
    await flyout.locator('[data-test="circuit-from-text"]').click();

    const dialog = page.locator('[data-test="circuit-dialog"]');
    await dialog.waitFor();
    check(
        'the text is drawn as it is typed',
        (await dialog.locator('[data-test="circuit-preview"] svg').count()) ===
            1,
    );

    await dialog
        .locator('[data-test="circuit-source"]')
        .fill('R1: 10k\nR1 -- ');
    check(
        'a mistake is said, with its line',
        /Line 2/.test(
            await dialog.locator('[data-test="circuit-error"]').innerText(),
        ),
    );

    await dialog
        .locator('[data-test="circuit-source"]')
        .fill('V9: 9V\nR9: 330\nD9: led red\nV9 -- R9 -- D9 -- V9');
    await dialog.locator('[data-test="circuit-add"]').click();
    await dialog.waitFor({ state: 'detached' });
    await page.waitForTimeout(400);

    // Three parts and the three wires of the loop
    check(
        'a circuit from text lands as parts and wires',
        (await itemCount()) === drawnBefore + 6,
        `${drawnBefore} → ${await itemCount()}`,
    );

    read = await reading();
    check(
        'which the board reads as the same circuit',
        /LED\s+red\s+1\s+D9/.test(read.parts) &&
            /D9\.a, R9\.2/.test(read.nets) &&
            /D9\.k, V9\.-/.test(read.nets),
        `${read.parts} ${read.nets}`.replace(/\s+/g, ' '),
    );

    await page.locator('[data-test="fit"]').click();
    await page.waitForTimeout(500);
    await page.screenshot({ path: `${SHOTS}/circuit-from-text.png` });
});
