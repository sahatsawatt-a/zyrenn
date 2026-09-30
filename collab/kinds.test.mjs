// node --test collab/*.test.mjs
import assert from 'node:assert/strict';
import { test } from 'node:test';
import * as Y from 'yjs';
import { documentKinds, orderedItems } from './kinds.mjs';

const { boards, notes } = documentKinds;

const sticky = (id, text) => ({
    id,
    kind: 'sticky',
    text,
    x: 0,
    y: 0,
    width: 100,
    height: 100,
});

void test('a board opens with its items in order, and reads back the same', () => {
    const document = new Y.Doc();
    const items = [sticky('a', 'First'), sticky('b', 'Second')];

    boards.seed(document, { items, title: 'Map' });

    assert.deepEqual(boards.read(document), { items, title: 'Map' });
});

void test('an item left out of the order is still on the board, on top', () => {
    const document = new Y.Doc();
    boards.seed(document, { items: [sticky('a', 'First')], title: '' });

    // Someone else's new item, added while the order was being rewritten
    document.getMap('items').set('z', sticky('z', 'Theirs'));

    assert.deepEqual(
        orderedItems(document).map((item) => item.id),
        ['a', 'z'],
    );
});

void test('two people changing two items keep both changes', () => {
    const ours = new Y.Doc();
    boards.seed(ours, {
        items: [sticky('a', 'A'), sticky('b', 'B')],
        title: '',
    });
    const theirs = new Y.Doc();
    Y.applyUpdate(theirs, Y.encodeStateAsUpdate(ours));

    ours.getMap('items').set('a', sticky('a', 'A, moved by us'));
    theirs.getMap('items').set('b', sticky('b', 'B, moved by them'));
    Y.applyUpdate(ours, Y.encodeStateAsUpdate(theirs));
    Y.applyUpdate(theirs, Y.encodeStateAsUpdate(ours));

    for (const doc of [ours, theirs]) {
        assert.deepEqual(
            orderedItems(doc).map((item) => item.text),
            ['A, moved by us', 'B, moved by them'],
        );
    }
});

void test('a change from elsewhere replaces the items and the title', () => {
    const document = new Y.Doc();
    boards.seed(document, { items: [sticky('a', 'Old')], title: 'Old' });

    boards.replace(document, { items: [sticky('n', 'New')] });
    boards.replace(document, { title: 'Renamed' });

    assert.deepEqual(boards.read(document), {
        items: [sticky('n', 'New')],
        title: 'Renamed',
    });
});

void test('a note keeps its title and width beside the body', () => {
    const document = new Y.Doc();
    notes.seed(document, { content: null, title: 'Plan', is_wide: true });

    assert.deepEqual(notes.read(document), {
        content: { type: 'doc', content: [] },
        title: 'Plan',
        is_wide: true,
    });
});
