// The board itself: drawing, writing, selecting, undoing, and presenting.
import { SHOTS, runBoard } from './harness.mjs';

await runBoard('/demo/konva', async (b) => {
    const {
        page,
        check,
        at,
        draw,
        screenOf,
        itemCount,
        inspectorTitle,
        layerNames,
        painted,
    } = b;

    check(
        'the demo board mounts with something on it',
        (await itemCount()) > 0,
        `${await itemCount()} items`,
    );

    // --- drawing
    const before = await itemCount();
    const sticky = await draw('sticky', 0.3, 0.3);
    check(
        'a tool draws what it says',
        (await itemCount()) === before + 1,
        `${before} → ${await itemCount()}`,
    );
    check(
        'and what was drawn is selected',
        (await inspectorTitle()).toLowerCase() === 'sticky',
        await inspectorTitle(),
    );

    // --- writing on it
    const middle = await screenOf(
        sticky.x + sticky.w / 2,
        sticky.y + sticky.h / 2,
    );
    await b.label(middle, 'Written by a test');
    check(
        'typing on it sticks',
        (await layerNames()).some((name) => name.includes('Written by a test')),
        (await layerNames()).slice(0, 2).join(' | '),
    );

    // --- undo and redo
    const drawn = await itemCount();
    await page.keyboard.press('Control+z');
    await page.waitForTimeout(300);
    await page.keyboard.press('Control+z');
    await page.waitForTimeout(300);
    check(
        'undo takes it back off',
        (await itemCount()) < drawn,
        `${drawn} → ${await itemCount()}`,
    );
    await page.keyboard.press('Control+Shift+z');
    await page.keyboard.press('Control+Shift+z');
    await page.waitForTimeout(400);
    check(
        'redo puts it back',
        (await itemCount()) === drawn,
        `${await itemCount()}`,
    );

    // --- a marquee picks up several, and Delete clears them
    await page.locator('[data-test="tool-select"]').click();
    await page.mouse.move(at(0.08, 0.72).x, at(0.08, 0.72).y);
    await page.mouse.down();
    await page.mouse.move(at(0.92, 0.95).x, at(0.92, 0.95).y, { steps: 8 });
    await page.mouse.up();
    await page.waitForTimeout(300);
    await draw('rect', 0.2, 0.78, 0.08, 0.08);
    await page.keyboard.press('Control+d');
    await page.waitForTimeout(300);
    const copied = await itemCount();
    await page.keyboard.press('Delete');
    await page.waitForTimeout(300);
    check(
        'a copy can be made and thrown away',
        (await itemCount()) < copied,
        `${copied} → ${await itemCount()}`,
    );

    // --- present mode
    const ink = await painted();
    await page.getByRole('button', { name: /present/i }).click();
    await page.waitForTimeout(1200);
    check(
        'presenting hides the tools',
        !(await page.locator('[data-test="tool-sticky"]').isVisible()),
    );
    check('and fills the screen with a frame', (await painted()) !== ink);
    const first = await page.locator('body').innerText();
    await page.keyboard.press('ArrowRight');
    await page.waitForTimeout(1100);
    check(
        'the arrow keys move between frames',
        (await page.locator('body').innerText()) !== first,
        (await page.locator('body').innerText()).match(/\d \/ \d[^\n]*/)?.[0] ??
            '',
    );
    await page.screenshot({ path: `${SHOTS}/board-presenting.png` });
    await page.keyboard.press('Escape');
    await page.waitForTimeout(900);
    check(
        'escape comes back to the board',
        await page.locator('[data-test="tool-sticky"]').isVisible(),
    );
});
