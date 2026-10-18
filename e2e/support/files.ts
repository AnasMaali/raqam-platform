import { deflateSync } from 'node:zlib';

function crc32(buf: Buffer): number {
    let c = ~0;
    for (const byte of buf) {
        c ^= byte;
        for (let k = 0; k < 8; k++) c = c & 1 ? (c >>> 1) ^ 0xedb88320 : c >>> 1;
    }
    return ~c >>> 0;
}

function chunk(type: string, data: Buffer): Buffer {
    const body = Buffer.concat([Buffer.from(type), data]);
    const out = Buffer.alloc(body.length + 8);
    out.writeUInt32BE(data.length, 0);
    body.copy(out, 4);
    out.writeUInt32BE(crc32(body), body.length + 4);
    return out;
}

/** A real, valid PNG of the given size (a colour gradient, so it compresses like a photo would not, but decodes like one). */
export function png(width: number, height: number, tint = [200, 90, 40]): Buffer {
    const header = Buffer.alloc(13);
    header.writeUInt32BE(width, 0);
    header.writeUInt32BE(height, 4);
    header.set([8, 2, 0, 0, 0], 8);   // 8 bit, RGB
    const rows = Buffer.alloc((width * 3 + 1) * height);
    for (let y = 0; y < height; y++) {
        const row = y * (width * 3 + 1);
        for (let x = 0; x < width; x++) {
            rows[row + 1 + x * 3] = (tint[0] + (x * 255) / width) % 256;
            rows[row + 2 + x * 3] = (tint[1] + (y * 255) / height) % 256;
            rows[row + 3 + x * 3] = tint[2];
        }
    }
    return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', header), chunk('IDAT', deflateSync(rows)), chunk('IEND', Buffer.alloc(0))]);
}

export const file = (name: string, mimeType: string, buffer: Buffer) => ({ name, mimeType, buffer });
