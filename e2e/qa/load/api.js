// Load on the QA API (docs/qa/performance.md), with k6 (~/tools, the official release):
//   ~/tools/k6-v2.4.0/k6 run -e TOKEN=<API_SITE_TOKEN of backend/.env.qa> -e CACHE=on|off qa/load/api.js
// Four endpoints the website asks most, one after the other, each at RATE requests a second for DURATION, as the
// website's server asks (its token). CACHE=off sends "X-Raqam-Cache: off": each request is answered from an empty
// cache of its own (QUERY_REPORT, on in the QA server), the cost of a first visit. The goal: p95 under 300 ms each.
import http from 'k6/http';
import { check } from 'k6';

const BASE = __ENV.BASE || 'http://127.0.0.1:8100/api/v1';
const RATE = Number(__ENV.RATE || 20);
const DURATION = __ENV.DURATION || '30s';
const SECONDS = parseInt(DURATION, 10);
const headers = { Accept: 'application/json', 'X-Raqam-Site-Token': __ENV.TOKEN || '', ...(__ENV.CACHE === 'off' ? { 'X-Raqam-Cache': 'off' } : {}) };
const NAMES = ['home', 'post', 'search', 'currencies'];

// One scenario per endpoint, one after the other (a 5 s gap), so each is measured alone.
export const options = {
    scenarios: Object.fromEntries(NAMES.map((name, i) => [name, {
        executor: 'constant-arrival-rate', rate: RATE, timeUnit: '1s', duration: DURATION, preAllocatedVUs: 20, maxVUs: 100,
        startTime: `${i * (SECONDS + 5)}s`, exec: 'hit', env: { ENDPOINT: name }, tags: { endpoint: name },
    }])),
    thresholds: Object.fromEntries(NAMES.flatMap((name) => [[`http_req_duration{endpoint:${name}}`, ['p(95)<300']], [`http_req_failed{endpoint:${name}}`, ['rate<0.01']]])),
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

/** A real news item, as the list gives it. */
export function setup() {
    const posts = http.get(`${BASE}/posts?kind=news`, { headers }).json('data');
    return { post: `/posts/${posts[0].id}` };
}

export function hit(data) {
    const path = { home: '/home', post: data.post, search: `/search?q=${encodeURIComponent('الذهب')}`, currencies: '/currencies' }[__ENV.ENDPOINT];
    const answer = http.get(`${BASE}${path}`, { headers, tags: { endpoint: __ENV.ENDPOINT } });
    check(answer, { 'status 200': (r) => r.status === 200 });
}

export function handleSummary(summary) {
    const out = __ENV.OUT ? { [__ENV.OUT]: JSON.stringify(summary, null, 2) } : {};
    const rows = NAMES.map((name) => {
        const d = summary.metrics[`http_req_duration{endpoint:${name}}`]?.values ?? {};
        const f = summary.metrics[`http_req_failed{endpoint:${name}}`]?.values ?? {};
        return `${name.padEnd(11)} med ${d.med?.toFixed(1)} ms  p95 ${d['p(95)']?.toFixed(1)} ms  p99 ${d['p(99)']?.toFixed(1)} ms  max ${d.max?.toFixed(1)} ms  failed ${((f.rate ?? 0) * 100).toFixed(2)}%`;
    });
    return { stdout: `\nAPI, cache ${__ENV.CACHE || 'on'}, ${RATE}/s for ${DURATION} each:\n${rows.join('\n')}\n`, ...out };
}
