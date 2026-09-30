import { usePage } from '@inertiajs/vue3';
import { echo, echoIsConfigured } from '@laravel/echo-vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import type { Member } from '@/lib/live';

/**
 * Being on a note's, board's or table's presence channel while its page is
 * open: who else is there, and what they change, as `on` hears it. The
 * channel follows `name`, so a page that swaps what it shows swaps channels.
 */
export function usePresence(
    name: () => string,
    on: Record<string, (payload: any) => void> = {},
) {
    const page = usePage();
    const members = ref<Member[]>([]);

    // Everyone but me; the same person in two tabs shows once
    const others = computed(() => {
        const me = page.props.auth.user.id;
        const seen = new Set<number>();

        return members.value.filter((member) => {
            if (member.id === me || seen.has(member.id)) {
                return false;
            }

            seen.add(member.id);

            return true;
        });
    });

    let joined: string | null = null;

    const leave = () => {
        if (joined) {
            echo().leave(joined);
            joined = null;
        }

        members.value = [];
    };

    const join = (channel: string) => {
        leave();

        if (!echoIsConfigured()) {
            return;
        }

        joined = channel;

        const presence = echo()
            .join(channel)
            .here((here: Member[]) => (members.value = here))
            .joining((member: Member) => members.value.push(member))
            .leaving((member: Member) => {
                const at = members.value.findIndex(
                    (item) => item.id === member.id,
                );

                if (at !== -1) {
                    members.value.splice(at, 1);
                }
            });

        for (const [event, handler] of Object.entries(on)) {
            presence.listen(`.${event}`, handler);
        }
    };

    watch(name, join, { immediate: true });
    onBeforeUnmount(leave);

    return { others };
}
