import { describe, expect, it } from 'vitest';
import { attachmentsOf, LABEL_HEIGHT, labelWidth } from './connectors';
import type { Item, Side } from './items';
import { makeItem } from './items';
import { connectorRoutes, LANE, MARGIN } from './routing';

const card = (
    id: string,
    x: number,
    y: number,
    width = 160,
    height = 80,
): Item => ({ ...makeItem('rect', x, y), id, width, height });

const arrow = (
    id: string,
    from: string,
    to: string,
    sides: [Side | null, Side | null] = [null, null],
    text = '',
): Item => ({
    ...makeItem('arrow', 0, 0),
    id,
    text,
    from: { item: from, side: sides[0], x: 0, y: 0 },
    to: { item: to, side: sides[1], x: 0, y: 0 },
});

const routed = (items: Item[]) =>
    connectorRoutes(
        items,
        new Map(items.map((item) => [item.id, item])),
        attachmentsOf(items),
    );

type Run = { line: string; x1: number; y1: number; x2: number; y2: number };

const runsOf = (routes: ReturnType<typeof routed>): Run[] =>
    [...routes].flatMap(([line, { points }]) =>
        points.slice(0, -2).flatMap((_, index) =>
            index % 2
                ? []
                : [
                      {
                          line,
                          x1: points[index],
                          y1: points[index + 1],
                          x2: points[index + 2],
                          y2: points[index + 3],
                      },
                  ],
        ),
    );

/** Runs of two different lines lying along each other, closer than a lane. */
const onTopOfEachOther = (runs: Run[]) =>
    runs.flatMap((a, i) =>
        runs.slice(i + 1).filter((b) => {
            if (a.line === b.line) {
                return false;
            }

            const upright = (run: Run) => run.x1 === run.x2;

            if (upright(a) !== upright(b)) {
                return false;
            }

            const [across, along] = upright(a) ? ['x', 'y'] : ['y', 'x'];
            const low = (run: Run) =>
                Math.min(run[`${along}1` as 'x1'], run[`${along}2` as 'x2']);
            const high = (run: Run) =>
                Math.max(run[`${along}1` as 'x1'], run[`${along}2` as 'x2']);

            return (
                Math.abs(a[`${across}1` as 'x1'] - b[`${across}1` as 'x1']) <
                    LANE - 1 &&
                Math.min(high(a), high(b)) - Math.max(low(a), low(b)) > 1
            );
        }),
    );

const through = (run: Run, box: Item, by = 0) =>
    Math.max(run.x1, run.x2) > box.x - by &&
    Math.min(run.x1, run.x2) < box.x + box.width + by &&
    Math.max(run.y1, run.y2) > box.y - by &&
    Math.min(run.y1, run.y2) < box.y + box.height + by;

describe('lines fanning out of one side', () => {
    // nginx in front of three services stacked to its right, as on the
    // "Zyrenn at a glance" board
    const web = card('web', 0, 250, 200, 90);
    const reverb = card('reverb', 260, 0, 250, 80);
    const collab = card('collab', 260, 110, 250, 100);
    const app = card('app', 260, 240, 250, 110);
    const items = [
        web,
        reverb,
        collab,
        app,
        arrow('a', 'web', 'reverb', ['right', 'left']),
        arrow('b', 'web', 'collab', ['right', 'left']),
        arrow('c', 'web', 'app', ['right', 'left']),
    ];
    const routes = routed(items);

    it('leave at points of their own along it', () => {
        const starts = ['a', 'b', 'c'].map((id) => routes.get(id)!.points[1]);

        expect(new Set(starts).size).toBe(3);
        // In the order their far ends lie, so they do not cross on the way
        expect(starts).toEqual([...starts].sort((x, y) => x - y));
    });

    it('run in lanes of their own, never on top of each other', () => {
        expect(onTopOfEachOther(runsOf(routes))).toEqual([]);
    });

    it('and the one level with its far end goes straight across', () => {
        expect(routes.get('c')!.points).toHaveLength(4);
    });
});

describe('a line with an item in its way', () => {
    // Three in a row; the line from the first to the last
    const first = card('first', 0, 0);
    const middle = card('middle', 260, 0);
    const last = card('last', 520, 0);
    const line = arrow('line', 'first', 'last', ['right', 'left']);
    const routes = routed([first, middle, last, line]);

    it('goes round it, keeping clear, rather than through', () => {
        const runs = runsOf(routes);

        expect(runs.some((run) => through(run, middle, MARGIN - 1))).toBe(
            false,
        );
        // ...and still leaves and arrives by the faces it was given
        const points = routes.get('line')!.points;

        expect(points.slice(0, 2)).toEqual([160, 40]);
        expect(points.slice(-2)).toEqual([520, 40]);
    });

    it('leaves the plain elbow alone when nothing is in the way', () => {
        const clear = routed([first, last, line]);

        expect(clear.get('line')!.points).toEqual([160, 40, 520, 40]);
    });

    it('does not double back through its own shapes', () => {
        // The far end behind where the line has to leave from
        const behind = arrow('back', 'last', 'first', ['right', 'left']);
        const runs = runsOf(routed([first, last, behind]));

        for (const box of [first, last]) {
            expect(
                runs.some((run) =>
                    // Not counting the stubs that leave and reach the edges
                    through(run, {
                        ...box,
                        x: box.x + 1,
                        width: box.width - 2,
                    }),
                ),
            ).toBe(false);
        }
    });

    it('ignores a box drawn round both ends', () => {
        const group = card('group', -40, -40, 760, 160);
        const clear = routed([group, first, last, line]);

        expect(clear.get('line')!.points).toEqual([160, 40, 520, 40]);
    });
});

describe('an arrow given no sides', () => {
    it('leaves by the faces that look at each other', () => {
        const routes = routed([
            card('a', 0, 0),
            card('b', 400, 0),
            arrow('line', 'a', 'b'),
        ]);

        expect(routes.get('line')!.points).toEqual([160, 40, 400, 40]);
    });

    it('turns a corner once when the shapes are off to one side', () => {
        const routes = routed([
            card('a', 0, 0),
            card('b', 400, 300),
            arrow('line', 'a', 'b'),
        ]);

        // Start, the corner, end
        expect(routes.get('line')!.points).toHaveLength(6);
    });

    it('keeps a side somebody chose', () => {
        const routes = routed([
            card('a', 0, 0),
            card('b', 400, 0),
            arrow('line', 'a', 'b', ['top', null]),
        ]);

        // Out of the top: up from the top edge's middle
        expect(routes.get('line')!.points.slice(0, 4)).toEqual([
            80, 0, 80, -24,
        ]);
    });
});

describe('a label', () => {
    // A line straight down, with a card close beside its middle
    const top = card('top', 0, 0, 100, 60);
    const bottom = card('bottom', 0, 400, 100, 60);
    const beside = card('beside', 70, 200, 100, 60);
    const named = arrow('line', 'top', 'bottom', [null, null], 'goes on to');

    it('sits halfway along when nothing is there', () => {
        const label = routed([top, bottom, named]).get('line')!.label!;

        expect(label).toEqual({ x: 50, y: 230 });
    });

    it('moves off an item that is', () => {
        const label = routed([top, bottom, beside, named]).get('line')!.label!;
        const width = labelWidth(named);

        const covers =
            label.x + width / 2 > beside.x &&
            label.x - width / 2 < beside.x + beside.width &&
            label.y + LABEL_HEIGHT / 2 > beside.y &&
            label.y - LABEL_HEIGHT / 2 < beside.y + beside.height;

        expect(covers).toBe(false);
    });
});

describe('a big board', () => {
    it('is routed quickly', () => {
        // A grid of 400 cards, each joined to the next and to the one below,
        // some of the lines running past the cards between
        const items: Item[] = [];

        for (let row = 0; row < 20; row++) {
            for (let column = 0; column < 20; column++) {
                items.push(card(`c${row}-${column}`, column * 240, row * 160));
            }
        }

        for (let row = 0; row < 20; row++) {
            for (let column = 0; column < 20; column++) {
                if (column + 2 < 20) {
                    items.push(
                        arrow(
                            `h${row}-${column}`,
                            `c${row}-${column}`,
                            `c${row}-${column + 2}`,
                            ['right', 'left'],
                        ),
                    );
                }

                if (row + 1 < 20) {
                    items.push(
                        arrow(
                            `v${row}-${column}`,
                            `c${row}-${column}`,
                            `c${row + 1}-${column}`,
                        ),
                    );
                }
            }
        }

        const started = performance.now();
        const routes = routed(items);

        expect(routes.size).toBe(360 + 380);
        // Generous, for a slow machine: it takes a fraction of this
        expect(performance.now() - started).toBeLessThan(2000);
    });
});
