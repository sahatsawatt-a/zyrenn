import type { VisitOptions } from '@inertiajs/core';
import { router, setLayoutProps } from '@inertiajs/vue3';
import { computed, watchEffect } from 'vue';
import { toast } from 'vue-sonner';
import { useDragMove } from '@/composables/useDragMove';
import { useFolderDialogs } from '@/composables/useFolderDialogs';
import type { FolderItem } from '@/composables/useFolderDialogs';
import { canChange } from '@/lib/projects';
import type { RouteDefinition, RouteQueryOptions } from '@/wayfinder';

export type FolderRef = { ref_id: string; name: string };

type ItemRoutes = {
    update: { url: (ref_id: string) => string };
    destroy: { url: (ref_id: string) => string };
};

type FolderPageOptions<ItemKind extends string> = {
    // Shown for the top level: in breadcrumbs, the move dialog and toasts
    rootLabel: string;
    index: (options?: RouteQueryOptions) => RouteDefinition<'get'>;
    state: () => {
        folder: FolderRef | null;
        breadcrumbs: FolderRef[];
        folders: FolderRef[];
    };
    item: {
        kind: ItemKind;
        routes: ItemRoutes;
        // The request field holding the item's name ('name', 'title')
        nameField: string;
    };
    folderRoutes: ItemRoutes & { store: { url: () => string } };
};

/**
 * Everything a folder view (Drive, Notes) shares: breadcrumbs, the
 * new-folder / rename / move / delete dialogs and their requests, and
 * dragging items onto folders.
 */
export function useFolderPage<ItemKind extends string>(
    options: FolderPageOptions<ItemKind>,
) {
    type Kind = ItemKind | 'folder';

    const { rootLabel, index, state, item, folderRoutes } = options;

    const folderHref = (ref_id?: string) =>
        index(ref_id ? { query: { folder: ref_id } } : undefined);

    watchEffect(() => {
        setLayoutProps({
            breadcrumbs: [
                { title: rootLabel, href: folderHref() },
                ...state().breadcrumbs.map((crumb) => ({
                    title: crumb.name,
                    href: folderHref(crumb.ref_id),
                })),
            ],
        });
    });

    const dialogs = useFolderDialogs<Kind>();
    const { target, visitOptions, firstError } = dialogs;

    const folderItem = (folder: FolderRef): FolderItem<Kind> => ({
        kind: 'folder',
        ...folder,
    });

    const routesFor = (target: FolderItem) =>
        target.kind === 'folder' ? folderRoutes : item.routes;

    const submitName = (name: string) => {
        if (!target.value) {
            router.post(
                folderRoutes.store.url(),
                { name, parent: state().folder?.ref_id ?? null },
                visitOptions,
            );

            return;
        }

        const field = target.value.kind === 'folder' ? 'name' : item.nameField;
        router.patch(
            routesFor(target.value).update.url(target.value.ref_id),
            { [field]: name },
            visitOptions,
        );
    };

    const moveItem = (
        moving: FolderItem,
        destination: string | null,
        visit: VisitOptions,
    ) => {
        const field = moving.kind === 'folder' ? 'parent' : 'folder';
        router.patch(
            routesFor(moving).update.url(moving.ref_id),
            { [field]: destination },
            visit,
        );
    };

    const submitMove = (destination: string | null) => {
        if (target.value) {
            moveItem(target.value, destination, visitOptions);
        }
    };

    const submitDelete = () => {
        if (target.value) {
            router.delete(
                routesFor(target.value).destroy.url(target.value.ref_id),
                visitOptions,
            );
        }
    };

    // Up one level from the open folder: its parent, or the top level
    const parent = computed(() => state().breadcrumbs.at(-2) ?? null);

    const folderName = (ref_id: string | null) =>
        ref_id === null
            ? rootLabel
            : (state().folders.find((folder) => folder.ref_id === ref_id)
                  ?.name ??
              state().breadcrumbs.find((crumb) => crumb.ref_id === ref_id)
                  ?.name ??
              'the folder');

    const drag = useDragMove<Kind>((moving, destination) =>
        moveItem(moving, destination, {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(
                    `Moved “${moving.name}” to ${folderName(destination)}`,
                ),
            onError: firstError,
        }),
    );

    return {
        ...dialogs,
        ...drag,
        // A project's viewers can't move things, so nothing picks up
        dragProps: (moving: FolderItem<Kind>) =>
            canChange() ? drag.dragProps(moving) : {},
        folderHref,
        folderItem,
        parent,
        submitName,
        submitMove,
        submitDelete,
    };
}
