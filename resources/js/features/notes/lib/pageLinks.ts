// Links from a note to another page by its name, [[ … ]]. The note keeps
// which page each points at (its ref_id); what the page is called now, and
// where it is, is asked of the server (App\Support\Note\PageLinks) -- every
// link on the page in one request, as live values are (noteValues).
import { reactive, ref } from 'vue';
import { linkedFrom as linkedFromRoute } from '@/routes/notes';
import { linkedFrom as linkedFromPage } from '@/routes/pages';
import { pick, show } from '@/routes/notes/page-links';
import { xsrfToken } from '@/lib/utils';

export type PageKind = 'note' | 'board' | 'table' | 'trip';

/** What a link holds: the page's kind and ref_id, or only the name it wants. */
export interface PageRef {
    kind: string;
    ref: string;
    title: string;
}

/** What a link points at now. */
export interface Page {
    kind: PageKind;
    ref: string;
    title: string;
    // Nothing to open when there is no such page, or it is not the reader's to see
    url: string | null;
    found: boolean;
}

/** A page to choose, when typing [[. */
export interface PageChoice {
    kind: PageKind;
    ref: string;
    title: string;
    folder: string | null;
}

/** The note whose links are asked about; the page showing it sets this. */
export const linksNote = ref<string | null>(null);

// By ref_id where a link has one; by the name it wants where it has not
const keyOf = (link: PageRef) =>
    link.ref ? `${link.kind}:${link.ref}` : `?${link.kind}:${link.title}`;

const answers = reactive(new Map<string, Page>());
const wanted = new Map<string, PageRef>();
let waiting: ReturnType<typeof setTimeout> | null = null;

/** What a link points at, once the server has said. */
export const pageOf = (link: PageRef): Page | undefined =>
    answers.get(keyOf(link));

/** A chip shows this link: it is asked about with the others, shortly. */
export function want(link: PageRef): void {
    if (answers.has(keyOf(link)) || (!link.ref && !link.title)) {
        return;
    }

    wanted.set(keyOf(link), { ...link });
    waiting ??= setTimeout(() => void ask(), 30);
}

const json = { Accept: 'application/json' };

async function ask(): Promise<void> {
    waiting = null;

    const note = linksNote.value;
    const asked = [...wanted];

    wanted.clear();

    if (!note || !asked.length) {
        return;
    }

    try {
        const response = await fetch(show.url(note), {
            method: 'POST',
            headers: {
                ...json,
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ links: asked.map(([, link]) => link) }),
        });

        if (!response.ok) {
            return;
        }

        const { links } = (await response.json()) as { links: Page[] };

        // Answered in the order asked
        asked.forEach(([key], index) => answers.set(key, links[index]));
    } catch {
        // Shown as the note last knew them
    }
}

/** The pages a few typed letters could mean, among the note's owner's. */
export async function searchPages(letters: string): Promise<PageChoice[]> {
    const note = linksNote.value;

    if (!note) {
        return [];
    }

    const response = await fetch(pick.url(note, { query: { q: letters } }), {
        headers: json,
    });

    return response.ok ? (await response.json()).pages : [];
}

/** A note that links to this one. */
export interface LinkingNote {
    ref_id: string;
    title: string;
    url: string;
    folder: string | null;
}

export async function notesLinkingTo(
    page: string,
    kind: PageKind = 'note',
): Promise<LinkingNote[]> {
    const response = await fetch(
        kind === 'note'
            ? linkedFromRoute.url(page)
            : linkedFromPage.url({ kind, ref: page }),
        { headers: json },
    );

    return response.ok ? (await response.json()).notes : [];
}

/** A kind of page, as a word and as the sign the picker shows for it. */
export const KINDS: Record<PageKind, { name: string; sign: string }> = {
    note: { name: 'Note', sign: '📄' },
    board: { name: 'Board', sign: '🖼️' },
    table: { name: 'Table', sign: '▦' },
    trip: { name: 'Trip', sign: '✈️' },
};
