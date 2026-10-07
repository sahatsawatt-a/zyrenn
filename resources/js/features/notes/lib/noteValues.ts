// A note's live values, {{ … }}: the note keeps only each formula, and the
// server says what it comes to now (App\Support\Formula\NoteValues). Every
// chip on the page asks through here, so they go in one request, and a change
// to a trip or table they read (ValuesChanged) asks for them all again.
import { reactive, ref, watch } from 'vue';
import { xsrfToken } from '@/lib/utils';
import { values as valuesRoute } from '@/routes/notes';

export interface NoteValue {
    value?: unknown;
    text?: string;
    error?: string;
}

/** The note whose values are asked for; the page showing it sets this. */
export const valuesNote = ref<string | null>(null);

const answers = reactive(new Map<string, NoteValue>());
const wanted = new Set<string>();
let waiting: ReturnType<typeof setTimeout> | null = null;

/** What a formula came to, once the server has said. */
export const valueOf = (expression: string): NoteValue | undefined =>
    answers.get(expression);

/** A chip shows this formula: it is asked for with the others, shortly. */
export function want(expression: string): void {
    wanted.add(expression);

    if (!answers.has(expression)) {
        askSoon();
    }
}

/** Everything shown is asked for again: something it reads changed. */
export function refreshValues(): void {
    askSoon(true);
}

let askAll = false;

function askSoon(all = false): void {
    askAll ||= all;

    if (waiting === null) {
        waiting = setTimeout(() => void ask(), 30);
    }
}

async function ask(): Promise<void> {
    waiting = null;
    const note = valuesNote.value;
    const expressions = [...wanted].filter(
        (expression) => askAll || !answers.has(expression),
    );
    askAll = false;

    if (!note || !expressions.length) {
        return;
    }

    try {
        const response = await fetch(valuesRoute.url(note), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ expressions }),
        });

        if (!response.ok) {
            throw new Error(`The values answered ${response.status}`);
        }

        const { values } = (await response.json()) as {
            values: Record<string, NoteValue>;
        };

        // The page may have moved on to another note meanwhile
        if (note !== valuesNote.value) {
            return;
        }

        for (const expression of expressions) {
            answers.set(
                expression,
                values[expression] ?? { error: 'No answer came back.' },
            );
        }
    } catch {
        for (const expression of expressions) {
            if (!answers.has(expression)) {
                answers.set(expression, {
                    error: 'Couldn’t be worked out just now.',
                });
            }
        }
    }
}

// Another note, other answers
watch(valuesNote, () => {
    answers.clear();
    wanted.clear();
});
