import { createServer, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';

/** One request the stand-in received. */
export type OutsideRequest = { method: string; path: string; headers: Record<string, string | string[] | undefined>; body: string };

export type OutsideAnswers = {
    /** Brevo's record of a contact, by address (absent = Brevo has none). */
    contacts: Record<string, { emailBlacklisted: boolean; listIds: number[]; listUnsubscribed?: number[] }>;
    /** Brevo's campaign, as GET /emailCampaigns/{id} answers. */
    campaign: { status: string; delivered: number; uniqueViews: number; uniqueClicks: number };
    /** Anything else than 200 makes every Brevo call fail with it. */
    brevoStatus: number;
};

export type FakeOutside = {
    /** Values for BREVO_API_BASE and TURNSTILE_VERIFY_URL. */
    brevoBase: string;
    turnstileUrl: string;
    answers: OutsideAnswers;
    requests: OutsideRequest[];
    brevo(): OutsideRequest[];
    stop(): Promise<void>;
};

/**
 * A stand-in for Brevo's API v3 and Cloudflare Turnstile's siteverify, shaped like the real answers. Turnstile: a token
 * "pass:<action>" is solved for that form on raqam.test; "elsewhere:<action>" is solved on another site; anything
 * else is refused. The application under test never reaches either real service.
 */
export async function startFakeOutside(): Promise<FakeOutside> {
    const requests: OutsideRequest[] = [];
    const answers: OutsideAnswers = { contacts: {}, campaign: { status: 'draft', delivered: 0, uniqueViews: 0, uniqueClicks: 0 }, brevoStatus: 200 };

    const server: Server = createServer((req, res) => {
        const chunks: Buffer[] = [];
        req.on('data', (c) => chunks.push(c));
        req.on('end', () => {
            const url = new URL(req.url ?? '/', 'http://127.0.0.1');
            const body = Buffer.concat(chunks).toString('utf8');
            requests.push({ method: req.method ?? 'GET', path: url.pathname + url.search, headers: req.headers, body });
            const json = (status: number, value?: unknown) => res.writeHead(status, { 'Content-Type': 'application/json' }).end(value === undefined ? '' : JSON.stringify(value));

            if (url.pathname === '/turnstile/siteverify') {
                const token = new URLSearchParams(body).get('response') ?? '';
                const [kind, action] = token.split(':');
                if (kind === 'pass' || kind === 'elsewhere') {
                    return json(200, { success: true, action, hostname: kind === 'pass' ? 'raqam.test' : 'evil.test', 'error-codes': [] });
                }
                return json(200, { success: false, 'error-codes': ['invalid-input-response'] });
            }

            if (!url.pathname.startsWith('/brevo/v3/')) return json(404, { code: 'not_found' });
            if (answers.brevoStatus !== 200) return json(answers.brevoStatus, { code: 'unauthorized', message: 'Key not found' });
            const path = url.pathname.slice('/brevo/v3'.length);
            if (path === '/contacts' && req.method === 'POST') return json(201, { id: 42 });
            if (path.startsWith('/contacts/') && req.method === 'GET') {
                const email = decodeURIComponent(path.slice('/contacts/'.length)).toLowerCase();
                const c = answers.contacts[email];
                return c ? json(200, { id: 42, email, attributes: {}, ...c }) : json(404, { code: 'document_not_found', message: 'Contact does not exist' });
            }
            if (path.startsWith('/contacts/')) return json(204);
            if (path === '/emailCampaigns' && req.method === 'POST') return json(201, { id: 77 });
            if (path.startsWith('/emailCampaigns/') && req.method === 'GET') {
                const c = answers.campaign;
                return json(200, { id: 77, status: c.status, sentDate: c.status === 'sent' ? new Date().toISOString() : undefined,
                    statistics: { globalStats: { delivered: c.delivered, uniqueViews: c.uniqueViews, uniqueClicks: c.uniqueClicks, unsubscriptions: 0 } } });
            }
            if (path.startsWith('/emailCampaigns/')) return json(204);
            return json(404, { code: 'not_found' });
        });
    });

    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
    const base = `http://127.0.0.1:${(server.address() as AddressInfo).port}`;

    return {
        brevoBase: `${base}/brevo/v3`,
        turnstileUrl: `${base}/turnstile/siteverify`,
        answers,
        requests,
        brevo: () => requests.filter((r) => r.path.startsWith('/brevo/')),
        stop: () => new Promise((resolve) => server.close(() => resolve())),
    };
}
