// Reading boards from somewhere that is not the board page -- a note showing
// one, say. The board's own page holds its items already and needs none of this.
import type { Item } from '@/composables/board/items';
import { owned } from '@/lib/projects';
import { content, pick } from '@/routes/boards';
import { pick as projectPick } from '@/routes/projects/boards';

export type BoardSummary = {
    ref_id: string;
    title: string;
    updated_at: string | null;
};

export type BoardContent = {
    ref_id: string;
    title: string;
    items: Item[];
};

const headers = () => ({
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
});

/** The boards of wherever the page is, newest first, for choosing one. */
export async function listBoards(query = ''): Promise<BoardSummary[]> {
    const response = await fetch(
        owned(pick, projectPick).url({ query: query ? { q: query } : {} }),
        {
            headers: headers(),
        },
    );

    if (!response.ok) {
        throw new Error('Couldn’t load your boards.');
    }

    return (await response.json()).boards;
}

/** What is on one board. */
export async function boardContent(refId: string): Promise<BoardContent> {
    const response = await fetch(content.url(refId), { headers: headers() });

    if (!response.ok) {
        throw new Error(
            response.status === 403 || response.status === 404
                ? 'That board is not there any more.'
                : 'Couldn’t load that board.',
        );
    }

    return await response.json();
}
