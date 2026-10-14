import { linksNote } from '@/features/notes/lib/pageLinks';
import { plotTable } from '@/routes/notes';
import type { Plot, Points } from './plot';

// The columns of a table a graph draws, asked of the server once for each
// table and set of columns, and again when the graph is next written
const asked = new Map<string, Promise<Points[]>>();

const keyOf = (note: string, plot: Plot) =>
    [note, plot.table, plot.across, ...plot.columns].join('\n');

/** Forget what was read, so the table is read again. */
export const forgetPoints = (plot: Plot): void => {
    if (linksNote.value) {
        asked.delete(keyOf(linksNote.value, plot));
    }
};

/**
 * The points of each column a graph names. Why they cannot be had -- no
 * such table, no such column -- is thrown, in the server's words.
 */
export const pointsOf = (plot: Plot): Promise<Points[]> => {
    const note = linksNote.value;

    if (plot.table === null) {
        return Promise.resolve([]);
    }

    if (!note) {
        return Promise.reject(
            new Error('A graph draws a table from inside a note.'),
        );
    }

    const key = keyOf(note, plot);
    let waiting = asked.get(key);

    if (!waiting) {
        waiting = fetch(
            plotTable.url(note, {
                query: { table: plot.table, x: plot.across, y: plot.columns },
            }),
            { headers: { Accept: 'application/json' } },
        ).then(async (response) => {
            const answer = await response.json().catch(() => null);

            if (!response.ok || !answer || answer.problem) {
                throw new Error(
                    answer?.problem ?? 'The table could not be read.',
                );
            }

            return answer.series as Points[];
        });

        // One that failed is asked again next time
        waiting.catch(() => asked.delete(key));
        asked.set(key, waiting);
    }

    return waiting;
};
