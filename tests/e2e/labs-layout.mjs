// A circuit written as text, laid out by the server and put on a board: what
// an AI client gets from create-board's "circuit", tried from the labs page.
import { SHOTS, boardKept, removeAt, runBoard, until } from './harness.mjs';

await runBoard(
    '/demo/labs',
    async ({ page, check, soon, afterwards, ready }) => {
        const base = new URL(page.url()).origin;
        const section = page.locator('[data-test="labs-layout"]');
        const text = section.locator('[data-test="labs-layout-text"]');
        const go = section.locator('[data-test="labs-layout-go"]');

        // What cannot be read is said, and no board is made
        await text.fill('R1: 10k\nR1 -- Q7.b');
        await go.click();
        await soon(
            'a circuit that cannot be read is said to be, with its line',
            async () =>
                /Line 2/.test(
                    await section
                        .locator('[data-test="labs-layout-error"]')
                        .innerText(),
                ),
        );

        await text.fill(
            'U1: esp32\nR1: 330\nD1: led green\nS1: button\nU1.GPIO2 -- R1 -- D1 -- GND\nU1.GPIO4 -- S1 -- GND\nU1.3V3 -- 3V3\nU1.GND -- GND',
        );
        await go.click();
        await page.waitForURL(/\/boards\/[a-z0-9]+$/);

        const ref = page.url().split('/').pop();

        afterwards(() => removeAt(page, `${base}/boards/${ref}`));
        await ready();

        const items = Object.values(await until(() => boardKept(page, ref)));
        const parts = items.filter((item) => item.kind === 'symbol');
        const wires = items.filter((item) => item.kind === 'arrow');
        const named = Object.fromEntries(
            parts.map((part) => [part.id, part.symbol]),
        );

        check(
            'the board has each part, under its own name',
            named.u1 === 'esp32' &&
                named.r1 === 'resistor' &&
                named.d1 === 'led' &&
                named.s1 === 'button',
            JSON.stringify(named),
        );
        check(
            'on the grid',
            parts.every((part) => part.x % 20 === 0 && part.y % 20 === 0),
        );
        check(
            'and wired pin to pin',
            wires.length >= 5 &&
                wires.every((wire) => wire.from?.pin && wire.to?.pin),
            `${wires.length} wires`,
        );

        // No part sits on another (those drawn upright: a turned one's
        // corner is not where it is drawn from)
        const upright = parts.filter((part) => part.rotation === 0);
        const over = upright.filter((one, index) =>
            upright
                .slice(index + 1)
                .some(
                    (other) =>
                        one.x < other.x + other.width &&
                        other.x < one.x + one.width &&
                        one.y < other.y + other.height &&
                        other.y < one.y + one.height,
                ),
        );

        check(
            'with no part lying on another',
            over.length === 0,
            over.map((part) => part.id).join(', '),
        );

        // A ground drawn in line with one pin of the ESP32 leaves the part
        // on the next pin down its room
        const byId = Object.fromEntries(parts.map((part) => [part.id, part]));

        check(
            'and room left beside a part for the ground at its end',
            byId.r1.x >= byId.s1.x + byId.s1.width + 80,
            `S1 ends at ${byId.s1.x + byId.s1.width}, R1 starts at ${byId.r1.x}`,
        );
        await page.screenshot({ path: `${SHOTS}/labs-layout.png` });
    },
    { canvas: false },
);
