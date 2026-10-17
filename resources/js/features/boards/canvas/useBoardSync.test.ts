import { afterEach, beforeAll, describe, expect, it } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import type { EffectScope } from 'vue';
import * as Y from 'yjs';
import { makeItem } from '../model/items';
import type { Item } from '../model/items';
import { useBoard } from './useBoard';
import { sharedItems, useBoardSync } from './useBoardSync';

// Writes go out once a frame; here, at once
beforeAll(() => {
    globalThis.requestAnimationFrame ??= ((done: FrameRequestCallback) =>
        setTimeout(
            () => done(0),
            0,
        ) as unknown as number) as typeof requestAnimationFrame;
    globalThis.cancelAnimationFrame ??= ((id: number) =>
        clearTimeout(id)) as typeof cancelAnimationFrame;
});

let scope: EffectScope | null = null;

afterEach(() => {
    scope?.stop();
    scope = null;
});

const rect = (id: string, text: string, x = 0): Item => ({
    ...makeItem('rect', x, 0),
    id,
    text,
});

/** A shared document holding these items, in this order. */
const documentOf = (items: Item[]) => {
    const document = new Y.Doc();

    document.transact(() => {
        for (const item of items) {
            document.getMap<Item>('items').set(item.id, item);
        }

        document.getArray<string>('order').push(items.map((item) => item.id));
    });

    return document;
};

/**
 * A board opened on what the page was given, its shared document still on
 * its way: the board, the document it will get, and how to say it came.
 */
const opening = (given: Item[]) => {
    const document = new Y.Doc();
    const synced = ref(false);
    let board!: ReturnType<typeof useBoard>;

    scope = effectScope();
    scope.run(() => {
        board = useBoard(given);
        useBoardSync(board, document, synced);
    });

    return {
        board,
        document,
        arrives: async (server: Y.Doc) => {
            // As the server sends it: the document first, and that it is
            // whole only in a later message
            Y.applyUpdate(document, Y.encodeStateAsUpdate(server));
            await new Promise((done) => setTimeout(done, 0));
            synced.value = true;
            await nextTick();
        },
    };
};

const texts = (items: Item[]) =>
    Object.fromEntries(items.map((item) => [item.id, item.text]));

describe('a shared board opened before its document arrives', () => {
    it('keeps what was added, changed and taken away meanwhile', async () => {
        const { board, document, arrives } = opening([
            rect('i1', 'Kept'),
            rect('i2', 'Was'),
            rect('i3', 'Taken away'),
        ]);

        // Done here before the shared board arrives
        board.items.value = [
            ...board.items.value.filter((item) => item.id !== 'i3'),
            rect('i9', 'Added here'),
        ];
        board.items.value.find((item) => item.id === 'i2')!.text =
            'Changed here';

        // ...while someone else changed one, and added their own
        await arrives(
            documentOf([
                rect('i1', 'Kept, and changed by them'),
                rect('i2', 'Was'),
                rect('i3', 'Taken away'),
                rect('i5', 'Theirs'),
            ]),
        );

        const shown = texts(board.items.value);

        expect(shown).toEqual({
            i1: 'Kept, and changed by them',
            i2: 'Changed here',
            i5: 'Theirs',
            i9: 'Added here',
        });
        // ...and the same is sent to everyone
        expect(texts(sharedItems(document))).toEqual(shown);
    });

    it('gives what was added here a new id when someone else has used its one', async () => {
        const { board, document, arrives } = opening([rect('i1', 'Box')]);
        const mine = rect('i2', 'Mine');
        const line: Item = {
            ...makeItem('arrow', 0, 0),
            id: 'i3',
            from: { item: 'i1', side: null, x: 0, y: 0 },
            to: { item: 'i2', side: null, x: 0, y: 0 },
        };

        board.items.value = [...board.items.value, mine, line];

        await arrives(
            documentOf([rect('i1', 'Box'), rect('i2', 'Theirs, made since')]),
        );

        const items = board.items.value;
        const kept = items.find((item) => item.text === 'Mine')!;
        const keptLine = items.find((item) => item.kind === 'arrow')!;

        expect(items.find((item) => item.id === 'i2')?.text).toBe(
            'Theirs, made since',
        );
        expect(kept.id).not.toBe('i2');
        // The line still joins the box to what was added here
        expect(keptLine.from?.item).toBe('i1');
        expect(keptLine.to?.item).toBe(kept.id);
        expect(new Set(items.map((item) => item.id)).size).toBe(items.length);
        expect(
            sharedItems(document)
                .map((item) => item.id)
                .sort(),
        ).toEqual(items.map((item) => item.id).sort());
    });

    it('takes the shared board as it is when nothing was done here', async () => {
        const { board, document, arrives } = opening([rect('i1', 'Old')]);
        const server = documentOf([rect('i1', 'New'), rect('i4', 'More')]);
        const before = Y.encodeStateVector(server);

        await arrives(server);

        expect(texts(board.items.value)).toEqual({ i1: 'New', i4: 'More' });
        // Nothing of the page's own went out over it
        expect(texts(sharedItems(document))).toEqual({ i1: 'New', i4: 'More' });
        expect(Y.encodeStateVector(server)).toEqual(before);
    });
});
