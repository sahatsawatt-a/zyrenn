import { echo, echoIsConfigured } from '@laravel/echo-vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Whether live updates are reaching the page, and a call each time they
 * come back after a break -- when whatever was missed meanwhile should be
 * fetched again, since a socket message sent while away is gone.
 */
export function useSocketState(onBack: () => void = () => {}) {
    const live = ref(false);

    // Echo over Reverb speaks Pusher's protocol: its connection says how it is
    const connection = (): {
        state: string;
        bind: (event: string, handler: (change: any) => void) => void;
        unbind: (event: string, handler: (change: any) => void) => void;
    } | null => {
        if (!echoIsConfigured()) {
            return null;
        }

        const connector = echo().connector as { pusher?: { connection?: any } };

        return connector.pusher?.connection ?? null;
    };

    let lost = false;

    const changed = ({ current }: { current: string }) => {
        live.value = current === 'connected';

        if (current === 'connected' && lost) {
            lost = false;
            onBack();
        } else if (current !== 'connected' && current !== 'connecting') {
            lost = true;
        }
    };

    onMounted(() => {
        const link = connection();

        if (!link) {
            return;
        }

        live.value = link.state === 'connected';
        link.bind('state_change', changed);
    });

    onBeforeUnmount(() => connection()?.unbind('state_change', changed));

    return { live };
}
