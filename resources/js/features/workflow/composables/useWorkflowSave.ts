import { watch } from 'vue';
import type { Ref } from 'vue';
import { useRevisionedSave } from '@/composables/useRevisionedSave';
import type {
    Definition,
    Problem,
    Publishing,
} from '@/features/workflow/types';
import { socketHeaders } from '@/lib/live';
import { xsrfToken } from '@/lib/utils';
import { publish as publishRoute, update } from '@/routes/workflows';

// A workflow's draft saving itself a moment after each change, with a check
// for someone else's newer save (useRevisionedSave), as a trip does. Saving
// is not publishing: what runs on its own changes only on Publish.

export type SavedCopy = {
    title: string;
    definition: Definition;
    revision: number;
    edited_by?: string | null;
};

// The socket id, too, so the others hear of this save and this page doesn't
const headers = () => ({
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-XSRF-TOKEN': xsrfToken(),
    ...socketHeaders(),
});

export function useWorkflowSave({
    refId,
    firstRevision,
    title,
    definition,
    editable,
    publishing,
    problems,
}: {
    refId: string;
    firstRevision: number;
    title: Ref<string>;
    // What the editor holds, as the server keeps it
    definition: () => Definition;
    editable: boolean;
    publishing: Ref<Publishing>;
    problems: Ref<Problem[]>;
}) {
    // How a saved copy is put in place: the page says, each time it asks
    let applyCopy: (copy: SavedCopy) => void = () => {};

    const saving = useRevisionedSave<
        SavedCopy,
        {
            revision: number;
            problems: Problem[];
            published: Publishing['published'];
            unpublished_changes: Publishing['unpublished_changes'];
        }
    >({
        firstRevision,
        editable,
        send: (revision, keepalive) =>
            fetch(update(refId).url, {
                method: 'PATCH',
                headers: headers(),
                keepalive,
                body: JSON.stringify({
                    title: title.value,
                    definition: definition(),
                    revision,
                }),
            })
                .then(async (response) => {
                    const body = await response.json().catch(() => ({}));

                    if (response.status === 409) {
                        return { stale: body.workflow };
                    }

                    if (!response.ok) {
                        return {
                            error:
                                body.errors?.definition?.[0] ??
                                body.message ??
                                'It could not be saved.',
                        };
                    }

                    return { saved: body };
                })
                .catch(() => ({ error: 'The connection was lost.' })),
        saved: (body) => {
            problems.value = body.problems;
            publishing.value = {
                published: body.published,
                unpublished_changes: body.unpublished_changes,
            };
        },
        apply: (copy) => applyCopy(copy),
    });

    watch(title, saving.changed);

    /**
     * Publish what is saved, saving first. Answers with what stands in the
     * way when something does, and nothing when it went out.
     */
    const publish = async (): Promise<Problem[] | string | null> => {
        await saving.flush();

        if (saving.status.value !== 'saved') {
            return 'Save the workflow first.';
        }

        const response = await fetch(publishRoute(refId).url, {
            method: 'POST',
            headers: headers(),
            body: JSON.stringify({ revision: saving.revision.value }),
        });
        const body = await response.json().catch(() => ({}));

        if (response.status === 422 && body.problems) {
            problems.value = body.problems;

            return body.problems;
        }

        if (response.status === 409) {
            saving.stale(body.workflow);

            return body.message;
        }

        if (!response.ok) {
            return body.message ?? 'It could not be published.';
        }

        publishing.value = body;

        return null;
    };

    return {
        status: saving.status,
        revision: saving.revision,
        conflict: saving.conflict,
        saveError: saving.saveError,
        changed: saving.changed,
        flush: saving.flush,
        keepMine: saving.keepMine,
        /** Someone else saved first: take their copy, dropping the changes made here. */
        takeTheirs: (apply: (copy: SavedCopy) => void) => {
            applyCopy = apply;
            void saving.takeTheirs();
        },
        canCatchUp: saving.canCatchUp,
        /** Their copy, once loaded -- unless changes were made here meanwhile. */
        catchUp: (copy: SavedCopy, apply: (copy: SavedCopy) => void) => {
            applyCopy = apply;
            saving.catchUp(copy);
        },
        retry: saving.retry,
        publish,
    };
}
