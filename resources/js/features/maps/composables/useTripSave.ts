import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { saveTrip, StaleSave } from '@/lib/maps';
import type { TripContent } from '@/features/maps/lib/trip';
import { copy } from '@/features/maps/lib/trip';

// A trip saving itself a moment after each change, one save at a time.
// Someone else's newer save is caught: this copy can take theirs or keep
// its own, and a page with nothing of its own unsaved just loads theirs.
// Whatever is unsaved goes as the page does -- leaving it, or the tab closing.

/** A trip as saved: what the page holds of it. */
export type SavedTrip = Pick<
    StaleSave['current'],
    'title' | 'content' | 'revision'
>;

export type SaveStatus = 'saved' | 'saving' | 'unsaved' | 'error' | 'conflict';

export function useTripSave({
    refId,
    firstRevision,
    title,
    trip,
    editable,
    onApplied,
}: {
    refId: string;
    firstRevision: number;
    title: Ref<string>;
    trip: TripContent;
    editable: boolean;
    /** After a saved copy has replaced this one. */
    onApplied: () => void;
}) {
    const status = ref<SaveStatus>('saved');
    const revision = ref(firstRevision);
    const conflict = ref<StaleSave['current'] | null>(null);
    const saveError = ref('');
    let saveTimer: ReturnType<typeof setTimeout> | undefined;
    let inFlight: Promise<void> | null = null;
    let changedSince = false;
    // Set while the trip is replaced by the server's copy: that isn't an edit
    let applying = false;

    const flush = (keepalive = false): Promise<void> | null => {
        clearTimeout(saveTimer);

        if (!editable || status.value !== 'unsaved') {
            return inFlight;
        }

        if (inFlight) {
            changedSince = true;

            return inFlight;
        }

        status.value = 'saving';
        changedSince = false;
        inFlight = saveTrip(
            refId,
            {
                title: title.value,
                content: JSON.parse(JSON.stringify(trip)),
                revision: revision.value,
            },
            keepalive,
        )
            .then((saved) => {
                revision.value = saved.revision;
                status.value = changedSince ? 'unsaved' : 'saved';
            })
            .catch((thrown: Error) => {
                if (thrown instanceof StaleSave) {
                    conflict.value = thrown.current;
                    status.value = 'conflict';
                } else {
                    saveError.value = thrown.message;
                    status.value = 'error';
                }
            })
            .finally(() => {
                inFlight = null;

                if (status.value === 'unsaved') {
                    saveTimer = setTimeout(() => flush(), 800);
                }
            });

        return inFlight;
    };

    watch(
        [title, () => trip],
        () => {
            if (!editable || applying) {
                return;
            }

            if (status.value !== 'conflict') {
                status.value = 'unsaved';
            }

            changedSince = inFlight !== null;
            clearTimeout(saveTimer);
            saveTimer = setTimeout(() => flush(), 800);
        },
        { deep: true },
    );

    /** Puts a saved copy in place of this one, as if the page had opened on it. */
    const apply = async (saved: SavedTrip) => {
        applying = true;
        title.value = saved.title;
        Object.assign(trip, copy(saved.content) as TripContent);
        revision.value = saved.revision;
        status.value = 'saved';
        await nextTick();
        applying = false;
        onApplied();
    };

    /** Someone else saved first: take their copy, dropping the changes made here. */
    const takeTheirs = async () => {
        if (!conflict.value) {
            return;
        }

        const theirs = conflict.value;
        conflict.value = null;
        await apply(theirs);
    };

    /**
     * Someone else saved (App\Events\TripChanged): whether this page can
     * just load their copy -- it is behind, with nothing of its own unsaved
     * or on its way. A page with changes of its own finds out on its next
     * save, which asks which to keep.
     */
    const canCatchUp = (theirs: number) =>
        theirs > revision.value &&
        status.value === 'saved' &&
        inFlight === null;

    /** Their copy, once loaded -- unless changes were made here meanwhile. */
    const catchUp = (saved: SavedTrip) => {
        if (canCatchUp(saved.revision)) {
            void apply(saved);
        }
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

    const retry = () => {
        status.value = 'unsaved';
        void flush();
    };

    const leaving = () => flush(true);
    onMounted(() => window.addEventListener('pagehide', leaving));
    onBeforeUnmount(() => {
        window.removeEventListener('pagehide', leaving);
        void flush(true);
    });

    return {
        status,
        conflict,
        saveError,
        takeTheirs,
        keepMine,
        canCatchUp,
        catchUp,
        retry,
        flush,
    };
}
