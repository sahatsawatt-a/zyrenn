import { ref } from 'vue';
import { setUnreadChats } from '@/composables/useUnreadChats';
import { usePresence } from '@/composables/usePresence';
import type { Member } from '@/lib/live';
import { xsrfToken } from '@/lib/utils';
import { read as markRead } from '@/routes/chats';
import type { Message, Room } from '../types';

// What others say in a room, as they say it, and who else is in it now.
// Live in one's own chats too: a workflow can write in them.

export function useRoomLive(
    room: () => Room,
    {
        heard,
        moved,
    }: {
        // A message someone else wrote
        heard: (message: Message) => void;
        // Someone else moved a customer's chat, took it or tagged it -- or a workflow did
        moved: () => void;
    },
) {
    // Read as it arrives, while the page is open. Sent at once and kept alive past
    // the page, so leaving the room straight after does not leave it unread.
    async function readUpTo(id: number) {
        try {
            const response = await fetch(markRead(room().ref_id).url, {
                method: 'POST',
                keepalive: true,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({ message: id }),
            });

            if (response.ok) {
                setUnreadChats((await response.json()).unread);
            }
        } catch {
            // Read again on the next visit
        }
    }

    const presence = usePresence(() => `chats.${room().ref_id}`, {
        'chat.message': (event: { message: Message }) => {
            heard(event.message);
            void readUpTo(event.message.id);
            // What a customer sends opens their chat again
            moved();
        },
        'chat.room': () => moved(),
    });

    // Nobody else is in one's own room
    const others =
        room().kind !== 'personal' ? presence.others : ref<Member[]>([]);

    return { others };
}
