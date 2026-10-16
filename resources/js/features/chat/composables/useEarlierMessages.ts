import { nextTick, ref } from 'vue';
import type { Ref } from 'vue';
import { index as earlierMessages } from '@/routes/chats/messages';
import type { Message } from '../types';

// Scrolling back: the messages before the first shown, put in above it
// without moving what was being read

export function useEarlierMessages({
    room,
    list,
    hasEarlier,
    scroller,
}: {
    room: () => { ref_id: string };
    list: Ref<Message[]>;
    // There is more before the first shown
    hasEarlier: Ref<boolean>;
    scroller: Ref<HTMLElement | null>;
}) {
    const loadingEarlier = ref(false);

    async function loadEarlier() {
        const first = list.value[0];

        if (!first || loadingEarlier.value) {
            return;
        }

        loadingEarlier.value = true;
        const before = scroller.value?.scrollHeight ?? 0;

        try {
            const response = await fetch(
                earlierMessages(room().ref_id, {
                    query: { before: first.id },
                }).url,
                { headers: { Accept: 'application/json' } },
            );
            const body = await response.json();

            list.value = [...body.messages, ...list.value];
            hasEarlier.value = body.hasEarlier;

            // Stay on what was being read, not jump to the top of what came in
            await nextTick();

            if (scroller.value) {
                scroller.value.scrollTop +=
                    scroller.value.scrollHeight - before;
            }
        } finally {
            loadingEarlier.value = false;
        }
    }

    return { loadingEarlier, loadEarlier };
}
