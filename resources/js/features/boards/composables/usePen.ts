import type Konva from 'konva';
import type { ComputedRef, Ref } from 'vue';
import { computed, ref } from 'vue';
import { useEventListener } from '@vueuse/core';
import type { Item, ItemKind, Tool } from './items';
import type { Segment } from './pathData';
import { mapPoints, normalisePath, parsePath, pathString } from './pathData';

/** A point a path passes through, and the handles that bend it in and out. */
export type Anchor = {
    x: number;
    y: number;
    inX?: number;
    inY?: number;
    outX?: number;
    outY?: number;
};

/** One unbroken run of a path: its anchors, and whether it closes. */
export type Run = { anchors: Anchor[]; closed: boolean };

/** What the pointer is holding while a path is drawn or edited. */
type Grip = { run: number; anchor: number; part: 'point' | 'in' | 'out' };

type Pen = {
    board: {
        items: Ref<Item[]>;
        byId: ComputedRef<Map<string, Item>>;
        select: (ids: string[]) => void;
        add: (item: Item, options?: { keepSelection?: boolean }) => void;
        commit: () => void;
        makeItem: (kind: ItemKind, x: number, y: number) => Item;
    };
    camera: {
        scale: Ref<number>;
        toBoard: (point: { x: number; y: number }) => { x: number; y: number };
    };
    stage: () => Konva.Stage | null | undefined;
    tool: Ref<Tool>;
    fill: Ref<string>;
    stroke: Ref<string>;
};

/** A path's runs as anchors, in board units, from its segments. */
export function runsOf(segments: Segment[]): Run[] {
    const runs = readRuns(segments);

    // A handle lying on its own point bends nothing: that point is a corner
    for (const { anchors } of runs) {
        for (const anchor of anchors) {
            if (anchor.inX === anchor.x && anchor.inY === anchor.y) {
                delete anchor.inX;
                delete anchor.inY;
            }

            if (anchor.outX === anchor.x && anchor.outY === anchor.y) {
                delete anchor.outX;
                delete anchor.outY;
            }
        }
    }

    return runs;
}

function readRuns(segments: Segment[]): Run[] {
    const runs: Run[] = [];
    let run: Run | null = null;

    for (const s of segments) {
        if (s.type === 'M') {
            run = { anchors: [{ x: s.x, y: s.y }], closed: false };
            runs.push(run);
            continue;
        }

        if (!run) {
            continue;
        }

        if (s.type === 'Z') {
            // A close that ends on its first point is that point, joined
            const first = run.anchors[0];
            const last = run.anchors[run.anchors.length - 1];

            if (
                run.anchors.length > 1 &&
                last.x === first.x &&
                last.y === first.y
            ) {
                first.inX = last.inX;
                first.inY = last.inY;
                run.anchors.pop();
            }

            run.closed = true;
            run = null;
            continue;
        }

        const previous = run.anchors[run.anchors.length - 1];

        if (s.type === 'L') {
            run.anchors.push({ x: s.x, y: s.y });
        } else if (s.type === 'C') {
            previous.outX = s.x1;
            previous.outY = s.y1;
            run.anchors.push({ x: s.x, y: s.y, inX: s.x2, inY: s.y2 });
        } else {
            // A quadratic curve as the cubic it is
            previous.outX = previous.x + ((s.x1 - previous.x) * 2) / 3;
            previous.outY = previous.y + ((s.y1 - previous.y) * 2) / 3;
            run.anchors.push({
                x: s.x,
                y: s.y,
                inX: s.x + ((s.x1 - s.x) * 2) / 3,
                inY: s.y + ((s.y1 - s.y) * 2) / 3,
            });
        }
    }

    return runs;
}

/** The segments that draw a path's runs. */
export function segmentsOf(runs: Run[]): Segment[] {
    const segments: Segment[] = [];
    const join = (from: Anchor, to: Anchor): Segment =>
        from.outX !== undefined || to.inX !== undefined
            ? {
                  type: 'C',
                  x1: from.outX ?? from.x,
                  y1: from.outY ?? from.y,
                  x2: to.inX ?? to.x,
                  y2: to.inY ?? to.y,
                  x: to.x,
                  y: to.y,
              }
            : { type: 'L', x: to.x, y: to.y };

    for (const run of runs) {
        if (!run.anchors.length) {
            continue;
        }

        segments.push({ type: 'M', x: run.anchors[0].x, y: run.anchors[0].y });

        for (let at = 1; at < run.anchors.length; at++) {
            segments.push(join(run.anchors[at - 1], run.anchors[at]));
        }

        if (run.closed && run.anchors.length > 1) {
            const last = run.anchors[run.anchors.length - 1];
            const first = run.anchors[0];

            if (last.outX !== undefined || first.inX !== undefined) {
                segments.push(join(last, first));
            }

            segments.push({ type: 'Z' });
        }
    }

    return segments;
}

/**
 * Drawing a vector path, and changing one: the pen puts down a point at a
 * click and a curve where the pointer is dragged, closes the path at its
 * first point, and finishes with Enter, Escape or a double-click. A path
 * double-clicked shows its points and handles to drag; Delete takes away
 * the point last touched, and Escape or a click away puts it down.
 */
export function usePen({ board, camera, stage, tool, fill, stroke }: Pen) {
    // What is being drawn, in board units; and the path being changed
    const runs = ref<Run[]>([]);
    const editing = ref<string | null>(null);
    const hover = ref<{ x: number; y: number } | null>(null);
    const grip = ref<Grip | null>(null);
    const touched = ref<Grip | null>(null);
    let changed = false;

    const drawing = computed(() => tool.value === 'path');
    const active = () => drawing.value || editing.value !== null;

    const pointer = () => {
        const position = stage()?.getPointerPosition();

        return position ? camera.toBoard(position) : null;
    };

    // Near enough to catch, whatever the zoom: a few pixels on screen
    const near = (a: { x: number; y: number }, b: { x: number; y: number }) =>
        Math.hypot(a.x - b.x, a.y - b.y) <= 8 / camera.scale.value;

    /**
     * What of the runs is under the point: the nearest point or handle, a
     * point winning over a handle as near.
     */
    const gripAt = (at: { x: number; y: number }): Grip | null => {
        let best: { grip: Grip; distance: number } | null = null;
        const consider = (
            grip: Grip,
            x: number | undefined,
            y: number | undefined,
        ) => {
            if (x === undefined || y === undefined || !near(at, { x, y })) {
                return;
            }

            const distance = Math.hypot(at.x - x, at.y - y);

            if (!best || distance < best.distance) {
                best = { grip, distance };
            }
        };

        for (const [run, { anchors }] of runs.value.entries()) {
            for (const [anchor, a] of anchors.entries()) {
                consider({ run, anchor, part: 'point' }, a.x, a.y);
                consider({ run, anchor, part: 'in' }, a.inX, a.inY);
                consider({ run, anchor, part: 'out' }, a.outX, a.outY);
            }
        }

        return (best as { grip: Grip } | null)?.grip ?? null;
    };

    // ------------------------------------------------------------ Drawing

    const down = () => {
        const at = pointer();

        if (!at) {
            return;
        }

        if (editing.value) {
            grip.value = gripAt(at);
            touched.value = grip.value;

            // A click away from the path puts it down
            if (!grip.value) {
                stopEditing();
            } else {
                board.commit();
            }

            return;
        }

        const run = runs.value[0];

        // Back at the first point: the path closes, and is done
        if (run && run.anchors.length > 1 && near(at, run.anchors[0])) {
            run.closed = true;
            finish();

            return;
        }

        // Clicked again on the last point -- a double-click: the path is done
        if (
            run &&
            run.anchors.length > 1 &&
            near(at, run.anchors[run.anchors.length - 1])
        ) {
            finish();

            return;
        }

        if (!run) {
            runs.value = [{ anchors: [{ ...at }], closed: false }];
        } else {
            run.anchors.push({ ...at });
        }

        grip.value = {
            run: 0,
            anchor: runs.value[0].anchors.length - 1,
            part: 'out',
        };
    };

    const move = () => {
        const at = pointer();

        if (!at) {
            return;
        }

        hover.value = at;

        if (!grip.value) {
            return;
        }

        const anchor = runs.value[grip.value.run]?.anchors[grip.value.anchor];

        if (!anchor) {
            return;
        }

        // A new point barely moved off is still a corner: a hand is not still
        if (!editing.value && !changed && near(at, anchor)) {
            return;
        }

        if (grip.value.part === 'point') {
            // A point carries its handles with it
            const dx = at.x - anchor.x;
            const dy = at.y - anchor.y;

            anchor.x = at.x;
            anchor.y = at.y;

            if (anchor.inX !== undefined) {
                anchor.inX += dx;
                anchor.inY! += dy;
            }

            if (anchor.outX !== undefined) {
                anchor.outX += dx;
                anchor.outY! += dy;
            }
        } else {
            // A handle pulled one way, its partner the other: a smooth curve
            const [mine, other] =
                grip.value.part === 'out'
                    ? (['out', 'in'] as const)
                    : (['in', 'out'] as const);

            anchor[`${mine}X`] = at.x;
            anchor[`${mine}Y`] = at.y;

            if (!editing.value || anchor[`${other}X`] !== undefined) {
                anchor[`${other}X`] = 2 * anchor.x - at.x;
                anchor[`${other}Y`] = 2 * anchor.y - at.y;
            }
        }

        changed = true;

        if (editing.value) {
            writeBack();
        }
    };

    const up = () => {
        const held = grip.value;

        grip.value = null;

        // A click that didn't drag puts down a corner, not a curve
        if (held && !editing.value && !changed) {
            const anchor = runs.value[held.run]?.anchors[held.anchor];

            if (anchor) {
                delete anchor.outX;
                delete anchor.outY;
                delete anchor.inX;
                delete anchor.inY;
            }
        }

        changed = false;
    };

    /** The path drawn so far becomes an item on the board. */
    const finish = () => {
        const [run] = runs.value;

        if (run && run.anchors.length > 1) {
            const { d, box } = normalisePath(segmentsOf(runs.value));
            const item = board.makeItem('path', box.x, box.y);

            Object.assign(item, {
                d,
                width: Math.max(1, box.width),
                height: Math.max(1, box.height),
                viewWidth: Math.max(1, box.width),
                viewHeight: Math.max(1, box.height),
                // Closed, it is a shape and takes the fill; open, a line
                fill: run.closed ? fill.value : 'transparent',
                stroke: stroke.value,
                lineWidth: 3,
            });
            board.add(item);
        }

        runs.value = [];
        hover.value = null;
        tool.value = 'select';
    };

    const cancel = () => {
        runs.value = [];
        hover.value = null;
        grip.value = null;
    };

    // ------------------------------------------------------------ Editing

    /** The path's points, in board units, to drag about. */
    const edit = (id: string): boolean => {
        const item = board.byId.value.get(id);

        // Turned, its points would not sit under the pointer
        if (!item || item.kind !== 'path' || item.locked || item.rotation) {
            return false;
        }

        const scaleX = item.width / Math.max(1, item.viewWidth ?? item.width);
        const scaleY =
            item.height / Math.max(1, item.viewHeight ?? item.height);

        runs.value = runsOf(
            mapPoints(parsePath(item.d ?? ''), (x, y) => ({
                x: item.x + x * scaleX,
                y: item.y + y * scaleY,
            })),
        );
        editing.value = id;
        // Its points, not the box round it, are what is moved now
        board.select([]);

        return true;
    };

    /** The points as dragged, written into the item. */
    const writeBack = () => {
        const item = editing.value ? board.byId.value.get(editing.value) : null;

        if (!item) {
            return;
        }

        const { d, box } = normalisePath(segmentsOf(runs.value));

        Object.assign(item, {
            d,
            x: box.x,
            y: box.y,
            width: Math.max(1, box.width),
            height: Math.max(1, box.height),
            viewWidth: Math.max(1, box.width),
            viewHeight: Math.max(1, box.height),
        });
    };

    const stopEditing = () => {
        editing.value = null;
        runs.value = [];
        touched.value = null;
    };

    /** The point last touched taken away; a run of one point goes too. */
    const removeTouched = () => {
        const at = touched.value;

        if (!at || at.part !== 'point') {
            return;
        }

        board.commit();
        runs.value[at.run].anchors.splice(at.anchor, 1);
        runs.value = runs.value.filter((run) => run.anchors.length > 1);
        touched.value = null;

        if (runs.value.length) {
            writeBack();
        } else {
            stopEditing();
        }
    };

    useEventListener(
        window,
        'keydown',
        (event: KeyboardEvent) => {
            if (
                !active() ||
                (event.target as HTMLElement)?.closest?.(
                    'input, textarea, [contenteditable]',
                )
            ) {
                return;
            }

            if (event.key === 'Enter' || event.key === 'Escape') {
                event.preventDefault();
                event.stopImmediatePropagation();

                if (editing.value) {
                    stopEditing();
                } else if (
                    event.key === 'Escape' &&
                    (runs.value[0]?.anchors.length ?? 0) < 2
                ) {
                    cancel();
                    tool.value = 'select';
                } else {
                    finish();
                }
            } else if (
                editing.value &&
                (event.key === 'Delete' || event.key === 'Backspace')
            ) {
                event.preventDefault();
                event.stopImmediatePropagation();
                removeTouched();
            }
        },
        { capture: true },
    );

    /** What the overlay draws: the path so far, the line to the pointer, the points. */
    const preview = computed(() => {
        if (!runs.value.length) {
            return null;
        }

        const segments = segmentsOf(runs.value);
        const last = runs.value[0].anchors.at(-1);
        const reaching =
            !editing.value && last && hover.value && !runs.value[0].closed
                ? `M${last.x} ${last.y} L${hover.value.x} ${hover.value.y}`
                : '';

        return { d: pathString(segments), reaching, runs: runs.value };
    });

    return {
        active,
        drawing,
        editing,
        preview,
        down,
        move,
        up,
        finish,
        cancel,
        edit,
        stopEditing,
    };
}
