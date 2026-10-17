// Lines that cross: on a board the one lying across hops over the one
// standing up with a small bump, and stops once they no longer cross; in the
// workflow editor a line between steps does the same.
import { SHOTS, open, runBoard, tinker } from './harness.mjs';

const RUN = Date.now().toString().slice(-6);
const TITLE = `e2e hops ${RUN}`;
const user = `App\\Models\\User::where('email', '${process.env.E2E_EMAIL ?? 'test@example.com'}')->first()`;
const at = (x, y) => ({ x, y });

// Two lines out of two steps, the second crossing the first on its way up
const ref = tinker(
    `$u = ${user}; $w = (new App\\Models\\Workflow\\Workflow)->ownedBy($u, $u); $w->title = '${TITLE}'; $w->definition = json_decode('${JSON.stringify(
        {
            nodes: [
                {
                    id: 'a1',
                    key: 'start',
                    type: 'trigger.manual',
                    position: at(0, 0),
                    config: {},
                },
                {
                    id: 'b2',
                    key: 'up',
                    type: 'output.log',
                    position: at(500, -150),
                    config: { message: 'up' },
                },
                {
                    id: 'c3',
                    key: 'side',
                    type: 'output.log',
                    position: at(100, 300),
                    config: { message: 'side' },
                },
                {
                    id: 'd4',
                    key: 'down',
                    type: 'output.log',
                    position: at(500, 450),
                    config: { message: 'down' },
                },
            ],
            edges: [
                {
                    id: 'a1.out.d4',
                    source: 'a1',
                    sourceHandle: 'out',
                    target: 'd4',
                },
                {
                    id: 'c3.out.b2',
                    source: 'c3',
                    sourceHandle: 'out',
                    target: 'b2',
                },
            ],
        },
    )}', true); $w->save(); echo $w->ref_id;`,
);

await runBoard('/demo/konva', async (b) => {
    const { page, soon, drawAt, join, screenOf, afterwards } = b;

    afterwards(() =>
        tinker(
            `App\\Models\\Workflow\\Workflow::where('ref_id', '${ref}')->first()?->delete();`,
        ),
    );

    // How each connector on the board is drawn: a Shape where it hops, a
    // Line where it does not
    const lines = () =>
        page.evaluate(() =>
            window.Konva.stages[0]
                .find('Group')
                .filter(
                    (group) =>
                        group.id() &&
                        !group.findOne('Rect') &&
                        !group.findOne('Path') &&
                        (group.findOne('Line') || group.findOne('Shape')),
                )
                .map((group) =>
                    group
                        .getChildren()
                        .find(
                            (node) =>
                                ['Line', 'Shape'].includes(
                                    node.getClassName(),
                                ) && node.visible(),
                        )
                        ?.getClassName(),
                ),
        );

    // ------------------------------------------------------------- A board
    const top = await drawAt('rect', 300, -900, 120, 60);
    const bottom = await drawAt('rect', 300, -560, 120, 60);
    const left = await drawAt('rect', 60, -760, 120, 60);
    const right = await drawAt('rect', 560, -760, 120, 60);
    const before = (await lines()).length;

    await join(top, bottom, {
        from: await screenOf(top.x + top.w / 2, top.y + top.h),
        to: await screenOf(bottom.x + bottom.w / 2, bottom.y),
    });
    await join(left, right, {
        from: await screenOf(left.x + left.w, left.y + left.h / 2),
        to: await screenOf(right.x, right.y + right.h / 2),
    });

    let seen;
    await soon(
        'where two connectors cross, the one lying across hops over the other',
        async () => {
            seen = (await lines()).slice(before);

            return seen.length === 2 && seen.sort().join() === 'Line,Shape';
        },
        () => JSON.stringify(seen),
    );

    // Moved up past the top of the other line, the line no longer crosses,
    // nor hops (it goes round the top shape rather than through it)
    const grab = await screenOf(left.x + left.w / 2, left.y + left.h / 2);
    const to = await screenOf(left.x + left.w / 2, top.y - 100);
    await page.mouse.move(grab.x, grab.y);
    await page.mouse.down();
    await page.mouse.move(to.x, to.y, { steps: 8 });
    await page.mouse.up();
    await page.keyboard.press('Escape');
    await soon(
        'and once they no longer cross, it is a plain line again',
        async () => {
            seen = (await lines()).slice(before);

            return seen.join() === 'Line,Line';
        },
        () => JSON.stringify(seen),
    );

    // ------------------------------------------------------ A workflow
    await open(page, `${new URL(page.url()).origin}/workflows/${ref}`);
    await page.locator('.vue-flow__edge-path').first().waitFor();

    let bumps;
    await soon(
        'in a workflow, the line lying across hops over the one it crosses',
        async () => {
            bumps = await page
                .locator('.vue-flow__edge-path')
                .evaluateAll((paths) =>
                    paths.map((path) => /A/.test(path.getAttribute('d'))),
                );

            return bumps.length === 2 && bumps.filter(Boolean).length === 1;
        },
        () => JSON.stringify(bumps),
    );

    await page.screenshot({ path: `${SHOTS}/line-hops.png` });
});
