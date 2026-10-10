import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Ref } from 'vue';
import type {
    Definition,
    Problem,
    Publishing,
    SaveStatus,
} from '@/features/workflow/types';
import { socketHeaders } from '@/lib/live';
import { xsrfToken } from '@/lib/utils';
import { publish as publishRoute, update } from '@/routes/workflows';

// A workflow's draft saving itself a moment after each change, one save at
// a time, as a trip does. Someone else's newer save is caught: this copy can
// take theirs or keep its own. Whatever is unsaved goes as the page does.
// Saving is not publishing: what runs on its own changes only on Publish.

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
    const status = ref<SaveStatus>('saved');
    const revision = ref(firstRevision);
    const conflict = ref<SavedCopy | null>(null);
    const saveError = ref('');
    let timer: ReturnType<typeof setTimeout> | undefined;
    let inFlight: Promise<void> | null = null;
    let changedSince = false;
    // Set while the server's copy replaces this one: that isn't an edit
    let applying = false;

    const flush = (keepalive = false): Promise<void> | null => {
        clearTimeout(timer);

        if (!editable || status.value !== 'unsaved') {
            return inFlight;
        }

        if (inFlight) {
            changedSince = true;

            return inFlight;
        }

        status.value = 'saving';
        changedSince = false;
        inFlight = fetch(update(refId).url, {
            method: 'PATCH',
            headers: headers(),
            keepalive,
            body: JSON.stringify({
                title: title.value,
                definition: definition(),
                revision: revision.value,
            }),
        })
            .then(async (response) => {
                const body = await response.json().catch(() => ({}));

                if (response.status === 409) {
                    conflict.value = body.workflow;
                    status.value = 'conflict';

                    return;
                }

                if (!response.ok) {
                    saveError.value =
                        body.errors?.definition?.[0] ??
                        body.message ??
                        'It could not be saved.';
                    status.value = 'error';

                    return;
                }

                revision.value = body.revision;
                problems.value = body.problems;
                publishing.value = {
                    published: body.published,
                    unpublished_changes: body.unpublished_changes,
                };
                status.value = changedSince ? 'unsaved' : 'saved';
            })
            .catch(() => {
                saveError.value = 'The connection was lost.';
                status.value = 'error';
            })
            .finally(() => {
                inFlight = null;

                if (status.value === 'unsaved') {
                    timer = setTimeout(() => flush(), 800);
                }
            });

        return inFlight;
    };

    /** Something in the draft changed: save it in a moment. */
    const changed = () => {
        if (!editable || applying) {
            return;
        }

        if (status.value !== 'conflict') {
            status.value = 'unsaved';
        }

        changedSince = inFlight !== null;
        clearTimeout(timer);
        timer = setTimeout(() => flush(), 800);
    };

    watch(title, changed);

    /** Someone else saved first: save this copy over theirs. */
    const keepMine = () => {
        if (!conflict.value) {
            return;
        }

        revision.value = conflict.value.revision;
        conflict.value = null;
        status.value = 'unsaved';
        void flush();
    };

    /** Someone else saved first: take their copy, dropping the changes made here. */
    const takeTheirs = (apply: (copy: SavedCopy) => void) => {
        if (!conflict.value) {
            return;
        }

        const theirs = conflict.value;
        conflict.value = null;
        applying = true;
        apply(theirs);
        revision.value = theirs.revision;
        status.value = 'saved';
        setTimeout(() => (applying = false));
    };

    /**
     * Someone else saved (App\Events\WorkflowChanged): whether this page can
     * just load their copy -- it is behind, with nothing of its own unsaved
     * or on its way. One with changes of its own finds out on its next save.
     */
    const canCatchUp = (theirs: number) =>
        theirs > revision.value &&
        status.value === 'saved' &&
        inFlight === null;

    /** Their copy, once loaded -- unless changes were made here meanwhile. */
    const catchUp = (copy: SavedCopy, apply: (copy: SavedCopy) => void) => {
        if (!canCatchUp(copy.revision)) {
            return;
        }

        applying = true;
        apply(copy);
        revision.value = copy.revision;
        setTimeout(() => (applying = false));
    };

    const retry = () => {
        status.value = 'unsaved';
        void flush();
    };

    /**
     * Publish what is saved, saving first. Answers with what stands in the
     * way when something does, and nothing when it went out.
     */
    const publish = async (): Promise<Problem[] | string | null> => {
        await flush();

        if (status.value !== 'saved') {
            return 'Save the workflow first.';
        }

        const response = await fetch(publishRoute(refId).url, {
            method: 'POST',
            headers: headers(),
            body: JSON.stringify({ revision: revision.value }),
        });
        const body = await response.json().catch(() => ({}));

        if (response.status === 422 && body.problems) {
            problems.value = body.problems;

            return body.problems;
        }

        if (response.status === 409) {
            conflict.value = body.workflow;
            status.value = 'conflict';

            return body.message;
        }

        if (!response.ok) {
            return body.message ?? 'It could not be published.';
        }

        publishing.value = body;

        return null;
    };

    const leaving = () => flush(true);
    onMounted(() => window.addEventListener('pagehide', leaving));
    onBeforeUnmount(() => {
        window.removeEventListener('pagehide', leaving);
        void flush(true);
    });

    return {
        status,
        revision,
        conflict,
        saveError,
        changed,
        flush,
        keepMine,
        takeTheirs,
        canCatchUp,
        catchUp,
        retry,
        publish,
    };
}
