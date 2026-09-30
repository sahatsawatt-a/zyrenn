import { HocuspocusProvider } from '@hocuspocus/provider';
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import * as Y from 'yjs';
import { colourFor } from '@/lib/live';

/**
 * Whether notes and boards are edited live here, through the collaboration
 * server (collab/server.mjs). When not, their pages save as they always did.
 */
export const sharingIsOn = (): boolean => usePage().props.collab === true;

/**
 * A note or board opened as a shared document: everyone who has it open edits
 * one Yjs document, which the collaboration server merges, passes around and
 * hands to the app to keep. Closed again when the page goes.
 *
 * @param name e.g. "notes.k3x9m2p7qa"
 */
export function useShared(name: string) {
    const page = usePage();
    const document = new Y.Doc();

    const status = ref<'connecting' | 'connected' | 'disconnected'>(
        'connecting',
    );
    const synced = ref(false);
    const unsynced = ref(0);
    const refused = ref(false);

    const provider = new HocuspocusProvider({
        // The page's own origin, like the Reverb socket: nginx carries /collab through
        url: `${window.location.protocol === 'https:' ? 'wss' : 'ws'}://${window.location.host}/collab`,
        name,
        document,
        // Who you are is the session cookie the socket carries; this only makes the server ask
        token: 'session',
        onStatus: ({ status: next }) => (status.value = next),
        onSynced: ({ state }) => (synced.value = state),
        onUnsyncedChanges: ({ number }) => (unsynced.value = number),
        onAuthenticationFailed: () => (refused.value = true),
    });

    // How you look to the others: your cursor, and its label
    const me = {
        name: page.props.auth.user.name,
        color: colourFor(page.props.auth.user.id),
    };

    /** What the page says about saving, in place of "Saved 2 minutes ago". */
    const label = computed(() => {
        if (refused.value) {
            return 'Can’t open this for editing';
        }

        if (status.value === 'disconnected') {
            return 'Offline — your changes sync when you’re back';
        }

        if (!synced.value) {
            return 'Connecting…';
        }

        return unsynced.value > 0 ? 'Saving…' : 'Saved';
    });

    // Changed here and not yet known to be kept by the app
    let pending = false;

    document.on('update', (_update: Uint8Array, origin: unknown) => {
        if (origin !== provider) {
            pending = true;
        }
    });

    /**
     * Asks the server to hand what was changed here to the app now, rather
     * than after the pause it waits for while people type. Never waits long.
     * `everyone`: what the others typed too, not only what was typed here --
     * for when the app is about to read it, as the PDF printer does.
     */
    const flush = ({ everyone = false } = {}): Promise<void> =>
        new Promise((resolve) => {
            if ((!pending && !everyone) || status.value !== 'connected') {
                resolve();

                return;
            }

            const done = () => {
                clearTimeout(timer);
                provider.off('stateless', heard);
                pending = false;
                resolve();
            };
            const heard = ({ payload }: { payload: string }) =>
                payload === 'flushed' && done();
            const timer = setTimeout(done, 1500);

            provider.on('stateless', heard);
            provider.sendStateless('flush');
        });

    // Leaving for another page: that page reads from the app, so what was just
    // typed -- a new title, say -- is kept first, then the visit goes on
    let leaving = false;

    const removeBefore = router.on('before', (event) => {
        const visit = event.detail.visit;

        if (leaving || !pending || visit.method === 'delete') {
            return;
        }

        leaving = true;
        event.preventDefault();
        void flush().then(() => router.visit(visit.url, visit));
    });

    onBeforeUnmount(() => {
        removeBefore();
        provider.destroy();
        document.destroy();
    });

    return { document, provider, me, synced, label, flush };
}
