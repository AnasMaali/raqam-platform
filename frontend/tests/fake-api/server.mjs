// A stand-in for the platform API, for the build and the browser tests in CI: it answers the site's requests from the
// fixtures captured by capture.mjs (made-up demo content) and serves one placeholder picture for every media file.
//   node tests/fake-api/server.mjs [port]   (default 4010; API_BASE_URL=http://127.0.0.1:4010/api/v1)
import { existsSync, readFileSync } from "node:fs";
import { createServer } from "node:http";

const port = Number(process.argv[2] ?? 4010);
const fromFile = (name) => JSON.parse(readFileSync(new URL(`./fixtures/${name}.json`, import.meta.url), "utf8"));

// The tests may replace one answer for a while (PUT /__fixtures/<name>, DELETE /__fixtures/<name>), to see the site
// keep its cached copy until the webhook says it changed. Only this stand-in has it; the platform API has nothing like it.
const replaced = new Map();
const fixture = (name) => replaced.get(name) ?? fromFile(name);

// The newsletter, as the platform answers it: one challenge, the code 123456 confirms, anything else is a wrong code.
const newsletterCode = "123456";
const challenge = { data: { challenge: "fake-challenge", resend_after: 60, expires_in: 1800 } };
const invalid = (code, message) => ({ status: 422, body: { error: { code, message } } });

function newsletter(step, body, request) {
  if (!request.headers["x-raqam-site-token"] || !request.headers["x-raqam-visitor-ip"]) return { status: 403, body: { error: { code: "site_only", message: "للموقع فقط." } } };
  if (body.website) return { status: 202, body: challenge };
  if (step === "subscribe") {
    if (!body.turnstile_token) return invalid("turnstile", "تعذّر التحقق. حاول مرة أخرى.");
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(body.email ?? "")) return { status: 422, body: { error: { code: "invalid", message: "تحقّق من البيانات.", details: { email: ["اكتب بريداً إلكترونياً صحيحاً."] } } } };
    const places = { home: "/", newsletter_page: "/newsletter" };
    if (places[body.placement] !== body.path) return invalid("invalid", "مكان النموذج غير معروف.");

    return { status: 202, body: challenge };
  }
  if (body.challenge !== "fake-challenge") return invalid("expired", "انتهت صلاحية الرمز. اطلب رمزاً جديداً.");
  if (step === "resend") return { status: 202, body: challenge };
  if (body.code !== newsletterCode) return invalid("bad_code", "الرمز غير صحيح.");

  return { status: 200, body: { data: { confirmed: true } } };
}

// How many times the site checked a post's, a currency's or a city's address with HEAD (GET /__requests?path=/api/v1/…).
const asked = new Map();

const readBody = (request) =>
  new Promise((resolve) => {
    let text = "";
    request.on("data", (chunk) => (text += chunk));
    request.on("end", () => resolve(text));
  });

const routes = {
  "/api/v1/site": "site",
  "/api/v1/menus": "menus",
  "/api/v1/currencies": "currencies",
  "/api/v1/currencies/ticker": "ticker",
  "/api/v1/weather/widget": "weather",
  "/api/v1/ads": "ads",
  "/api/v1/home": "home",
  "/api/v1/sections": "sections",
};

// One post as the platform answers its address: 200 with the current slug, 301 to the current address with an old
// one (both name the address in X-Raqam-Path), 410 for the removed id below, 503 for the failing one, 404 otherwise.
const removedPost = 900410;
const failingPost = 900503;
// Any slug is this post's current one, so a test can ask for a page address no earlier test has asked for.
const anySlugPost = 900200;
const headerPath = (path) => path.split("/").map(encodeURIComponent).join("/");
function post(id, slug) {
  if (id === removedPost) return { status: 410, body: { error: { code: "gone", message: "حُذفت هذه المادة." } } };
  if (id === failingPost) return { status: 503, body: { error: { code: "unavailable", message: "الخدمة غير متاحة." } } };
  if (id === anySlugPost && slug) {
    const base = JSON.parse(readFileSync(new URL("./fixtures/posts/900001.json", import.meta.url), "utf8"));
    const data = { ...base.data, id, slug, path: `/news/${id}/${slug}`, seo: { ...base.data.seo, noindex: true } };

    return { status: 200, body: { data }, path: headerPath(data.path) };
  }
  const file = new URL(`./fixtures/posts/${id}.json`, import.meta.url);
  if (!existsSync(file)) return { status: 404, body: { error: { code: "not_found", message: "غير موجود" } } };
  const body = JSON.parse(readFileSync(file, "utf8"));
  const path = headerPath(body.data.path);
  if (slug !== undefined && slug !== body.data.slug) return { status: 301, body: { path: body.data.path }, location: `/api/v1/posts/${id}/${encodeURIComponent(body.data.slug)}`, path };

  return { status: 200, body, path };
}

// The list pages' data: entities, every item of four lists, indexes, a category feed (capture.mjs).
const lists = fromFile("lists");
const plural = { categories: "category", tags: "tag", authors: "author", guests: "guest" };

function listRoute(pathname) {
  const index = /^\/api\/v1\/(categories|tags|authors|guests)$/.exec(pathname);
  if (index) return { status: 200, body: { data: lists.index[index[1]] ?? [] } };
  const one = /^\/api\/v1\/(categories|tags|authors|guests)\/([^/]+)$/.exec(pathname);
  if (one) {
    const entity = lists.entities[plural[one[1]]][decodeURIComponent(one[2])];
    return entity ? { status: 200, body: { data: entity } } : { status: 404, body: { error: { code: "not_found", message: "غير موجود." } } };
  }
  const feed = /^\/api\/v1\/feeds\/categories\/([^/]+)$/.exec(pathname);
  if (feed) {
    const data = lists.feeds[decodeURIComponent(feed[1])];
    return data ? { status: 200, body: { data } } : { status: 404, body: { error: { code: "not_found", message: "غير موجود." } } };
  }
  return null;
}

// The section pages' data (capture.mjs): each section, all its items, the characters, the feeds, three pages of /news.
const sections = fromFile("sections-pages");

// A section item's value for a filter, read from its facts as the platform's filters would match it.
const factOf = {
  season: (p) => String(p.facts?.season?.number ?? ""),
  sector: (p) => p.facts?.organization?.sector?.slug ?? "",
  era: (p) => String(p.facts?.era?.id ?? ""),
  initiative_status: (p) => p.facts?.status ?? "",
  character: (p) => p.facts?.character?.slug ?? "",
};

/** A page of a section's (or /news's) items: filtered, featured only if asked, `per_page` 1 to 12 as the platform. */
function sectionPosts(params) {
  let items = params.get("section") ? sections.items[params.get("section")] : params.get("kind") === "news,report,opinion,infographic" ? sections.news : undefined;
  if (!items) return null;
  for (const [param, read] of Object.entries(factOf)) if (params.has(param)) items = items.filter((p) => read(p) === params.get(param));
  if (params.get("featured") === "1") items = items.filter((p) => p.is_featured);
  // History is captured on its timeline; without order=timeline the platform gives the newest first, as here.
  if (params.get("section") === "history" && params.get("order") !== "timeline") items = [...items].sort((a, b) => b.published_at.localeCompare(a.published_at));
  const size = Math.min(12, Math.max(1, Number(params.get("per_page") ?? 12)));
  const page = Math.max(1, Number(params.get("page") ?? 1));

  return { data: items.slice((page - 1) * size, page * size), meta: { page, per_page: size, total: items.length, last_page: Math.max(1, Math.ceil(items.length / size)) } };
}

function sectionRoute(pathname) {
  const one = /^\/api\/v1\/sections\/([^/]+)$/.exec(pathname);
  if (one) {
    const data = sections.detail[one[1]];
    return data ? { status: 200, body: { data } } : { status: 404, body: { error: { code: "not_found", message: "غير موجود." } } };
  }
  if (pathname === "/api/v1/awareness") return { status: 200, body: { data: sections.awareness } };
  const feed = /^\/api\/v1\/feeds\/sections\/([^/]+)$/.exec(pathname);
  if (feed) {
    const data = sections.feeds[feed[1]];
    return data ? { status: 200, body: { data } } : { status: 404, body: { error: { code: "not_found", message: "غير موجود." } } };
  }
  return null;
}

// A list of posts: one of the captured lists, paged 12 at a time as the platform does (per_page is 12 whatever is
// asked, except a smaller number for "more from …"); otherwise the posts fixture filtered.
function posts(params) {
  const ofSection = sectionPosts(params);
  if (ofSection) return ofSection;
  const kind = ["category", "tag", "author", "guest"].find((k) => params.get(k));
  const captured = kind ? lists.items[`${kind}:${params.get(kind)}`] : undefined;
  if (captured) {
    const asked = Number(params.get("per_page") ?? 12);
    const size = asked < 12 ? asked : 12;
    const page = Math.max(1, Number(params.get("page") ?? 1));
    const last = Math.max(1, Math.ceil(captured.length / size));
    return { data: captured.slice((page - 1) * size, page * size), meta: { page, per_page: size, total: captured.length, last_page: last } };
  }
  if (kind && kind !== "category") return { data: [], meta: { page: 1, per_page: 12, total: 0, last_page: 1 } };
  let data = fixture("posts").data;
  if (params.get("category")) data = data.filter((p) => p.category?.slug === params.get("category"));
  if (params.get("section")) data = data.filter((p) => p.path.split("/")[1] === params.get("section"));

  return { data: data.slice(0, Number(params.get("per_page") ?? 12)) };
}

// The markets and weather pages' data (capture-markets.mjs): the converter, each currency's 30 days, every city.
const markets = fromFile("markets");
const notFoundAnswer = { status: 404, body: { error: { code: "not_found", message: "غير موجود." } } };

function marketsRoute(pathname) {
  if (pathname === "/api/v1/currencies/converter") return { status: 200, body: markets.converter };
  const history = /^\/api\/v1\/currencies\/([A-Za-z]{3})\/history$/.exec(pathname);
  if (history) {
    const data = markets.history[history[1].toUpperCase()];
    return data ? { status: 200, body: data } : notFoundAnswer;
  }
  if (pathname === "/api/v1/weather/cities") return { status: 200, body: markets.cities };
  const city = /^\/api\/v1\/weather\/([^/]+)$/.exec(pathname);
  if (city && city[1] !== "widget") {
    const data = markets.city[decodeURIComponent(city[1])];
    return data ? { status: 200, body: data } : notFoundAnswer;
  }
  return null;
}

// The team, the questions and «أعلن معنا» (capture-p1.mjs).
const p1 = fromFile("p1");
const p1Routes = { "/api/v1/team": p1.team, "/api/v1/faqs": p1.faqs, "/api/v1/advertise": p1.advertise };

// The services and the panel's pages (capture-pages.mjs), the redirects the platform keeps, and what the site sent.
const pagesData = fromFile("pages");
const presses = new Map();
let lastContact = null;
const chats = [];

/**
 * The chatbot's answers, by what the visitor sent. Some are what a broken or hostile platform could send (markup,
 * `javascript:`, links that leave the site), for the site to show as text.
 */
function chatAnswer(body) {
  const conversation = typeof body.conversation === "string" ? body.conversation : "test-conversation-1";
  const reply = (fields) => ({ status: 200, body: { data: { conversation, reply: { kind: "answer", html: "", text: "", links: [], buttons: [], whatsapp: null, ...fields } } } });
  const message = typeof body.message === "string" ? body.message : "";
  if (body.website) return reply({ kind: "no_answer", html: "<p>لم أجد جواباً لسؤالك.</p>", text: "لم أجد جواباً لسؤالك." });
  if (body.button === 1) {
    return reply({ html: "<p>نقدّم الإعلان والتغطية والتصوير. التفاصيل في <a href=\"/services\">صفحة الخدمات</a>.</p>", text: "نقدّم الإعلان والتغطية والتصوير. التفاصيل في صفحة الخدمات.", buttons: [{ id: 3, label: "أعلن معنا" }] });
  }
  if (body.button) return reply({ kind: "live", html: "<p>أسعار اليوم في صفحة العملات.</p>", text: "أسعار اليوم في صفحة العملات.", links: [{ label: "أسعار العملات", url: "/currencies" }] });
  if (message.includes("كثير")) return { status: 429, body: { error: { code: "too_many", message: "أرسلت رسائل كثيرة. انتظر دقيقة ثم أعد المحاولة." } } };
  if (message.includes("سكربت")) {
    const hostile = '<script>window.__chatRan = 1</script><img src="x" onerror="window.__chatRan = 2">جواب فيه كود';
    return reply({ html: hostile, text: hostile });
  }
  if (message.includes("رابط")) {
    return reply({
      html: '<p>اقرأ <a href="javascript:window.__chatRan=3">هذا</a> و<a href="https://evil.example/">الموقع الآخر</a> و<a href="/about">من نحن</a> و<a href="//evil.example/x">ذاك</a>.</p>',
      text: "اقرأ هذا والموقع الآخر ومن نحن وذاك.",
      links: [
        { label: "<b>اضغط هنا</b>", url: "javascript:window.__chatRan=4" },
        { label: "موقع آخر", url: "https://evil.example/page" },
        { label: "خبر عندنا", url: "https://example.org/news/1" },
        { label: "خدماتنا", url: "/services" },
      ],
      whatsapp: "https://evil.example/wa",
    });
  }
  return reply({
    kind: "no_answer",
    html: "<p>لم أجد جواباً لسؤالك. هذه أقرب المواد من الموقع:</p>",
    text: "لم أجد جواباً لسؤالك. هذه أقرب المواد من الموقع:",
    links: [{ label: "آخر الأخبار", url: "/news" }],
    whatsapp: `https://wa.me/15555550123?text=${encodeURIComponent(`سؤالي: ${message}`)}`,
  });
}

function pagesRoute(pathname, params) {
  if (p1Routes[pathname]) return { status: 200, body: p1Routes[pathname] };
  if (pathname === "/api/v1/services") return { status: 200, body: pagesData.services };
  const service = /^\/api\/v1\/services\/([^/]+)$/.exec(pathname);
  if (service) {
    const data = pagesData.detail[decodeURIComponent(service[1])];
    return data ? { status: 200, body: data } : notFoundAnswer;
  }
  if (pathname === "/api/v1/pages") return { status: 200, body: pagesData.pageList };
  const page = /^\/api\/v1\/pages\/([^/]+)$/.exec(pathname);
  if (page) {
    const data = pagesData.pages[decodeURIComponent(page[1])];
    return data ? { status: 200, body: { data } } : notFoundAnswer;
  }
  if (pathname === "/api/v1/redirects/lookup") {
    const known = pagesData.redirects[params.get("path") ?? ""];
    if (!known) return notFoundAnswer;
    if (known.status === 410) return { status: 410, body: { error: { code: "gone", message: "هذا العنوان حُذف." } } };
    return { status: known.status, body: { to: known.to }, location: known.to };
  }
  return null;
}

// The search (capture-search.mjs): the captured queries, and for any other the answer that finds nothing. The site's
// server must send its token and the visitor's address; the searches it counts (track=1) are kept for the tests.
const searchData = fromFile("search");
const searchOrder = ["q", "category", "section", "kind", "from", "to", "sort", "page"];
const tracked = [];
// What the site's server reported: page views and missing pages, and the ads seen, each with the visitor it named.
const hits = [];
const impressions = [];
const unsubscribed = [];

function searchRoute(params, request) {
  if (!request.headers["x-raqam-site-token"] || !request.headers["x-raqam-visitor-ip"]) return { status: 403, body: { error: { code: "site_only", message: "للموقع فقط." } } };
  const q = (params.get("q") ?? "").trim();
  if (q.length < 2) return { status: 422, body: { error: { code: "invalid", message: "اكتب حرفين على الأقل." } } };
  if (params.get("track") === "1") tracked.push(q);
  const key = new URLSearchParams(searchOrder.flatMap((k) => (params.get(k) ? [[k, params.get(k)]] : []))).toString();
  const known = searchData.answers[key];
  if (known) return { status: 200, body: known };

  return { status: 200, body: { ...searchData.empty, data: { ...searchData.empty.data, query: q } } };
}

// The files search engines read (capture-seo.mjs).
const seo = fromFile("seo");
const seoRoutes = {
  "/api/v1/robots": () => seo.robots,
  "/api/v1/llms": () => seo.llms,
  "/api/v1/sitemaps": () => seo.index,
  "/api/v1/sitemaps/pages": () => seo.pages,
  "/api/v1/sitemaps/news": () => seo.news,
  "/api/v1/sitemaps/videos": () => seo.videos,
  "/api/v1/feeds/posts": () => seo.feed,
};

// The video library and the awareness characters (capture-video.mjs).
const videoData = fromFile("video");

function videoRoute(pathname, params) {
  if (pathname === "/api/v1/videos") {
    const key = params.get("section") ? `section=${params.get("section")}` : `page=${params.get("page") ?? 1}`;
    const known = videoData.library[key];
    return known ? { status: 200, body: known } : { status: 200, body: { data: [], meta: { page: Number(params.get("page") ?? 1), per_page: 12, total: 0, last_page: 1 } } };
  }
  const one = /^\/api\/v1\/videos\/(\d+)$/.exec(pathname);
  if (one) return videoData.video[one[1]] ? { status: 200, body: videoData.video[one[1]] } : notFoundAnswer;
  if (pathname === "/api/v1/playlists") return { status: 200, body: videoData.playlists };
  const list = /^\/api\/v1\/playlists\/([^/]+)$/.exec(pathname);
  if (list) return videoData.playlist[decodeURIComponent(list[1])] ? { status: 200, body: videoData.playlist[decodeURIComponent(list[1])] } : notFoundAnswer;
  if (pathname === "/api/v1/characters") return { status: 200, body: videoData.characters };
  const character = /^\/api\/v1\/characters\/([^/]+?)(\/cards)?$/.exec(pathname);
  if (character) {
    const known = videoData.character[decodeURIComponent(character[1])];
    if (!known) return notFoundAnswer;
    return { status: 200, body: character[2] ? known.cards : known.detail };
  }
  return null;
}

// Campaign dates would run out one day and empty every slot in CI: the fake campaigns never end. The popup and the
// phone's bar (off on a new platform) are on here, each with one made-up campaign, so every test sees the site with
// both; the switches are on in the site's answer below.
const floatingCampaigns = {
  popup: { id: 9001, advertiser: "شركة تجريبية للمنبثق", picture: { width: 1200, height: 1000 }, slot: "popup" },
  mobile_sticky: { id: 9002, advertiser: "شركة تجريبية للشريط", picture: { width: 640, height: 100 }, slot: "mobile_sticky" },
};

function floatingAd({ id, advertiser, picture: { width, height }, slot }) {
  const image = { id, mime_type: "image/jpeg", url: `http://127.0.0.1:${port}/media/floating-${slot}.jpg`, alt: "إعلان تجريبي", caption: null, credit: null, width, height, focal: null, variants: {} };

  return {
    id,
    weight: 50,
    advertiser,
    starts_at: null,
    ends_at: null,
    images: { desktop: slot === "popup" ? image : null, mobile: image },
    click_url: `http://127.0.0.1:${port}/api/v1/ads/click/${id}/${slot}/fake-signature`,
    rel: "sponsored noopener",
  };
}

function timeless(body) {
  return {
    ...body,
    data: body.data.map((slot) => {
      const floating = floatingCampaigns[slot.code];
      if (floating) return { ...slot, active: true, ads: [floatingAd(floating)] };

      return { ...slot, ads: slot.ads.map((ad) => ({ ...ad, starts_at: null, ends_at: null })) };
    }),
  };
}

const withFloatingAds = (body) => ({ ...body, data: { ...body.data, features: { ...body.data.features, ad_popup: true, ad_mobile_sticky: true } } });

const picture = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800" preserveAspectRatio="xMidYMid slice"><rect width="1200" height="800" fill="#dfe6f5"/><path d="M640 520h170l210-280H850z" fill="#21ceea"/><path d="M430 520h90l120-160h-90z" fill="#124fff"/></svg>`;

createServer(async (request, response) => {
  const url = new URL(request.url ?? "/", `http://127.0.0.1:${port}`);
  const json = (status, body) => {
    response.writeHead(status, { "Content-Type": "application/json" });
    response.end(JSON.stringify(body));
  };
  const swap = /^\/__fixtures\/(\w+)$/.exec(url.pathname);
  if (swap && request.method === "PUT") {
    replaced.set(swap[1], JSON.parse(await readBody(request)));
    return json(200, { replaced: swap[1] });
  }
  if (swap && request.method === "DELETE") {
    replaced.delete(swap[1]);
    return json(200, { restored: swap[1] });
  }
  // Unsubscribing: any subscriber with the signature "a" × 64 is a good link; anything else is not. Kept for the tests.
  if (url.pathname === "/api/v1/newsletter/unsubscribe" && request.method === "POST") {
    if (!request.headers["x-raqam-site-token"] || !request.headers["x-raqam-visitor-ip"]) return json(403, { error: { code: "site_only", message: "للموقع فقط." } });
    const body = JSON.parse((await readBody(request)) || "{}");
    if (body.signature !== "a".repeat(64)) return json(422, { error: { code: "invalid_link", message: "رابط إلغاء الاشتراك غير صالح." } });
    unsubscribed.push(String(body.subscriber));
    return json(200, { data: { unsubscribed: true } });
  }
  if (url.pathname === "/__unsubscribed") return json(200, { count: unsubscribed.filter((s) => s === url.searchParams.get("s")).length });
  const step = /^\/api\/v1\/newsletter\/(subscribe|resend|confirm)$/.exec(url.pathname)?.[1];
  if (step && request.method === "POST") {
    const answer = newsletter(step, JSON.parse((await readBody(request)) || "{}"), request);
    return json(answer.status, answer.body);
  }
  if (url.pathname.startsWith("/media/")) {
    response.writeHead(200, { "Content-Type": "image/svg+xml", "Cache-Control": "public, max-age=3600" });
    return response.end(picture);
  }
  if (url.pathname.startsWith("/api/v1/ads/click/")) {
    response.writeHead(302, { Location: "https://example.org/" });
    return response.end();
  }
  if (url.pathname === "/__requests") return json(200, { count: asked.get(url.searchParams.get("path")) ?? 0 });
  if (url.pathname === "/__presses") return json(200, { count: presses.get(url.searchParams.get("key")) ?? 0 });
  if (url.pathname === "/__contact") return json(200, { last: lastContact });
  if (url.pathname === "/__searches") return json(200, { tracked });
  if (url.pathname === "/__hits") return json(200, { hits });
  if (url.pathname === "/__impressions") return json(200, { impressions });
  if ((url.pathname === "/api/v1/stats/hits" || url.pathname === "/api/v1/ads/impressions") && request.method === "POST") {
    const body = JSON.parse((await readBody(request)) || "{}");
    const visitor = { ip: request.headers["x-raqam-visitor-ip"] ?? null, ua: request.headers["x-raqam-visitor-ua"] ?? null, token: !!request.headers["x-raqam-site-token"] };
    (url.pathname.endsWith("hits") ? hits : impressions).push({ ...body, visitor });
    response.writeHead(204);
    return response.end();
  }
  if (seoRoutes[url.pathname]) return json(200, seoRoutes[url.pathname]());
  if (url.pathname === "/api/v1/sitemaps/posts") {
    const page = Number(url.searchParams.get("page") ?? 1);
    return page === 1 ? json(200, seo.posts) : json(200, { data: [], meta: { ...seo.posts.meta, page } });
  }
  if (url.pathname === "/api/v1/search") {
    const answer = searchRoute(url.searchParams, request);
    return json(answer.status, answer.body);
  }
  const press = /^\/api\/v1\/services\/([^/]+)\/contact$/.exec(url.pathname);
  if (press && request.method === "POST") {
    if (!request.headers["x-raqam-site-token"] || !request.headers["x-raqam-visitor-ip"]) return json(403, { error: { code: "site_only", message: "للموقع فقط." } });
    const channel = JSON.parse((await readBody(request)) || "{}").channel;
    const key = `${decodeURIComponent(press[1])}:${channel}`;
    presses.set(key, (presses.get(key) ?? 0) + 1);
    response.writeHead(204);
    return response.end();
  }
  if (url.pathname === "/api/v1/chat" && request.method === "GET") {
    return json(200, { data: { enabled: true, welcome: "أهلاً بك! أنا المساعد. اسألني أو اختر من الأزرار.", buttons: [{ id: 1, label: "خدماتنا وأسعارها" }, { id: 2, label: "أسعار العملات اليوم" }], max_length: 500 } });
  }
  if (url.pathname === "/__chat") return json(200, { last: chats.findLast((c) => c.body.message === url.searchParams.get("message")) ?? null });
  if (url.pathname === "/api/v1/chat/messages" && request.method === "POST") {
    const visitor = { ip: request.headers["x-raqam-visitor-ip"] ?? null, token: request.headers["x-raqam-site-token"] ?? null };
    if (!visitor.token || !visitor.ip) return json(403, { error: { code: "site_only", message: "المحادثة تُرسَل من الموقع فقط." } });
    const body = JSON.parse((await readBody(request)) || "{}");
    chats.push({ body, visitor });
    if (chats.length > 500) chats.shift();
    const answer = chatAnswer(body);
    return json(answer.status, answer.body);
  }
  if (url.pathname === "/api/v1/contact" && request.method === "POST") {
    if (!request.headers["x-raqam-site-token"] || !request.headers["x-raqam-visitor-ip"]) return json(403, { error: { code: "site_only", message: "هذا النموذج يُرسَل من الموقع فقط." } });
    const body = JSON.parse((await readBody(request)) || "{}");
    lastContact = { body, ip: request.headers["x-raqam-visitor-ip"] };
    if (body.website) return json(202, { data: { received: true } });
    if (!body.turnstile_token) return json(422, { error: { code: "turnstile_failed", message: "تعذّر التحقق من أنك لست روبوتاً؛ أعد المحاولة." } });
    if (body.phone && !/^\+\d{8,15}$/.test(body.phone.replace(/\s/g, ""))) {
      return json(422, { error: { code: "invalid", message: "تحقّق من البيانات.", details: { phone: ["اكتب الرقم بالصيغة الدولية، مثال: +970599123456"] } } });
    }
    return json(202, { data: { received: true } });
  }
  const single = /^\/api\/v1\/posts\/(\d+)(?:\/([^/]+))?$/.exec(url.pathname);
  if (single) {
    // The site's check (HEAD), counted by address, so the tests can see it is kept and not repeated.
    if (request.method === "HEAD") {
      const key = decodeURIComponent(url.pathname);
      asked.set(key, (asked.get(key) ?? 0) + 1);
    }
    const answer = post(Number(single[1]), single[2] === undefined ? undefined : decodeURIComponent(single[2]));
    response.writeHead(answer.status, {
      "Content-Type": "application/json",
      ...(answer.location ? { Location: answer.location } : {}),
      ...(answer.path ? { "X-Raqam-Path": answer.path } : {}),
    });
    return response.end(request.method === "HEAD" ? undefined : JSON.stringify(answer.body));
  }
  if (url.pathname === "/api/v1/posts" && url.searchParams.get("breaking") !== "1") return json(200, posts(url.searchParams));
  const videoAnswer = videoRoute(url.pathname, url.searchParams);
  if (videoAnswer) {
    if (request.method === "HEAD") {
      const key = decodeURIComponent(url.pathname);
      asked.set(key, (asked.get(key) ?? 0) + 1);
    }
    return json(videoAnswer.status, videoAnswer.body);
  }
  const pagesAnswer = pagesRoute(url.pathname, url.searchParams);
  if (pagesAnswer) {
    if (request.method === "HEAD") {
      const key = decodeURIComponent(url.pathname);
      asked.set(key, (asked.get(key) ?? 0) + 1);
    }
    response.writeHead(pagesAnswer.status, { "Content-Type": "application/json", ...(pagesAnswer.location ? { Location: encodeURI(pagesAnswer.location) } : {}) });
    return response.end(request.method === "HEAD" ? undefined : JSON.stringify(pagesAnswer.body));
  }
  const marketsAnswer = marketsRoute(url.pathname);
  if (marketsAnswer) {
    if (request.method === "HEAD") {
      const key = decodeURIComponent(url.pathname);
      asked.set(key, (asked.get(key) ?? 0) + 1);
    }
    return json(marketsAnswer.status, marketsAnswer.body);
  }
  const sectionAnswer = sectionRoute(url.pathname);
  if (sectionAnswer) return json(sectionAnswer.status, sectionAnswer.body);
  const listAnswer = listRoute(url.pathname);
  if (listAnswer) return json(listAnswer.status, listAnswer.body);
  let name = routes[url.pathname];
  if (url.pathname === "/api/v1/posts" && url.searchParams.get("breaking") === "1") name = "breaking";
  if (!name) {
    response.writeHead(404, { "Content-Type": "application/json" });
    return response.end(JSON.stringify({ error: { code: "not_found", message: "غير موجود" } }));
  }
  const body = name === "ads" ? timeless(fixture(name)) : name === "site" ? withFloatingAds(fixture(name)) : fixture(name);
  response.writeHead(200, { "Content-Type": "application/json" });
  response.end(JSON.stringify(body));
}).listen(port, "127.0.0.1", () => console.log(`Fake API on http://127.0.0.1:${port}/api/v1`));
