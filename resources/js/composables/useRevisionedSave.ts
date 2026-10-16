import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import type { Ref } from 'vue';

// A page's copy of something saving itself a moment after each change, one
// save at a time, each save saying which revision it was made from. Someone
// else's newer save is caught (the server answers 409 with what is there
// now): this copy can take theirs or keep its own, and a page with nothing
// of its own unsaved just loads theirs. Whatever is unsaved goes as the page
// does -- leaving it, or the tab closing. A trip (useTripSave) and a
// workflow's draft (useWorkflowSave) save this way; the server's side is
// App\Http\Controllers\Concerns\SavesRevisions.

export type SaveStatus = 'saved' | 'saving' | 'unsaved' | 'error' | 'conflict';

/** What one save came to: saved, refused for an older copy, or failed. */
export type SaveOutcome<Saved, Copy> =
    | { saved: Saved }
    | { stale: Copy }
    | { error: string };

export function useRevisionedSave<
    Copy extends { revision: number },
    Saved extends { revision: number },
>({
    firstRevision,
    editable,
    send,
    saved = () => {},
    apply,
    applied = () => {},
}: {
    firstRevision: number;
    editable: boolean;
    /** Sends this copy, as made from `revision`; keepalive as the page goes. */
    send: (
        revision: number,
        keepalive: boolean,
    ) => Promise<SaveOutcome<Saved, Copy>>;
    /** After a save went through, with what the server answered. */
    saved?: (answer: Saved) => void;
    /** Puts a saved copy in place of this page's, as if it had opened on it. */
    apply: (copy: Copy) => void | Promise<void>;
    /** Once a saved copy has replaced this page's and the page has caught up. */
    applied?: () => void;
}) {
    const status = ref<SaveStatus>('saved');
    const revision = ref(firstRevision);
    const conflict = ref(null) as Ref<Copy | null>;
    const saveError = ref('');
    let timer: ReturnType<typeof setTimeout> | undefined;
    let inFlight: Promise<void> | null = null;
    let changedSince = false;
    // Set while a saved copy replaces this one: that isn't an edit
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
        inFlight = send(revision.value, keepalive)
            .then((outcome) => {
                if ('stale' in outcome) {
                    conflict.value = outcome.stale;
                    status.value = 'conflict';
                } else if ('error' in outcome) {
                    saveError.value = outcome.error;
                    status.value = 'error';
                } else {
                    revision.value = outcome.saved.revision;
                    saved(outcome.saved);
                    status.value = changedSince ? 'unsaved' : 'saved';
                }
            })
            .catch((thrown: unknown) => {
                saveError.value =
                    thrown instanceof Error
                        ? thrown.message
                        : 'It could not be saved.';
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

    /** Something changed here: save it in a moment. */
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

    const replaceWith = async (copy: Copy) => {
        applying = true;
        await apply(copy);
        revision.value = copy.revision;
        status.value = 'saved';
        // What the copy changed is told of after this tick, and an editor
        // may tell of it a moment later still: none of it is an edit
        await nextTick();
        setTimeout(() => (applying = false));
        applied();
    };

    /** Someone else saved first: take their copy, dropping the changes made here. */
    const takeTheirs = async () => {
        if (!conflict.value) {
            return;
        }

        const theirs = conflict.value;
        conflict.value = null;
        await replaceWith(theirs);
    };

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

    /**
     * Someone else saved: whether this page can just load their copy -- it
     * is behind, with nothing of its own unsaved or on its way. A page with
     * changes of its own finds out on its next save, which asks which to keep.
     */
    const canCatchUp = (theirs: number) =>
        theirs > revision.value &&
        status.value === 'saved' &&
        inFlight === null;

    /** Their copy, once loaded -- unless changes were made here meanwhile. */
    const catchUp = (copy: Copy) => {
        if (canCatchUp(copy.revision)) {
            void replaceWith(copy);
        }
    };

    const retry = () => {
        status.value = 'unsaved';
        void flush();
    };

    /** Refused by the server for an older copy, outside a save (publishing). */
    const stale = (copy: Copy) => {
        conflict.value = copy;
        status.value = 'conflict';
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
        takeTheirs,
        keepMine,
        canCatchUp,
        catchUp,
        retry,
        stale,
    };
}
