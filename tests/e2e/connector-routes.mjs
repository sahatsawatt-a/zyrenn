// Connectors kept apart and out of the way: an elbow goes round a shape lying
// between its ends and straightens once the shape is moved off, and lines
// fanning out of one shape neither leave from one point nor run on top of
// each other.
import { SHOTS, runBoard } from './harness.mjs';

await runBoard('/demo/konva', async (b) => {
    const { page, soon, draw, join, screenOf } = b;

    // Each connector's path as drawn, by the line under its caps: always
    // there, hopping or not (ConnectorShape)
    const paths = () =>
        page.evaluate(() =>
            window.Konva.stages[0]
                .find('Line')
                .filter((line) => line.hitStrokeWidth() === 18)
                .map((line) => line.points()),
        );

    const runsOf = (points) =>
        points.slice(0, -2).flatMap((_, index) =>
            index % 2
                ? []
                : [
                      {
                          x1: points[index],
                          y1: points[index + 1],
                          x2: points[index + 2],
                          y2: points[index + 3],
                      },
                  ],
        );

    // Inside the box, not along its edge
    const through = (points, box) =>
        runsOf(points).some(
            (run) =>
                Math.max(run.x1, run.x2) > box.x + 1 &&
                Math.min(run.x1, run.x2) < box.x + box.w - 1 &&
                Math.max(run.y1, run.y2) > box.y + 1 &&
                Math.min(run.y1, run.y2) < box.y + box.h - 1,
        );

    // Runs of two different lines lying along each other, closer than 4
    const onTopOfEachOther = (lines) =>
        lines.flatMap((a, i) =>
            lines.slice(i + 1).flatMap((b) =>
                runsOf(a).flatMap((p) =>
                    runsOf(b).filter((q) => {
                        const upright = (run) => run.x1 === run.x2;

                        if (upright(p) !== upright(q)) {
                            return false;
                        }

                        const [across, along] = upright(p)
                            ? ['x', 'y']
                            : ['y', 'x'];
                        const low = (run) =>
                            Math.min(run[`${along}1`], run[`${along}2`]);
                        const high = (run) =>
                            Math.max(run[`${along}1`], run[`${along}2`]);

                        return (
                            Math.abs(p[`${across}1`] - q[`${across}1`]) < 4 &&
                            Math.min(high(p), high(q)) -
                                Math.max(low(p), low(q)) >
                                2
                        );
                    }),
                ),
            ),
        );

    // ----------------------------------------- A shape in the line's way
    const first = await draw('rect', 0.24, 0.64, 0.08, 0.08);
    const middle = await draw('rect', 0.42, 0.64, 0.08, 0.08);
    const last = await draw('rect', 0.6, 0.64, 0.08, 0.08);
    const before = (await paths()).length;

    await join(first, last);

    let seen;
    await soon(
        'an elbow goes round a shape lying between its ends, not through it',
        async () => {
            seen = (await paths()).slice(before);

            return seen.length === 1 && !through(seen[0], middle);
        },
        () => JSON.stringify(seen),
    );

    // Moved off, the shape is no longer in the way and the line runs straight
    const grab = await screenOf(
        middle.x + middle.w / 2,
        middle.y + middle.h / 2,
    );
    await page.mouse.move(grab.x, grab.y);
    await page.mouse.down();
    await page.mouse.move(grab.x, grab.y + 150, { steps: 8 });
    await page.mouse.up();
    await page.keyboard.press('Escape');
    await soon(
        'and straightens once the shape is moved out of the way',
        async () => {
            seen = (await paths()).slice(before);

            return seen.length === 1 && seen[0].length === 4;
        },
        () => JSON.stringify(seen),
    );

    // -------------------------------------------- Lines fanning out of one
    const hub = await draw('rect', 0.24, 0.1, 0.08, 0.1);
    const ends = [];

    for (const [index, y] of [0.02, 0.13, 0.24].entries()) {
        ends[index] = await draw('rect', 0.48, y, 0.1, 0.07);
    }

    const fanned = (await paths()).length;

    for (const end of ends) {
        // Out of the hub's right face, into each one's left
        await join(hub, end, {
            from: await screenOf(hub.x + hub.w - 2, hub.y + hub.h / 2),
            to: await screenOf(end.x + 2, end.y + end.h / 2),
        });
    }

    let lines;
    await soon(
        'lines out of one shape leave from points of their own',
        async () => {
            lines = (await paths()).slice(fanned);

            return (
                lines.length === 3 &&
                new Set(lines.map((line) => `${line[0]},${line[1]}`)).size === 3
            );
        },
        () => JSON.stringify(lines),
    );
    await soon(
        'and never run on top of each other',
        async () => {
            lines = (await paths()).slice(fanned);

            return lines.length === 3 && !onTopOfEachOther(lines).length;
        },
        () => JSON.stringify(onTopOfEachOther(lines ?? [])),
    );

    await page.screenshot({ path: `${SHOTS}/connector-routes.png` });
});
