import { onMounted, shallowRef } from 'vue';
import type { SavedChoice } from '@/lib/maps';
import { listSavedPlaces } from '@/lib/maps';
import type { LocalPlace } from './PlaceSearch.vue';

// The saved places, offered first wherever a place is chosen -- the map's
// own search, a trip's stops and hotels, a table's location cells. Picking
// one keeps its ref_id (savedId), so what is made of it follows the place.

/** Saved places whose name has what is typed, as a search offers them. */
export const matchingSaved = (
    places: SavedChoice[],
    term: string,
): LocalPlace[] => {
    const wanted = term.trim().toLowerCase();

    return places
        .filter((place) => place.name.toLowerCase().includes(wanted))
        .map((place) => ({
            ...place,
            savedId: place.ref_id,
            detail: [place.list, place.address].filter(Boolean).join(' · '),
            color: place.color ?? undefined,
            listIcon: place.icon ?? undefined,
        }));
};

// One page's several searches -- a trip's stops, its hotels, its airports --
// ask once between them
let asked: { at: number; url: string; places: Promise<SavedChoice[]> } | null =
    null;
const FRESH_FOR = 30_000;

/** Every saved place, asked once between the searches and blocks of a page. */
export const savedPlaces = () => {
    const url = window.location.pathname;

    if (!asked || asked.url !== url || Date.now() - asked.at > FRESH_FOR) {
        asked = {
            at: Date.now(),
            url,
            places: listSavedPlaces().catch(() => []),
        };
    }

    return asked.places;
};

/**
 * The saved places matching what is typed, for a PlaceSearch's "local" --
 * loaded as the component that asks opens.
 */
export function useSavedPlaces() {
    const places = shallowRef<SavedChoice[]>([]);

    onMounted(async () => {
        places.value = await savedPlaces();
    });

    return (term: string) => matchingSaved(places.value, term);
}
