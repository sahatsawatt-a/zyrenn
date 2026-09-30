// node --test collab/
import assert from 'node:assert/strict';
import { test } from 'node:test';
import * as Y from 'yjs';
import { readDoc, replaceDoc, writeDoc } from './prosemirror.mjs';

const roundTrip = (doc) => {
    const ydoc = new Y.Doc();
    const fragment = ydoc.getXmlFragment('default');
    writeDoc(doc, fragment);

    return readDoc(fragment);
};

const rich = {
    type: 'doc',
    content: [
        {
            type: 'heading',
            attrs: { level: 2 },
            content: [{ type: 'text', text: 'Plan' }],
        },
        {
            type: 'paragraph',
            content: [
                { type: 'text', text: 'Energy is ' },
                { type: 'text', text: 'bold', marks: [{ type: 'bold' }] },
                {
                    type: 'text',
                    text: ' and linked',
                    marks: [
                        { type: 'bold' },
                        {
                            type: 'link',
                            attrs: {
                                href: 'https://example.com',
                                target: '_blank',
                            },
                        },
                    ],
                },
                { type: 'inlineMath', attrs: { latex: 'x^2' } },
                { type: 'text', text: ' after' },
            ],
        },
        {
            type: 'taskList',
            content: [
                {
                    type: 'taskItem',
                    attrs: { checked: true },
                    content: [
                        {
                            type: 'paragraph',
                            content: [{ type: 'text', text: 'Done' }],
                        },
                    ],
                },
            ],
        },
        {
            type: 'image',
            attrs: {
                src: '/drive/files/k3x9m2p7qa',
                alt: 'chart',
                title: null,
            },
        },
        { type: 'paragraph' },
    ],
};

void test('a document comes back as it went in', () => {
    const back = roundTrip(rich);

    assert.equal(back.type, 'doc');
    assert.deepEqual(back.content[0], rich.content[0]);
    assert.deepEqual(back.content[2], rich.content[2]);

    // Marks keep their attributes; bold without any reads back with an empty set
    const paragraph = back.content[1].content;
    assert.equal(paragraph[0].text, 'Energy is ');
    assert.deepEqual(paragraph[1].marks, [{ type: 'bold', attrs: {} }]);
    assert.deepEqual(
        paragraph[2].marks[1],
        rich.content[1].content[2].marks[1],
    );
    assert.deepEqual(paragraph[3], rich.content[1].content[3]);
    assert.equal(paragraph[4].text, ' after');
});

void test('null attributes are left out, as the editor leaves them', () => {
    const image = roundTrip(rich).content[3];

    assert.deepEqual(image, {
        type: 'image',
        attrs: { src: '/drive/files/k3x9m2p7qa', alt: 'chart' },
    });
});

void test('an empty note is an empty document', () => {
    assert.deepEqual(roundTrip(null), { type: 'doc', content: [] });
});

void test('replacing swaps everything at once', () => {
    const ydoc = new Y.Doc();
    const fragment = ydoc.getXmlFragment('default');
    writeDoc(rich, fragment);

    replaceDoc(
        {
            type: 'doc',
            content: [
                { type: 'paragraph', content: [{ type: 'text', text: 'New' }] },
            ],
        },
        fragment,
    );

    assert.deepEqual(readDoc(fragment).content, [
        { type: 'paragraph', content: [{ type: 'text', text: 'New' }] },
    ]);
});
