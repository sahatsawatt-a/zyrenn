import { router, usePage } from '@inertiajs/vue3';
import { echo, echoIsConfigured } from '@laravel/echo-vue';
import { useDebounceFn } from '@vueuse/core';
import { onBeforeUnmount } from 'vue';

// What a space's lists are of, as the server says when one changes (Listed::KINDS)
export type ListKind =
    | 'notes'
    | 'boards'
    | 'tables'
    | 'trips'
    | 'workflows'
    | 'drive'
    | 'tickets'
    | 'product'
    | 'places'
    | 'browser_suites';

/**
 * A page listing things of a space -- the user's own, or the project it is
 * in -- that follows them as someone else, a workflow, a job or MCP makes,
 * changes or deletes one (ListChanged): it asks again for only the props
 * named, a moment after, so a run of changes asks once. What is being typed
 * or dragged on the page stays as it is.
 */
export function useSpaceChanges(
    kinds: ListKind[],
    // The props to ask for again, or what to do instead
    then: string[] | (() => void),
) {
    const page = usePage();
    const project = page.props.project?.ref_id;
    const user = page.props.auth?.user?.id;
    const channel = project
        ? `projects.${project}`
        : user
          ? `App.Models.User.${user}`
          : null;

    if (!channel || typeof window === 'undefined' || !echoIsConfigured()) {
        return;
    }

    // A page on its way out asks for nothing: a reload of the list there
    // would land in the middle of going elsewhere, and undo it
    let leaving = false;
    const leave = () => (leaving = true);
    const offStart = router.on('start', (event) => {
        if (!event.detail.visit.only.length) {
            leave();
        }
    });
    window.addEventListener('beforeunload', leave);

    const again = useDebounceFn(
        () => {
            if (leaving) {
                return;
            }

            return typeof then === 'function'
                ? then()
                : router.reload({ only: then });
        },
        400,
        { maxWait: 2000 },
    );
    const heard = (event: { kind: string }) => {
        if (kinds.includes(event.kind as ListKind)) {
            void again();
        }
    };

    echo().private(channel).listen('.listed.changed', heard);

    // Only this page stops listening: the user's channel carries their chats too
    onBeforeUnmount(() => {
        leave();
        offStart();
        window.removeEventListener('beforeunload', leave);
        echo().private(channel).stopListening('.listed.changed', heard);
    });
}
