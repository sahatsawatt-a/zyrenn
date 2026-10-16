import { usePage } from '@inertiajs/vue3';
import { echo, echoIsConfigured } from '@laravel/echo-vue';
import { onBeforeUnmount, ref, watch } from 'vue';

// One count for the whole app: the sidebar shows it, a room's page sets it as it reads
const unread = ref(0);

// Goes up whenever a project's chat with a customer is written in or changed: its inbox, and the
// count beside it, ask again for what they show
export const inboxChanges = ref(0);

// Goes up whenever any chat of the user's is written in or changed: the pages listing their chats
// ask again for what they show
export const chatChanges = ref(0);

/** Set the count from what the server said, such as after reading a room. */
export const setUnreadChats = (count: number) => (unread.value = count);

/**
 * How many messages from others are yet to be read: what the server said
 * with the page, then going up as messages arrive on the user's own channel
 * -- except in the room the page has open, which reads them as they come.
 */
export function useUnreadChats() {
    const page = usePage();

    watch(
        () => page.props.unreadChats,
        (count) => (unread.value = count ?? 0),
        { immediate: true },
    );

    const user = page.props.auth?.user?.id;
    const channel = `App.Models.User.${user}`;

    if (user && echoIsConfigured()) {
        echo()
            .private(channel)
            .listen(
                '.chat.message',
                (event: { room: string; kind?: string }) => {
                    chatChanges.value++;

                    // A customer's chat is the inbox's to count, by how many are still to be answered
                    if (event.kind === 'customer') {
                        inboxChanges.value++;

                        return;
                    }

                    const open = (
                        page.props.room as { ref_id?: string } | undefined
                    )?.ref_id;

                    if (event.room !== open) {
                        unread.value++;
                    }
                },
            )
            .listen('.chat.room', () => {
                inboxChanges.value++;
                chatChanges.value++;
            });

        onBeforeUnmount(() => echo().leave(channel));
    }

    return unread;
}
