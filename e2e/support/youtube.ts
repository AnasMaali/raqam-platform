import { createServer, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import { png } from './files';

export type FakeVideo = {
    id: string;
    title: string;
    description?: string;
    /** ISO 8601, as the real API writes it. */
    duration?: string;
    privacy?: 'public' | 'private' | 'unlisted';
};

export type FakeYoutube = {
    /** Value for YOUTUBE_API_BASE and YOUTUBE_IMAGE_BASE of the application under test. */
    apiBase: string;
    imageBase: string;
    /** Every request the application made, so a test can say what was (not) asked. */
    requests: { path: string; key: string | undefined }[];
    videos: Map<string, FakeVideo>;
    add(video: FakeVideo): void;
    stop(): Promise<void>;
};

/** A stand-in for the YouTube Data API and the picture server: the application never reaches the real Google in these tests. */
export async function startFakeYoutube(): Promise<FakeYoutube> {
    const videos = new Map<string, FakeVideo>();
    const requests: FakeYoutube['requests'] = [];
    const picture = png(640, 360, [30, 120, 200]);

    const server: Server = createServer((req, res) => {
        const url = new URL(req.url ?? '/', 'http://127.0.0.1');
        requests.push({ path: url.pathname + url.search, key: req.headers['x-goog-api-key'] as string | undefined });

        if (url.pathname === '/api/videos') {
            const ids = (url.searchParams.get('id') ?? '').split(',');
            const items = ids.flatMap((id) => {
                const v = videos.get(id);
                return v
                    ? [{
                        id: v.id,
                        snippet: { title: v.title, description: v.description ?? '', publishedAt: '2026-09-01T10:00:00Z', thumbnails: {} },
                        contentDetails: { duration: v.duration ?? 'PT10M30S' },
                        status: { privacyStatus: v.privacy ?? 'public' },
                    }]
                    : [];
            });
            res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({ items }));
            return;
        }

        const image = url.pathname.match(/^\/img\/([A-Za-z0-9_-]{11})\/(maxresdefault|hqdefault)\.jpg$/);
        if (image && videos.has(image[1])) {
            res.writeHead(200, { 'Content-Type': 'image/jpeg', 'Content-Length': picture.length }).end(picture);
            return;
        }

        res.writeHead(404).end();
    });

    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
    const base = `http://127.0.0.1:${(server.address() as AddressInfo).port}`;

    return {
        apiBase: `${base}/api`,
        imageBase: `${base}/img`,
        requests,
        videos,
        add: (video) => void videos.set(video.id, video),
        stop: () => new Promise((resolve) => server.close(() => resolve())),
    };
}
