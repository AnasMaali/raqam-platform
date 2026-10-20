import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/**
 * A real MP4 made by the real ffmpeg, the way a phone writes one: picture, sound, and a GPS position.
 * The tests fail (they do not skip) on a machine without ffmpeg.
 */
export function mp4(seconds = 3, gps = true): Buffer {
    const dir = mkdtempSync(join(tmpdir(), 'raqam-e2e-clip-'));
    const out = join(dir, 'clip.mp4');
    const meta = gps ? ['-metadata', 'location=+32.2211+035.2544/', '-metadata', 'com.apple.quicktime.location.ISO6709=+32.2211+035.2544/'] : [];
    const result = spawnSync('ffmpeg', ['-nostdin', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=size=96x64:rate=5', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=8000',
        '-t', String(seconds), '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', ...meta, '-y', out], { encoding: 'utf8' });
    if (result.status !== 0) throw new Error(`ffmpeg is needed for these tests (sudo apt install ffmpeg): ${result.stderr}`);
    const bytes = readFileSync(out);
    rmSync(dir, { recursive: true, force: true });
    return bytes;
}

/** What ffprobe says about a stored file (JSON text). */
export function probe(path: string): string {
    const result = spawnSync('ffprobe', ['-v', 'error', '-show_format', '-show_streams', '-print_format', 'json', path], { encoding: 'utf8' });
    if (result.status !== 0) throw new Error(`ffprobe failed: ${result.stderr}`);
    return result.stdout;
}

/** What ffprobe says about these bytes. */
export function probeBytes(bytes: Buffer): string {
    const dir = mkdtempSync(join(tmpdir(), 'raqam-e2e-probe-'));
    try {
        const path = join(dir, 'in.mp4');
        writeFileSync(path, bytes);
        return probe(path);
    } finally {
        rmSync(dir, { recursive: true, force: true });
    }
}
