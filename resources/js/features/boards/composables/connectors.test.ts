import { describe, expect, it } from 'vitest';
import {
    attachmentsOf,
    connectorPoints,
    isSwept,
    midpointOf,
} from './connectors';
import { anchorAt } from './geometry';
import type { Item } from './items';
import { makeItem } from './items';

const box = (id: string, x: number, y: number): Item => ({
    ...makeItem('rect', x, y),
    id,
    width: 100,
    height: 60,
});

const curve = (from: Item, to: Item): Item => ({
    ...makeItem('arrow', 0, 0),
    id: 'line',
    routing: 'curved',
    from: { item: from.id, side: null, x: 0, y: 0 },
    to: { item: to.id, side: null, x: 0, y: 0 },
});

describe('a curved connector', () => {
    // A branch of a mind map: up and well off to the side
    const centre = box('centre', 0, 300);
    const branch = box('branch', 600, 0);
    const byId = new Map([centre, branch].map((item) => [item.id, item]));
    const line = curve(centre, branch);
    const points = connectorPoints(line, byId);

    it('is one Bezier: start, two handles, end', () => {
        expect(points).toHaveLength(8);
        expect(isSwept(line, points)).toBe(true);
    });

    it('leaves and arrives along each face, with no backtracking', () => {
        const [x0, y0, x1, y1, x2, y2, x3, y3] = points;

        // Out of the centre's right face, into the branch's left face
        expect(y1).toBe(y0);
        expect(x1).toBeGreaterThan(x0);
        expect(y2).toBe(y3);
        expect(x2).toBeLessThan(x3);
        // Handles reach well past a short stub, so the sweep cannot loop
        expect(x1 - x0).toBeGreaterThan(100);
    });

    it('puts its label on the curve, between the ends', () => {
        const middle = midpointOf(points, true);

        expect(middle.x).toBeCloseTo((points[0] + points[6]) / 2, 0);
        expect(middle.y).toBeCloseTo((points[1] + points[7]) / 2, 0);
    });

    it('falls back to a straight line when neither end has a face', () => {
        const loose: Item = {
            ...line,
            from: { item: null, side: null, x: 0, y: 0 },
            to: { item: null, side: null, x: 50, y: 50 },
        };
        const straight = connectorPoints(loose, byId);

        expect(straight).toHaveLength(4);
        expect(isSwept(loose, straight)).toBe(false);
    });
});

describe('connectors sharing a side', () => {
    const hub = box('hub', 400, 200);
    const high = box('high', 0, 0);
    const low = box('low', 0, 400);
    const line = (id: string, from: Item, to: Item): Item => ({
        ...makeItem('arrow', 0, 0),
        id,
        from: { item: from.id, side: null, x: 0, y: 0 },
        to: { item: to.id, side: null, x: 0, y: 0 },
    });
    const fromHigh = line('a', high, hub);
    const fromLow = line('b', low, hub);
    const items = [hub, high, low, fromHigh, fromLow];
    const byId = new Map(items.map((item) => [item.id, item]));
    const attached = attachmentsOf(items);
    const endOf = (item: Item) =>
        connectorPoints(item, byId, attached).slice(-2);

    it('land at separate points, in the order their far ends lie', () => {
        const [ax, ay] = endOf(fromHigh);
        const [bx, by] = endOf(fromLow);

        // Both on the hub's left face, the one from above above the other
        expect(ax).toBe(400);
        expect(bx).toBe(400);
        expect(ay).toBeLessThan(by);
        expect(ay).toBeCloseTo(200 + 60 / 3);
        expect(by).toBeCloseTo(200 + (60 * 2) / 3);
    });

    it('a connector alone on its side keeps the middle', () => {
        const solo = [hub, high, fromHigh];
        const points = connectorPoints(
            fromHigh,
            new Map(solo.map((item) => [item.id, item])),
            attachmentsOf(solo),
        );

        expect(points.slice(-2)).toEqual([400, 230]);
    });

    it('a diamond keeps its tip, where any other point is off the shape', () => {
        const tip: Item = { ...hub, kind: 'diamond' };
        const onTip = [tip, high, low, fromHigh, fromLow];
        const map = new Map(onTip.map((item) => [item.id, item]));
        const shared = attachmentsOf(onTip);

        expect(connectorPoints(fromHigh, map, shared).slice(-2)).toEqual([
            400, 230,
        ]);
        expect(connectorPoints(fromLow, map, shared).slice(-2)).toEqual([
            400, 230,
        ]);
    });
});

describe('anchors on shapes that do not fill their box', () => {
    it('meet a cloud where it is drawn, not at its box', () => {
        const cloud: Item = { ...box('c', 0, 0), kind: 'cloud', height: 100 };

        expect(anchorAt(cloud, 'bottom').y).toBeCloseTo(78);
        expect(anchorAt(cloud, 'top').y).toBeCloseTo(22);
    });
});
