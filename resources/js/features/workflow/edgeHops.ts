// Where the workflow editor's lines cross, which hops over which: each edge
// says the straight runs of its path as it is drawn, and reads back where it
// hops. Worked out the way the board works it out (lib/lineHops).
import { computed, reactive } from 'vue';
import { lineHops, runsOf, straightOn } from '@/lib/lineHops';
import type { Hops, Run } from '@/lib/lineHops';

/** How big a bump is, in the editor's own units. */
export const HOP_RADIUS = 6;

// Every edge on show, by its id, in the order it was drawn
const shown = reactive(new Map<string, Run[]>());

const hops = computed(() =>
    lineHops(
        [...shown].map(([id, runs]) => ({ id, runs, radius: HOP_RADIUS })),
    ),
);

/** An edge as it is now drawn, to be crossed and to cross the others. */
export const showEdge = (id: string, runs: Run[]) => shown.set(id, runs);

export const hideEdge = (id: string) => shown.delete(id);

export const hopsOf = (id: string): Hops | undefined => hops.value.get(id);

/**
 * A path as Vue Flow draws it -- "M x y L x y Q cx cy x y ..." -- as its
 * commands, each with the point it ends at, and the straight runs among them.
 */
export const readPath = (path: string) => {
    const commands = [...path.matchAll(/([MLQ])([^MLQ]*)/g)].map((match) => ({
        letter: match[1],
        numbers: (match[2].match(/-?\d*\.?\d+(?:e[-+]?\d+)?/gi) ?? []).map(
            Number,
        ),
    }));
    const endOf = (index: number) => ({
        x: commands[index].numbers[commands[index].numbers.length - 2] ?? 0,
        y: commands[index].numbers[commands[index].numbers.length - 1] ?? 0,
    });

    // A line passing straight through a point goes on as one run: the stub
    // out of a step and the line it carries on as are one
    for (let index = commands.length - 2; index >= 1; index--) {
        if (
            commands[index].letter === 'L' &&
            commands[index + 1].letter === 'L' &&
            straightOn(endOf(index - 1), endOf(index), endOf(index + 1))
        ) {
            commands.splice(index, 1);
        }
    }

    const runs: Run[] = [];
    const runOf = new Map<number, number>();
    let at = { x: 0, y: 0 };

    for (const [index, command] of commands.entries()) {
        const end = {
            x: command.numbers[command.numbers.length - 2] ?? at.x,
            y: command.numbers[command.numbers.length - 1] ?? at.y,
        };

        if (command.letter === 'L') {
            runOf.set(index, runs.length);
            runs.push(...runsOf([at.x, at.y, end.x, end.y]));
        }

        at = end;
    }

    return { commands, runs, runOf };
};
