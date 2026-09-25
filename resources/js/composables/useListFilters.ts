import { router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { computed, nextTick, reactive, watch } from 'vue';

export type ListFilters = {
    q: string;
    sort: string;
    // The page's one filter (e.g. edited within, file type); null = everything
    filter: string | null;
};

/**
 * Search / sort / filter state for a folder view, kept in the URL so a
 * reload or a shared link shows the same list. Typing is debounced; picking
 * a sort or filter applies at once.
 */
export function useListFilters(options: {
    current: () => ListFilters;
    url: () => string;
    // Name of the filter's query parameter, e.g. "edited" or "type"
    filterParam: string;
    defaultSort: string;
    // The folder being browsed, kept while filtering
    folder: () => string | null;
}) {
    const state = reactive<ListFilters>({ ...options.current() });

    const isFiltered = computed(
        () => state.q.trim() !== '' || state.filter !== null,
    );
    const isSearching = computed(() => options.current().q !== '');

    const apply = () => {
        const params: Record<string, string> = {};
        const q = state.q.trim();
        const folder = options.folder();

        if (folder) params.folder = folder;
        if (q) params.q = q;
        if (state.sort !== options.defaultSort) params.sort = state.sort;
        if (state.filter) params[options.filterParam] = state.filter;

        router.get(options.url(), params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const applySoon = useDebounceFn(apply, 250);

    // True while copying the server's filters in, so that isn't sent back as a new visit
    let syncing = false;

    watch(
        () => state.q,
        () => !syncing && void applySoon(),
    );
    watch(
        () => [state.sort, state.filter],
        () => !syncing && apply(),
    );

    // Browsing to another folder brings its own filters from the server
    watch(options.current, async (next) => {
        syncing = true;
        if (next.q !== state.q.trim()) state.q = next.q;
        state.sort = next.sort;
        state.filter = next.filter;
        await nextTick();
        syncing = false;
    });

    const clear = () => {
        state.q = '';
        state.filter = null;
    };

    return { state, isFiltered, isSearching, clear };
}
