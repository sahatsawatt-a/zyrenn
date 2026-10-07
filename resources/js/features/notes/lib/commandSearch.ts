// Finding a block in the slash menu by what it is called, by the words people
// use for it -- "h1" for Heading 1, "url" for a link -- or by a near miss.
// Spaces, dashes and case don't count, so "heading1" and "todo" find
// "Heading 1" and "To-do List". The best matches come first: the name or a
// word for it exactly, then one that starts that way, then one that has it in
// it, then one a letter off.

export interface Searchable {
    title: string;
    keywords?: string[];
}

/** Letters and digits only, lower case: "To-do List" is "todolist". */
export const squash = (text: string) =>
    text.toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '');

/**
 * How many letters apart two words are, two letters the wrong way round
 * counting as one ("tabel"), up to `most` (then gives up).
 */
const distance = (a: string, b: string, most: number) => {
    if (Math.abs(a.length - b.length) > most) {
        return most + 1;
    }

    const rows = Array.from({ length: a.length + 1 }, (_, i) =>
        Array.from({ length: b.length + 1 }, (_, j) =>
            i === 0 ? j : j === 0 ? i : 0,
        ),
    );

    for (let i = 1; i <= a.length; i++) {
        for (let j = 1; j <= b.length; j++) {
            rows[i][j] = Math.min(
                rows[i - 1][j] + 1,
                rows[i][j - 1] + 1,
                rows[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1),
            );

            if (
                i > 1 &&
                j > 1 &&
                a[i - 1] === b[j - 2] &&
                a[i - 2] === b[j - 1]
            ) {
                rows[i][j] = Math.min(rows[i][j], rows[i - 2][j - 2] + 1);
            }
        }
    }

    return rows[a.length][b.length];
};

/** How well a query finds an item: 0 not at all, higher is better. */
export const scoreOf = (item: Searchable, query: string): number => {
    // Written as Markdown writes it -- "##", "-", "$$" -- before letters count
    const typed = query.trim().toLowerCase();

    if (typed && (item.keywords ?? []).includes(typed)) {
        return 95;
    }

    const wanted = squash(query);

    if (!wanted) {
        return typed ? 0 : 1;
    }

    const title = squash(item.title);
    const words = (item.keywords ?? []).map(squash).filter(Boolean);
    // Each word of the name on its own, so "list" finds "Bullet List"
    const parts = item.title
        .toLowerCase()
        .split(/[^\p{L}\p{N}]+/u)
        .filter(Boolean);

    if (title === wanted) return 100;
    if (words.includes(wanted)) return 90;
    if (title.startsWith(wanted)) return 80;
    if (words.some((word) => word.startsWith(wanted))) return 70;
    if (parts.some((part) => part.startsWith(wanted))) return 60;
    if (title.includes(wanted)) return 40;
    if (words.some((word) => word.includes(wanted))) return 30;

    // A letter off -- "lnik", "tabel" -- once there are enough letters to tell
    if (
        wanted.length >= 4 &&
        [title, ...words].some((word) => distance(word, wanted, 1) <= 1)
    ) {
        return 10;
    }

    return 0;
};

/** The items a query finds, best first; ties keep the menu's own order. */
export const searchCommands = <T extends Searchable>(
    items: T[],
    query: string,
): T[] =>
    items
        .map((item, index) => ({ item, index, score: scoreOf(item, query) }))
        .filter((each) => each.score > 0)
        .sort((a, b) => b.score - a.score || a.index - b.index)
        .map((each) => each.item);
