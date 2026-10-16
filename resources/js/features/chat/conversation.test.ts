import { describe, expect, it } from 'vitest';
import { layOut, nameOver, onlyEmoji } from './conversation';
import type { Message } from './types';

/** A person's message, so many minutes into a day. */
const said = (
    id: number,
    minutes: number,
    content: string,
    extra: Partial<Message> = {},
): Message => ({
    id,
    role: 'user',
    content,
    created_at: new Date(2026, 9, 1, 9, minutes).toISOString(),
    doc: null,
    error: null,
    author: { id: 1, name: 'Ada' },
    ...extra,
});

const picture = (id: number, minutes: number): Message =>
    said(id, minutes, '', {
        file: {
            ref_id: `f${id}`,
            name: `${id}.jpg`,
            url: `/f/${id}`,
            is_image: true,
            is_video: false,
        },
    });

describe('layOut', () => {
    it('keeps one speaker, close together, in one turn', () => {
        const entries = layOut([said(1, 0, 'Hi'), said(2, 1, 'There')]);

        expect(entries.map((e) => [e.first, e.last])).toEqual([
            [true, false],
            [false, true],
        ]);
    });

    it('starts a new turn after a long gap, or another speaker', () => {
        const entries = layOut([
            said(1, 0, 'Hi'),
            said(2, 30, 'Later'),
            said(3, 31, 'Me', { author: { id: 2, name: 'Bo' } }),
        ]);

        expect(entries.every((e) => e.first && e.last)).toBe(true);
    });

    it('puts the day over only the first of the day', () => {
        const entries = layOut([said(1, 0, 'Hi'), said(2, 1, 'There')]);

        expect(entries[0].day).not.toBeNull();
        expect(entries[1].day).toBeNull();
    });

    it('draws pictures sent together as one album', () => {
        const entries = layOut([picture(1, 0), picture(2, 0), picture(3, 0)]);

        expect(entries).toHaveLength(1);
        expect(entries[0].album?.map((file) => file.ref_id)).toEqual([
            'f1',
            'f2',
            'f3',
        ]);
    });

    it('does not join a picture that comes with words', () => {
        const words = { ...picture(2, 0), content: 'Look' };

        expect(layOut([picture(1, 0), words])).toHaveLength(2);
    });
});

describe('onlyEmoji', () => {
    it('is a few emoji and nothing else', () => {
        expect(onlyEmoji(said(1, 0, '👍'))).toBe(true);
        expect(onlyEmoji(said(1, 0, '👨‍👩‍👧 🎉'))).toBe(true);
        expect(onlyEmoji(said(1, 0, '🎉🎉🎉🎉'))).toBe(false);
        expect(onlyEmoji(said(1, 0, 'ok 👍'))).toBe(false);
        expect(onlyEmoji(said(1, 0, '123'))).toBe(false);
    });
});

describe('nameOver', () => {
    it('names others in a group, and nobody in one’s own room', () => {
        expect(nameOver(said(1, 0, 'Hi'), 'group')).toBe('Ada');
        expect(nameOver(said(1, 0, 'Hi'), 'personal')).toBe('');
    });
});
