// The logic parts being tried out, on the labs board: a gate with three
// inputs, a push-button that is on only while it is held, and a 7-segment
// display that shows the number its switches spell.
import { liveAt } from './circuit-parts.mjs';
import { SHOTS, runBoard } from './harness.mjs';

await runBoard('/demo/labs/board', async ({ page, check, soon, screenOf }) => {
    const isLive = liveAt(page, screenOf);
    const click = async (x, y) => {
        const point = await screenOf(x, y);

        await page.mouse.click(point.x, point.y);
    };

    await page.screenshot({ path: `${SHOTS}/labs-logic.png` });

    // ------------------------------------------------------ three-input gates
    // A and B start on, C off: any of them is enough for OR, not for AND
    const all = [406, 120];
    const any = [406, 280];

    check('with one switch off, a three-input OR is on', await isLive(...any));
    check('and a three-input AND is not', !(await isLive(...all)));

    await click(20, 280);
    await soon('the third switch on, AND is too', () => isLive(...all));

    // ------------------------------------------------------------ push-button
    const held = [406, 520];
    const kept = [406, 600];
    const button = await screenOf(30, 520);

    check('a push-button left alone is off', !(await isLive(...held)));
    check(
        'and what it clocks has not looked at its switch yet',
        !(await isLive(...kept)),
    );

    await page.mouse.move(button.x, button.y);
    await page.mouse.down();
    await soon('held down, it is on', () => isLive(...held));
    await soon('and the flip-flop takes what its switch says', () =>
        isLive(...kept),
    );

    await page.mouse.up();
    await soon('let go, it is off again', async () => !(await isLive(...held)));
    check('while what was taken is kept', await isLive(...kept));

    // The switch off: nothing changes until the next press
    await click(20, 600);
    await page.mouse.move(button.x, button.y);
    await page.mouse.down();
    await soon(
        'a press with the switch off puts the lamp out',
        async () => !(await isLive(...kept)),
    );
    await page.mouse.up();

    // ------------------------------------------------------ 7-segment display
    // The display is 100 by 160; its segments, by where they are on it
    const display = { x: 200, y: 840 };
    const lit = async () => {
        const at = {
            a: [60, 38],
            b: [82, 60],
            c: [82, 100],
            d: [60, 122],
            e: [38, 100],
            f: [38, 60],
            g: [60, 80],
        };
        let found = '';

        for (const [segment, [x, y]] of Object.entries(at)) {
            if (await isLive(display.x + x, display.y + y)) {
                found += segment;
            }
        }

        return found;
    };

    // 1 and 4 start on: five
    await soon(
        'switches 1 and 4 spell a 5',
        async () => (await lit()) === 'acdfg',
        lit,
    );

    await click(20, 900);
    await soon('with 2 as well, a 7', async () => (await lit()) === 'abc', lit);
    await page.screenshot({ path: `${SHOTS}/labs-logic-after.png` });
});
