// The collaboration server: keeps each open note (and board) as a shared Yjs
// document, passes every keystroke between the people who have it open, and
// hands the result to Laravel, which stays the one place it is kept.
//
//   browser ──ws /collab──▶ this ──http──▶ Laravel (auth, load, store)
//                             ◀──http /replace── Laravel (MCP and other edits)
//
// Run by the `collab` service in docker-compose.yml. It holds nothing of its
// own: a restart loses at most the last couple of seconds before a store.
import { Server } from '@hocuspocus/server';
import * as Y from 'yjs';
import { documentKinds } from './kinds.mjs';

const PORT = Number(process.env.COLLAB_PORT ?? 1234);
const APP = (process.env.COLLAB_APP_URL ?? 'http://app:8000').replace(
    /\/$/,
    '',
);
const SECRET = process.env.COLLAB_SECRET ?? '';

if (!SECRET) {
    console.error(
        'COLLAB_SECRET is not set; Laravel would refuse every load and store.',
    );
    process.exit(1);
}

/** Which kind of document a name is, e.g. "notes.k3x9m2p7qa" is a note. */
const kindOf = (documentName) => {
    const kind = documentKinds[documentName.split('.')[0]];

    if (!kind) {
        throw new Error(`No such document: ${documentName}`);
    }

    return kind;
};

/** Laravel's side of a document, reached with the shared secret. */
const internal = (documentName, init = {}) =>
    fetch(`${APP}/internal/collab/${encodeURIComponent(documentName)}`, {
        ...init,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Collab-Secret': SECRET,
            ...init.headers,
        },
    });

const readBody = (request) =>
    new Promise((resolve, reject) => {
        let body = '';
        request.on('data', (chunk) => (body += chunk));
        request.on('end', () => resolve(body));
        request.on('error', reject);
    });

/**
 * Hands a document to the app now, rather than after the pause it waits for
 * while people type, and waits (a little) for one already under way.
 */
async function storeNow(documentName) {
    const id = `onStoreDocument-${documentName}`;
    const { debouncer } = server.hocuspocus;

    if (debouncer.isDebounced(id)) {
        await debouncer.executeNow(id);
    }

    for (
        let waited = 0;
        debouncer.isCurrentlyExecuting(id) && waited < 3000;
        waited += 50
    ) {
        await new Promise((resolve) => setTimeout(resolve, 50));
    }
}

const server = new Server({
    port: PORT,
    quiet: true,
    // Stored at most every 2s while people type, and at least every 10s
    debounce: 2000,
    maxDebounce: 10000,

    /**
     * Who is this, and may they open it? Laravel answers from the session the
     * browser's own cookie carries; a viewer can follow along but not write.
     */
    async onAuthenticate({ documentName, requestHeaders, connectionConfig }) {
        kindOf(documentName);

        const response = await fetch(
            `${APP}/collab/auth?document=${encodeURIComponent(documentName)}`,
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    Cookie: requestHeaders.get('cookie') ?? '',
                },
            },
        );

        if (!response.ok) {
            throw new Error(`Not allowed (${response.status})`);
        }

        const { user, read_only: readOnly } = await response.json();

        if (readOnly) {
            connectionConfig.readOnly = true;
        }

        return { user };
    },

    /**
     * Opens a document: the shared state it was left in, or when there is none
     * yet (a new note, or one changed from elsewhere), what Laravel keeps.
     */
    async onLoadDocument({ documentName, document }) {
        const kind = kindOf(documentName);
        const response = await internal(documentName);

        if (!response.ok) {
            throw new Error(
                `Couldn't load ${documentName} (${response.status})`,
            );
        }

        const saved = await response.json();

        if (saved.state) {
            Y.applyUpdate(document, Buffer.from(saved.state, 'base64'));
        } else {
            document.transact(() => kind.seed(document, saved));
        }

        return document;
    },

    /**
     * Hands the document to Laravel: the shared state to open it from next
     * time, and what it says, in the form everything else reads.
     */
    async onStoreDocument({ documentName, document, lastContext }) {
        const kind = kindOf(documentName);

        const response = await internal(documentName, {
            method: 'PUT',
            body: JSON.stringify({
                ...kind.read(document),
                state: Buffer.from(Y.encodeStateAsUpdate(document)).toString(
                    'base64',
                ),
                updated_by: lastContext?.user?.id ?? null,
            }),
        });

        // Deleted while open: there is nothing left to store it in
        if (!response.ok && response.status !== 404) {
            throw new Error(
                `Couldn't store ${documentName} (${response.status})`,
            );
        }
    },

    /**
     * Someone leaving a page asks for what they changed to be kept now rather
     * than after the pause, so the list they are going to shows it.
     */
    async onStateless({ payload, documentName, connection }) {
        if (payload !== 'flush') {
            return;
        }

        try {
            await storeNow(documentName);
        } finally {
            connection.sendStateless('flushed');
        }
    },

    /**
     * What the app asks of an open document, with the shared secret:
     *
     * - /replace: it changed elsewhere (MCP, a rename from the list); the new
     *   version goes to everyone who has it open.
     * - /flush: hand what it holds to the app now, so the app reads it as it is.
     * - /apply: make changes by block or item (NoteBlocks, BoardItems), which
     *   touch only what they name, then hand the result to the app.
     *
     * Each answers whether the document is open here ("live"); one that isn't
     * is the app's to change itself.
     */
    async onRequest({ request, response, instance }) {
        // The same whether asked directly or through nginx's /collab
        const path = new URL(
            request.url ?? '/',
            'http://collab',
        ).pathname.replace(/^\/collab(?=\/)/, '');

        if (path === '/health') {
            response.writeHead(200, { 'Content-Type': 'text/plain' });
            response.end('ok');
            throw null;
        }

        if (
            !['/replace', '/flush', '/apply'].includes(path) ||
            request.method !== 'POST'
        ) {
            return;
        }

        if (request.headers['x-collab-secret'] !== SECRET) {
            response.writeHead(403);
            response.end();
            throw null;
        }

        const { document: documentName, ...given } = JSON.parse(
            await readBody(request),
        );
        const kind = kindOf(documentName);
        const live = instance.documents.has(documentName);
        const answer = { live };

        if (live && path !== '/flush') {
            // Changes made for someone are theirs: they become its last editor
            const connection = await instance.openDirectConnection(
                documentName,
                {
                    system: true,
                    user: given.by ? { id: given.by } : undefined,
                },
            );
            await connection.transact((document) => {
                if (path === '/replace') {
                    kind.replace(document, given);
                } else {
                    answer.missing = kind.edit(document, given.edits ?? []);
                }
            });
            await connection.disconnect();
        }

        // The app reads what it asked for straight after
        if (live && path !== '/replace') {
            await storeNow(documentName);
        }

        response.writeHead(200, { 'Content-Type': 'application/json' });
        response.end(JSON.stringify(answer));
        throw null;
    },
});

await server.listen();
console.log(`collab listening on :${PORT}, storing to ${APP}`);
