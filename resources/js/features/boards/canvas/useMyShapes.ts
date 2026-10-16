import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { xsrfToken } from '@/lib/utils';
import { destroy, update } from '@/routes/board-shapes';
import { index, store } from '@/routes/boards/shapes';
import type { Item } from '../model/items';
import type { MyShape } from '../model/myShapes';

const headers = () => ({
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-XSRF-TOKEN': xsrfToken(),
});

/** What the server said went wrong, in its words when it gave some. */
async function problem(response: Response, fallback: string) {
    const body = await response.json().catch(() => ({}));
    const first = Object.values(body.errors ?? {})[0] as string[] | undefined;

    return first?.[0] ?? body.message ?? fallback;
}

/**
 * The shapes of one's own a board's library offers -- the user's, and the
 * project's on a project's board -- and keeping, renaming and taking one away.
 * Asked for once, the first time the library is opened.
 */
export function useMyShapes(board: string | null) {
    const shapes = ref<MyShape[]>([]);
    let asked = false;

    async function load(again = false) {
        if (!board || (asked && !again)) {
            return;
        }

        asked = true;
        const response = await fetch(index.url(board), { headers: headers() });

        if (response.ok) {
            shapes.value = (await response.json()).shapes;
        }
    }

    /** Keeps the items as a shape; answers with it, or null when it could not. */
    async function save(
        name: string,
        items: Item[],
        preview: string | null,
        forProject: boolean,
    ): Promise<MyShape | null> {
        if (!board) {
            return null;
        }

        const response = await fetch(store.url(board), {
            method: 'POST',
            headers: headers(),
            body: JSON.stringify({
                name,
                items,
                preview,
                for_project: forProject,
            }),
        });

        if (!response.ok) {
            toast.error(await problem(response, 'It could not be kept.'));

            return null;
        }

        const made: MyShape = await response.json();
        shapes.value = [...shapes.value, made].sort((a, b) =>
            a.name.localeCompare(b.name),
        );

        return made;
    }

    async function rename(shape: MyShape, name: string) {
        const response = await fetch(update.url(shape.ref_id), {
            method: 'PATCH',
            headers: headers(),
            body: JSON.stringify({ name }),
        });

        if (!response.ok) {
            toast.error(await problem(response, 'It could not be renamed.'));

            return;
        }

        shape.name = (await response.json()).name;
    }

    async function remove(shape: MyShape) {
        const response = await fetch(destroy.url(shape.ref_id), {
            method: 'DELETE',
            headers: headers(),
        });

        if (!response.ok) {
            toast.error(await problem(response, 'It could not be deleted.'));

            return;
        }

        shapes.value = shapes.value.filter(
            (one) => one.ref_id !== shape.ref_id,
        );
    }

    return { shapes, load, save, rename, remove };
}
