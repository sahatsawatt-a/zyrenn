// A zip made in the page, of files already compressed -- a PDF, pictures,
// videos -- so each is stored as it is rather than squeezed again. Every
// unzip, the one built into each system included, reads it. Names are kept
// in UTF-8, so a Thai title stays a Thai title.

const table = (() => {
    const rows = new Uint32Array(256);

    for (let n = 0; n < 256; n++) {
        let c = n;

        for (let k = 0; k < 8; k++) {
            c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        }

        rows[n] = c >>> 0;
    }

    return rows;
})();

/** The CRC-32 a zip checks each file by. */
export function crc32(bytes: Uint8Array): number {
    let crc = 0xffffffff;

    for (let i = 0; i < bytes.length; i++) {
        crc = table[(crc ^ bytes[i]) & 0xff] ^ (crc >>> 8);
    }

    return (crc ^ 0xffffffff) >>> 0;
}

/** A date as a zip keeps it: DOS time and date, to two seconds. */
const dos = (at: Date) => ({
    time:
        (at.getHours() << 11) | (at.getMinutes() << 5) | (at.getSeconds() >> 1),
    date:
        ((Math.max(1980, at.getFullYear()) - 1980) << 9) |
        ((at.getMonth() + 1) << 5) |
        at.getDate(),
});

export type ZipEntry = { name: string; data: Blob };

/** The files as one zip, each at its name (folders as "videos/one.mp4"). */
export async function zip(entries: ZipEntry[], at = new Date()): Promise<Blob> {
    const encoder = new TextEncoder();
    const { time, date } = dos(at);
    const parts: BlobPart[] = [];
    const central: Uint8Array<ArrayBuffer>[] = [];
    let offset = 0;

    for (const entry of entries) {
        const name = encoder.encode(entry.name);
        const bytes = new Uint8Array(await entry.data.arrayBuffer());
        const crc = crc32(bytes);

        // The file's own header, then the file as it is
        const local = new DataView(new ArrayBuffer(30));
        local.setUint32(0, 0x04034b50, true);
        local.setUint16(4, 20, true);
        local.setUint16(6, 0x0800, true); // names in UTF-8
        local.setUint16(8, 0, true); // stored, not compressed
        local.setUint16(10, time, true);
        local.setUint16(12, date, true);
        local.setUint32(14, crc, true);
        local.setUint32(18, bytes.length, true);
        local.setUint32(22, bytes.length, true);
        local.setUint16(26, name.length, true);
        local.setUint16(28, 0, true);
        parts.push(local.buffer, name, bytes);

        // Its line in the list at the end
        const line = new DataView(new ArrayBuffer(46));
        line.setUint32(0, 0x02014b50, true);
        line.setUint16(4, 20, true);
        line.setUint16(6, 20, true);
        line.setUint16(8, 0x0800, true);
        line.setUint16(10, 0, true);
        line.setUint16(12, time, true);
        line.setUint16(14, date, true);
        line.setUint32(16, crc, true);
        line.setUint32(20, bytes.length, true);
        line.setUint32(24, bytes.length, true);
        line.setUint16(28, name.length, true);
        line.setUint32(42, offset, true);

        const row = new Uint8Array(46 + name.length);
        row.set(new Uint8Array(line.buffer), 0);
        row.set(name, 46);
        central.push(row);

        offset += 30 + name.length + bytes.length;
    }

    const size = central.reduce((sum, row) => sum + row.length, 0);
    const end = new DataView(new ArrayBuffer(22));
    end.setUint32(0, 0x06054b50, true);
    end.setUint16(8, entries.length, true);
    end.setUint16(10, entries.length, true);
    end.setUint32(12, size, true);
    end.setUint32(16, offset, true);

    return new Blob([...parts, ...central, end.buffer], {
        type: 'application/zip',
    });
}
