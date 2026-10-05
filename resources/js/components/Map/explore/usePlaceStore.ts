import { computed, ref, watch } from 'vue';
import { currentProject } from '@/lib/projects';
import type { Candidate, PlaceInfo, PlaceList, SavedPlace } from '@/lib/maps';
import {
    createList,
    deleteList,
    deletePlace,
    savePlace,
    updateList,
    updatePlace,
} from '@/lib/maps';

// The saved places on the map page, as the page loaded them, kept on the
// server as they change. Each change shows at once and is saved after; one
// that fails says so. Which lists are hidden is the viewer's own choice, kept
// in this browser -- it isn't something to share.

export function usePlaceStore(initial: {
    lists: PlaceList[];
    places: SavedPlace[];
}) {
    const lists = ref<PlaceList[]>([...initial.lists]);
    const places = ref<SavedPlace[]>([...initial.places]);
    const error = ref('');

    const hiddenKey = `zyrenn:maps:hidden:${currentProject()?.ref_id ?? 'own'}`;
    const hidden = ref<string[]>(
        (() => {
            try {
                return JSON.parse(localStorage.getItem(hiddenKey) ?? '[]');
            } catch {
                return [];
            }
        })(),
    );
    watch(hidden, (now) => {
        try {
            localStorage.setItem(hiddenKey, JSON.stringify(now));
        } catch {
            // a private window may refuse; the choice lasts until the page closes
        }
    });

    const visible = (list: string) => !hidden.value.includes(list);
    const toggle = (list: string) => {
        hidden.value = visible(list)
            ? [...hidden.value, list]
            : hidden.value.filter((each) => each !== list);
    };

    const listOf = (place: Pick<SavedPlace, 'list'>) =>
        lists.value.find((list) => list.ref_id === place.list) ??
        lists.value[0];
    const placesIn = (list: string) =>
        places.value.filter((place) => place.list === list);
    const shown = computed(() =>
        places.value.filter((place) => visible(place.list)),
    );

    /** Runs a save, keeping its failure to show. */
    async function run<T>(save: () => Promise<T>): Promise<T | null> {
        error.value = '';

        try {
            return await save();
        } catch (thrown) {
            error.value = (thrown as Error).message;

            return null;
        }
    }

    const addList = (name: string) =>
        run(async () => {
            const { list } = await createList(name);
            lists.value.push(list);

            return list;
        });

    /** A list to save into: the one given, or the first -- made if there is none. */
    const someList = async (list?: string) =>
        list ??
        lists.value[0]?.ref_id ??
        (await addList('Saved'))?.ref_id ??
        null;

    const save = (
        candidate: Candidate,
        list?: string,
        details?: PlaceInfo | null,
    ) =>
        run(async () => {
            const into = await someList(list);

            if (!into) {
                return null;
            }

            const { place } = await savePlace(into, {
                ...candidate,
                details: details ?? null,
            });
            places.value.push(place);

            return place;
        });

    const update = (
        ref: string,
        changes: Partial<
            Pick<SavedPlace, 'name' | 'note' | 'list' | 'details'>
        >,
    ) => {
        const place = places.value.find((each) => each.ref_id === ref);

        if (place) {
            Object.assign(place, changes);
        }

        return run(async () => {
            const { place: saved } = await updatePlace(ref, changes);
            places.value = places.value.map((each) =>
                each.ref_id === ref ? saved : each,
            );

            return saved;
        });
    };

    const remove = (ref: string) => {
        places.value = places.value.filter((each) => each.ref_id !== ref);

        return run(() => deletePlace(ref));
    };

    const renameList = (ref: string, name: string) => {
        const list = lists.value.find((each) => each.ref_id === ref);

        if (list) {
            list.name = name;
        }

        return run(() => updateList(ref, { name }));
    };

    /** A list goes; its places move to the list the server names. */
    const removeList = (ref: string) =>
        run(async () => {
            const { moved_to } = await deleteList(ref);
            lists.value = lists.value.filter((list) => list.ref_id !== ref);
            places.value = moved_to
                ? places.value.map((place) =>
                      place.list === ref ? { ...place, list: moved_to } : place,
                  )
                : places.value.filter((place) => place.list !== ref);
        });

    /** Every saved place as GeoJSON -- [longitude, latitude], the GeoJSON way round. */
    const exportGeoJson = (): GeoJSON.FeatureCollection => ({
        type: 'FeatureCollection',
        features: places.value.map((place) => ({
            type: 'Feature',
            geometry: { type: 'Point', coordinates: [place.lng, place.lat] },
            properties: {
                name: place.name,
                address: place.address,
                note: place.note,
                list: listOf(place)?.name ?? '',
                kind: place.kind,
            },
        })),
    });

    /**
     * Adds the points of a GeoJSON file -- never replaces -- each into the
     * list its `list` property names, made if need be. Lines and areas are
     * counted and left out.
     */
    const importGeoJson = async (
        data: unknown,
        progress: (done: number, of: number) => void,
    ) => {
        const collection = data as GeoJSON.FeatureCollection | GeoJSON.Feature;
        const features =
            collection?.type === 'FeatureCollection'
                ? collection.features
                : collection?.type === 'Feature'
                  ? [collection]
                  : null;

        if (!features) {
            throw new Error('That file is not GeoJSON.');
        }

        const points = features.filter(
            (feature) => feature.geometry?.type === 'Point',
        );
        let added = 0;

        for (const feature of points) {
            const [lng, lat] = (feature.geometry as GeoJSON.Point).coordinates;
            const props = (feature.properties ?? {}) as Record<string, unknown>;
            // Somebody else's file: only text is taken as text
            const text = (...keys: string[]) =>
                keys
                    .map((key) => props[key])
                    .find(
                        (value): value is string => typeof value === 'string',
                    ) ?? '';
            const listName = text('list') || 'Imported';
            const list =
                lists.value.find((each) => each.name === listName)?.ref_id ??
                (await addList(listName))?.ref_id;

            if (
                list &&
                (await save(
                    {
                        name: text('name', 'title') || 'Imported place',
                        address: text('address'),
                        kind: text('kind'),
                        lat,
                        lng,
                    },
                    list,
                ))
            ) {
                if (text('note', 'description')) {
                    await update(places.value.at(-1)!.ref_id, {
                        note: text('note', 'description'),
                    });
                }

                added++;
            }

            progress(added, points.length);
        }

        return { added, skipped: features.length - points.length };
    };

    return {
        lists,
        places,
        shown,
        error,
        visible,
        toggle,
        listOf,
        placesIn,
        addList,
        save,
        update,
        remove,
        renameList,
        removeList,
        exportGeoJson,
        importGeoJson,
    };
}

export type PlaceStore = ReturnType<typeof usePlaceStore>;
