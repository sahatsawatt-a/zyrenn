import { computed, reactive } from 'vue';
import type { Ref } from 'vue';
import { fromOutside } from '../conversation';
import type { Message, Room } from '../types';

// The faces a room shows: its own at the top, and that of someone outside
// beside what they wrote. Faces that would not load -- an address gone
// stale -- fall back to initials.

export function useFaces(room: () => Room, list: Ref<Message[]>) {
    const broken = reactive(new Set<string>());

    // The face of whoever a linked chat is with: the latest one a message came with
    const latest = computed(() => {
        for (let at = list.value.length - 1; at >= 0; at--) {
            const picture = list.value[at].sender?.picture;

            if (picture && !broken.has(picture)) {
                return picture;
            }
        }

        return null;
    });

    // The room's own face, when a workflow keeps one with it; else that of
    // whoever wrote last
    const roomFace = computed(() =>
        room().picture && !broken.has(room().picture!)
            ? room().picture!
            : room().link
              ? latest.value
              : null,
    );

    // The face beside what someone outside wrote: the room's own -- kept once
    // with the room, by the workflow that looks them up -- or one an older
    // message came with
    const faceOf = (message: Message) => {
        const picture = fromOutside(message)
            ? message.sender?.picture || room().picture
            : null;

        return picture && !broken.has(picture) ? picture : null;
    };

    return { broken, roomFace, faceOf };
}
