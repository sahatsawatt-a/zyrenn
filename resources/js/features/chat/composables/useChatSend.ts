import { ref, shallowRef } from 'vue';
import type { Ref } from 'vue';
import { socketHeaders } from '@/lib/live';
import type { DriveFile } from '@/lib/drive';
import { xsrfToken } from '@/lib/utils';
import { store as sendMessage } from '@/routes/chats/messages';
import type { ChatPlace, Message, Room } from '../types';

// The message being written, and sending it: its words, a file or a place
// to go with them, and an agent's answer as it is written.

export function useChatSend({
    room,
    noting,
    title,
    add,
    moved,
    reload,
    settled,
}: {
    room: () => Room;
    // A note for the project, in a customer's chat, in place of an answer to them
    noting: Ref<boolean>;
    // The room's title: its first message can give it one
    title: Ref<string>;
    add: (message: Message) => void;
    // Where a customer's chat stands has changed
    moved: () => void;
    // The messages as the server keeps them, in place of these
    reload: () => void;
    // Sent or not, writing can go on
    settled: () => void;
}) {
    const draft = ref('');
    // Chosen from this device, to be uploaded -- or one in the Drive already
    const attached = shallowRef<File | DriveFile | null>(null);
    // Or a place, in place of a file: chosen from the saved ones, a search, or where one is
    const placed = shallowRef<ChatPlace | null>(null);
    // What the agent has said so far in the answer being written
    const streaming = ref<string | null>(null);
    const failure = ref<string | null>(null);
    const sending = ref(false);
    let abort: AbortController | null = null;

    async function send() {
        const content = draft.value.trim();
        const file = attached.value;
        const place = placed.value;

        if ((!content && !file && !place) || sending.value) {
            return;
        }

        // A file from this device goes with the words as a form; one in the
        // Drive already is only named
        const form = file instanceof File ? new FormData() : null;
        form?.append('content', content);
        form?.append('upload', file as File);

        if (noting.value) {
            form?.append('internal', '1');
        }

        const named = file && 'ref_id' in file ? file.ref_id : undefined;

        draft.value = '';
        attached.value = null;
        placed.value = null;
        failure.value = null;
        sending.value = true;
        abort = new AbortController();

        try {
            const response = await fetch(sendMessage(room().ref_id).url, {
                method: 'POST',
                signal: abort.signal,
                headers: {
                    ...(form ? {} : { 'Content-Type': 'application/json' }),
                    Accept: 'text/event-stream',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                    // So the others hear it, and this page does not hear it twice
                    ...socketHeaders(),
                },
                body:
                    form ??
                    JSON.stringify({
                        content,
                        file: named,
                        place: place ?? undefined,
                        internal: noting.value || undefined,
                    }),
            });

            if (!response.ok || !response.body) {
                draft.value = content;
                attached.value = file;
                placed.value = place;
                failure.value =
                    response.status === 422
                        ? 'That message cannot be sent: it is empty or too long, or its file is too large.'
                        : response.status === 413
                          ? 'That file is too large to send.'
                          : 'Could not send that.';

                return;
            }

            await read(response.body);
        } catch (error) {
            // Stopping is not a failure; the page just keeps what it had
            if ((error as Error).name !== 'AbortError') {
                failure.value = 'The connection was lost.';
            }
        } finally {
            // The server keeps whatever was written, so the page asks for it rather than guess
            if (streaming.value !== null || failure.value) {
                reload();
            }

            streaming.value = null;
            sending.value = false;
            abort = null;
            settled();
        }
    }

    // Server-sent events: "event: name", "data: json", and a blank line after each
    async function read(body: ReadableStream<Uint8Array>) {
        const reader = body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        for (;;) {
            const { done, value } = await reader.read();

            if (done) {
                return;
            }

            buffer += decoder.decode(value, { stream: true });

            let end: number;

            while ((end = buffer.indexOf('\n\n')) !== -1) {
                const block = buffer.slice(0, end);
                buffer = buffer.slice(end + 2);

                const name = /^event: (\w+)/m.exec(block)?.[1];
                const data = /^data: (.*)$/m.exec(block)?.[1];

                if (name && data) {
                    handle(name, JSON.parse(data));
                }
            }
        }
    }

    function handle(
        name: string,
        data: { message?: Message | null; text?: string; title?: string },
    ) {
        if (name === 'user' && data.message) {
            add(data.message);
            title.value = data.title ?? title.value;
            streaming.value = room().ready ? '' : null;
            // An answer leaves a customer's chat waiting on them
            moved();
        } else if (name === 'delta') {
            streaming.value = (streaming.value ?? '') + (data.text ?? '');
        } else if (name === 'done') {
            if (data.message) {
                add(data.message);
            }

            streaming.value = null;
        } else if (name === 'error') {
            failure.value = (data as { message?: string }).message as string;
        }
    }

    const stop = () => abort?.abort();

    return {
        draft,
        attached,
        placed,
        streaming,
        failure,
        sending,
        send,
        stop,
    };
}
