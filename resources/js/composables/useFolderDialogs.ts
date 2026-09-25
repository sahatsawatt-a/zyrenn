import type { VisitOptions } from '@inertiajs/core';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

// Something listed in a folder view: a folder, or a file / note
export type FolderItem<Kind extends string = string> = {
    kind: Kind;
    ref_id: string;
    name: string;
};

type DialogState<Kind extends string> =
    | { type: 'new-folder' }
    | { type: 'rename' | 'move' | 'delete'; target: FolderItem<Kind> };

/**
 * State for the new-folder / rename / move / delete dialogs of a folder view
 * (Drive, Notes), and the visit options that close them on success.
 */
export function useFolderDialogs<Kind extends string>() {
    const dialog = ref<DialogState<Kind> | null>(null);
    const busy = ref(false);

    const target = computed(() =>
        dialog.value && 'target' in dialog.value ? dialog.value.target : null,
    );

    // A v-model:open for the dialog(s) handling these types
    const openFor = (...types: DialogState<Kind>['type'][]) =>
        computed({
            get: () => !!dialog.value && types.includes(dialog.value.type),
            set: (open: boolean) => {
                if (!open) {
                    dialog.value = null;
                }
            },
        });

    const firstError = (errors: Record<string, string>) =>
        toast.error(Object.values(errors)[0] ?? 'Something went wrong.');

    const visitOptions: VisitOptions = {
        preserveScroll: true,
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
        onSuccess: () => (dialog.value = null),
        onError: firstError,
    };

    return {
        dialog,
        target,
        busy,
        nameOpen: openFor('new-folder', 'rename'),
        moveOpen: openFor('move'),
        deleteOpen: openFor('delete'),
        newFolder: () => (dialog.value = { type: 'new-folder' }),
        rename: (item: FolderItem<Kind>) =>
            (dialog.value = { type: 'rename', target: item }),
        move: (item: FolderItem<Kind>) =>
            (dialog.value = { type: 'move', target: item }),
        remove: (item: FolderItem<Kind>) =>
            (dialog.value = { type: 'delete', target: item }),
        visitOptions,
        firstError,
    };
}
