// Stand-ins for every outside service the QA environment talks to, on one local port (docs/qa/resilience.md):
// the Bank of Israel, OpenWeather, YouTube, Brevo and Turnstile, answered in the shape of the real services (as the
// e2e fakes in support/ do). Each service can be made to fail on demand, and every request is recorded, so a QA run
// can show that nothing reached the real services.
//
//   node qa/stand-in.mjs [port, 8790]
//
//   curl -X POST 127.0.0.1:8790/_control -d '{"boi":"error"}'   a service answers 503 ("ok", "error" or "hang")
//   curl 127.0.0.1:8790/_control                               the modes and every request so far
//   curl -X POST 127.0.0.1:8790/_control/reset                 everything "ok", the record emptied
//
// It is also an HTTP proxy that refuses everything: started with HTTPS_PROXY/HTTP_PROXY pointing here (and NO_PROXY for
// 127.0.0.1), a process that tries to reach any other host is refused, and the host is recorded under "outside".
import { createServer } from 'node:http';

const port = Number(process.argv[2] ?? 8790);
const services = ['boi', 'ow', 'youtube', 'brevo', 'turnstile'];
const modes = Object.fromEntries(services.map((s) => [s, 'ok']));
let requests = [];
let outside = [];

const rates = { USD: '3.106', GBP: '4.1444', JPY: ['1.9632', '100'], EUR: '3.4822', CHF: '3.7675', JOD: '4.3812', EGP: '0.057' };
// A publication of yesterday afternoon (the bank publishes once a business day).
const published = new Date(Date.now() - 26 * 3600 * 1000).toISOString().replace(/\.\d+Z$/, '.6546389Z');

function serviceOf(path) {
    if (path.startsWith('/boi/')) return 'boi';
    if (path.startsWith('/ow/')) return 'ow';
    if (path.startsWith('/youtube/')) return 'youtube';
    if (path.startsWith('/brevo/')) return 'brevo';
    if (path.startsWith('/turnstile/')) return 'turnstile';
    return null;
}

function answer(service, url, method, body) {
    const path = url.pathname;
    if (service === 'boi' && path === '/boi/PublicApi/GetExchangeRates') {
        const items = Object.entries(rates).map(([key, rate]) => {
            const [value, unit] = Array.isArray(rate) ? rate : [rate, '1'];
            return `{"key":"${key}","currentExchangeRate":${value},"currentChange":0.1,"unit":${unit},"lastUpdate":"${published}"}`;
        });
        return [200, `{"exchangeRates":[${items.join(',')}]}`];
    }
    if (service === 'ow' && (path === '/ow/data/2.5/weather' || path === '/ow/data/2.5/forecast')) {
        const now = Math.floor(Date.now() / 1000);
        const weather = (id) => [{ id, main: 'Clear', description: 'clear', icon: '01d' }];
        if (path.endsWith('/weather')) {
            return [200, JSON.stringify({ weather: weather(800), main: { temp: 21.4, feels_like: 21.4, temp_min: 21.4, temp_max: 21.4, humidity: 45 },
                wind: { speed: 2.5, deg: 270 }, dt: now - 300, name: 'Nablus', cod: 200 })];
        }
        const start = Math.ceil(now / 10800) * 10800;
        const list = Array.from({ length: 40 }, (_, i) => ({ dt: start + i * 10800, main: { temp: 20, temp_min: 14 + (i % 3), temp_max: 22 + (i % 4), humidity: 50 },
            weather: weather(i % 2 ? 801 : 800), wind: { speed: 2, deg: 200 } }));
        return [200, JSON.stringify({ cod: '200', cnt: 40, list })];
    }
    if (service === 'youtube' && path === '/youtube/videos') {
        // Every video asked about is public and playable.
        const items = (url.searchParams.get('id') ?? '').split(',').filter(Boolean).map((id) => ({ id,
            snippet: { title: `Video ${id}`, description: '', publishedAt: '2026-09-01T10:00:00Z', thumbnails: {} },
            contentDetails: { duration: 'PT10M30S' }, status: { privacyStatus: 'public', embeddable: true } }));
        return [200, JSON.stringify({ items })];
    }
    if (service === 'brevo') {
        const p = path.slice('/brevo/v3'.length);
        if (p === '/contacts' && method === 'POST') return [201, '{"id":42}'];
        if (p.startsWith('/contacts/') && method === 'GET') return [404, '{"code":"document_not_found","message":"Contact does not exist"}'];
        if (p.startsWith('/contacts/')) return [204, ''];
        if (p === '/emailCampaigns' && method === 'POST') return [201, '{"id":77}'];
        if (p.startsWith('/emailCampaigns/') && method === 'GET') {
            return [200, JSON.stringify({ id: 77, status: 'draft', statistics: { globalStats: { delivered: 0, uniqueViews: 0, uniqueClicks: 0, unsubscriptions: 0 } } })];
        }
        if (p.startsWith('/emailCampaigns/') || p.startsWith('/webhooks')) return [p.startsWith('/webhooks') && method === 'POST' ? 201 : 204, method === 'POST' ? '{"id":5}' : ''];
        return [404, '{"code":"not_found"}'];
    }
    if (service === 'turnstile' && path === '/turnstile/siteverify') {
        const token = new URLSearchParams(body).get('response') ?? '';
        const [kind, action] = token.split(':');
        return [200, JSON.stringify(kind === 'pass' ? { success: true, action, hostname: '127.0.0.1', 'error-codes': [] } : { success: false, 'error-codes': ['invalid-input-response'] })];
    }
    return [404, '{"message":"not here"}'];
}

const server = createServer((req, res) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
        const body = Buffer.concat(chunks).toString('utf8');
        // A proxy request names the whole address: the process tried to reach a host outside.
        if (/^https?:\/\//.test(req.url ?? '')) {
            outside.push(`${req.method} ${new URL(req.url).host}`);
            return res.writeHead(403).end();
        }
        const url = new URL(req.url ?? '/', 'http://127.0.0.1');
        const json = (status, text) => res.writeHead(status, { 'Content-Type': 'application/json' }).end(text);

        if (url.pathname === '/_control/reset' && req.method === 'POST') {
            services.forEach((s) => (modes[s] = 'ok'));
            requests = [];
            outside = [];
            return json(200, JSON.stringify({ modes }));
        }
        if (url.pathname === '/_control') {
            if (req.method === 'POST') {
                for (const [service, mode] of Object.entries(JSON.parse(body || '{}'))) {
                    if (!services.includes(service) || !['ok', 'error', 'hang'].includes(mode)) return json(422, `{"message":"unknown ${service}=${mode}"}`);
                    modes[service] = mode;
                }
            }
            return json(200, JSON.stringify({ modes, requests, outside }));
        }

        const service = serviceOf(url.pathname);
        requests.push({ at: new Date().toISOString(), service, method: req.method, path: url.pathname, mode: service ? modes[service] : null });
        if (service && modes[service] === 'hang') return; // never answers: the caller's timeout decides
        if (service && modes[service] === 'error') return json(503, '{"message":"unavailable"}');
        const [status, text] = service ? answer(service, url, req.method, body) : [404, '{"message":"not here"}'];
        return json(status, text);
    });
});

// HTTPS through the proxy arrives as CONNECT: refused and recorded the same way.
server.on('connect', (req, socket) => {
    outside.push(`CONNECT ${req.url}`);
    socket.end('HTTP/1.1 403 Forbidden\r\n\r\n');
});

server.listen(port, '127.0.0.1', () => console.log(`stand-in on http://127.0.0.1:${port}`));
