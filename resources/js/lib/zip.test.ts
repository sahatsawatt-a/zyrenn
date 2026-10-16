import { describe, expect, it } from 'vitest';
import { crc32, zip } from './zip';

/** The files a zip holds, read back by its list at the end, as an unzip reads it. */
const unzip = (bytes: Uint8Array) => {
    const view = new DataView(bytes.buffer);
    const end = bytes.length - 22;

    expect(view.getUint32(end, true)).toBe(0x06054b50);

    const count = view.getUint16(end + 10, true);
    let at = view.getUint32(end + 16, true);
    const files: Record<string, string> = {};

    for (let n = 0; n < count; n++) {
        expect(view.getUint32(at, true)).toBe(0x02014b50);
        // Named in UTF-8
        expect(view.getUint16(at + 8, true) & 0x0800).toBe(0x0800);

        const crc = view.getUint32(at + 16, true);
        const size = view.getUint32(at + 20, true);
        const nameLength = view.getUint16(at + 28, true);
        const local = view.getUint32(at + 42, true);
        const name = new TextDecoder().decode(
            bytes.subarray(at + 46, at + 46 + nameLength),
        );

        // Where the list says, the file's own header and then the file
        expect(view.getUint32(local, true)).toBe(0x04034b50);
        const start = local + 30 + view.getUint16(local + 26, true);
        const data = bytes.subarray(start, start + size);

        expect(crc32(data)).toBe(crc);
        files[name] = new TextDecoder().decode(data);
        at += 46 + nameLength;
    }

    return files;
};

describe('a zip made in the page', () => {
    it('checks each file by its CRC-32', () => {
        expect(crc32(new TextEncoder().encode('hello'))).toBe(0x3610a686);
    });

    it('is read back whole, folders and UTF-8 names and all', async () => {
        const made = await zip([
            { name: 'Deck.pdf', data: new Blob(['%PDF-1.7 pages']) },
            { name: 'videos/สาธิต.webm', data: new Blob(['film']) },
        ]);

        expect(unzip(new Uint8Array(await made.arrayBuffer()))).toEqual({
            'Deck.pdf': '%PDF-1.7 pages',
            'videos/สาธิต.webm': 'film',
        });
    });
});
