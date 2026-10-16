import { watch } from 'vue';
import type { Ref } from 'vue';
import { useRevisionedSave } from '@/composables/useRevisionedSave';
import { saveTrip, StaleSave } from '@/lib/maps';
import type { TripContent } from '@/features/maps/lib/trip';
import { copy } from '@/features/maps/lib/trip';

// A trip saving itself a moment after each change, with a check for someone
// else's newer save (useRevisionedSave).

/** A trip as saved: what the page holds of it. */
export type SavedTrip = Pick<
    StaleSave['current'],
    'title' | 'content' | 'revision'
>;

export type { SaveStatus } from '@/composables/useRevisionedSave';

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
    const saving = useRevisionedSave<
        SavedTrip & { edited_by?: string | null },
        { revision: number }
    >({
        firstRevision,
        editable,
        send: (revision, keepalive) =>
            saveTrip(
                refId,
                {
                    title: title.value,
                    content: JSON.parse(JSON.stringify(trip)),
                    revision,
                },
                keepalive,
            )
                .then((saved) => ({ saved }))
                .catch((thrown: Error) =>
                    thrown instanceof StaleSave
                        ? { stale: thrown.current }
                        : { error: thrown.message },
                ),
        apply: (saved) => {
            title.value = saved.title;
            Object.assign(trip, copy(saved.content) as TripContent);
        },
        applied: onApplied,
    });

    watch([title, () => trip], saving.changed, { deep: true });

    return {
        status: saving.status,
        conflict: saving.conflict,
        saveError: saving.saveError,
        takeTheirs: saving.takeTheirs,
        keepMine: saving.keepMine,
        canCatchUp: saving.canCatchUp,
        catchUp: (saved: SavedTrip) => saving.catchUp(saved),
        retry: saving.retry,
        flush: saving.flush,
    };
}
