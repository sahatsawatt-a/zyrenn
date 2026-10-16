import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Ref } from 'vue';
import type { Message } from '../types';

// Where a room's messages are scrolled to. While the latest is in view the
// page stays with it as messages come and pictures find their size;
// scrolled back, it stays put.

export function useChatScroll({
    list,
    streaming,
    isMine,
}: {
    list: Ref<Message[]>;
    // What the agent has said so far in the answer being written
    streaming: Ref<string | null>;
    isMine: (message: Message) => boolean;
}) {
    const scroller = ref<HTMLElement | null>(null);

    // Whether the latest is in view
    const atLatest = ref(true);
    // Something came while scrolled back
    const fresh = ref(false);

    const toBottom = () =>
        nextTick(() => {
            if (scroller.value) {
                scroller.value.scrollTop = scroller.value.scrollHeight;
            }

            // Taken there on purpose: there, whatever a scroll says a moment later
            atLatest.value = true;
            fresh.value = false;
        });

    // How large the room was when last looked at: a scroll that comes of it
    // changing size -- the window, a phone's keyboard -- is not someone scrolling
    let box = { width: 0, height: 0 };

    function onScroll() {
        const here = scroller.value;

        if (
            here &&
            here.clientWidth === box.width &&
            here.clientHeight === box.height
        ) {
            atLatest.value =
                here.scrollHeight - here.scrollTop - here.clientHeight < 120;

            if (atLatest.value) {
                fresh.value = false;
            }
        }
    }

    // A picture or a film that has just found its size pushes what is under it down
    function settle() {
        if (atLatest.value) {
            void toBottom();
        }
    }

    // The window, or the keyboard on a phone, changing the room's height
    let sized: ResizeObserver | null = null;

    onMounted(() => {
        void toBottom();

        if (scroller.value && typeof ResizeObserver !== 'undefined') {
            sized = new ResizeObserver(() => {
                const here = scroller.value;

                if (here) {
                    box = {
                        width: here.clientWidth,
                        height: here.clientHeight,
                    };
                }

                settle();
            });
            sized.observe(scroller.value);
        }
    });
    onBeforeUnmount(() => sized?.disconnect());

    // Down to what is new as it comes -- one's own always, others' only when the
    // latest was in view -- and never when earlier messages come in at the top.
    // Two sources, not one array of both: a new array each time would count
    // every new list as something new, earlier messages and all.
    watch([() => list.value[list.value.length - 1]?.id, streaming], () => {
        const latest = list.value[list.value.length - 1];

        if (
            atLatest.value ||
            streaming.value !== null ||
            (latest && isMine(latest))
        ) {
            void toBottom();
        } else {
            fresh.value = true;
        }
    });

    return { scroller, atLatest, fresh, toBottom, onScroll, settle };
}
