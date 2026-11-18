// Load on the QA website (docs/qa/performance.md), with k6 (~/tools, the official release):
//   ~/tools/k6-v2.4.0/k6 run -e CACHE=on|off qa/load/site.js
// Four pages, one after the other, each at RATE requests a second for DURATION. "Without the cache" is the QA site
// started with RAQAM_QA_PAGE_CACHE_MB=0.0001 (no page fits in the page cache: each visit draws it); CACHE only names
// the run. The pages read the API through the site's own data cache in both.
import http from 'k6/http';
import { check } from 'k6';

const BASE = __ENV.BASE || 'http://localhost:3300';
const API = __ENV.API || 'http://127.0.0.1:8100/api/v1';
const RATE = Number(__ENV.RATE || 10);
const DURATION = __ENV.DURATION || '30s';
const SECONDS = parseInt(DURATION, 10);
const NAMES = ['home', 'post', 'category', 'search'];

export const options = {
    scenarios: Object.fromEntries(NAMES.map((name, i) => [name, {
        executor: 'constant-arrival-rate', rate: RATE, timeUnit: '1s', duration: DURATION, preAllocatedVUs: 20, maxVUs: 100,
        startTime: `${i * (SECONDS + 5)}s`, exec: 'hit', env: { PAGE: name }, tags: { page: name },
    }])),
    // No time goal for the pages (the API's is p95 < 300 ms): p(95)>=0 only makes k6 keep each page's own times.
    thresholds: Object.fromEntries(NAMES.flatMap((name) => [[`http_req_failed{page:${name}}`, ['rate<0.01']], [`http_req_duration{page:${name}}`, ['p(95)>=0']]])),
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

/** A real news item and category, as the API gives them (with the website's token, as its server asks). */
export function setup() {
    const headers = { Accept: 'application/json', 'X-Raqam-Site-Token': __ENV.TOKEN || '' };
    const post = http.get(`${API}/posts?kind=news`, { headers }).json('data')[0];
    const category = http.get(`${API}/categories`, { headers }).json('data')[0];
    return { post: encodeURI(post.path), category: `/category/${encodeURIComponent(category.slug)}` };
}

export function hit(data) {
    const path = { home: '/', post: data.post, category: data.category, search: `/search?q=${encodeURIComponent('الذهب')}` }[__ENV.PAGE];
    const answer = http.get(`${BASE}${path}`, { tags: { page: __ENV.PAGE } });
    check(answer, { 'status 200': (r) => r.status === 200 });
}

export function handleSummary(summary) {
    const out = __ENV.OUT ? { [__ENV.OUT]: JSON.stringify(summary, null, 2) } : {};
    const rows = NAMES.map((name) => {
        const d = summary.metrics[`http_req_duration{page:${name}}`]?.values ?? {};
        const f = summary.metrics[`http_req_failed{page:${name}}`]?.values ?? {};
        return `${name.padEnd(9)} med ${d.med?.toFixed(1)} ms  p95 ${d['p(95)']?.toFixed(1)} ms  p99 ${d['p(99)']?.toFixed(1)} ms  max ${d.max?.toFixed(1)} ms  failed ${((f.rate ?? 0) * 100).toFixed(2)}%`;
    });
    return { stdout: `\nWebsite, page cache ${__ENV.CACHE || 'on'}, ${RATE}/s for ${DURATION} each:\n${rows.join('\n')}\n`, ...out };
}
