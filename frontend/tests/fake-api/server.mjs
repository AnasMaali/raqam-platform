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
    if (body.placement !== "home" || body.path !== "/") return invalid("invalid", "مكان النموذج غير معروف.");

    return { status: 202, body: challenge };
  }
  if (body.challenge !== "fake-challenge") return invalid("expired", "انتهت صلاحية الرمز. اطلب رمزاً جديداً.");
  if (step === "resend") return { status: 202, body: challenge };
  if (body.code !== newsletterCode) return invalid("bad_code", "الرمز غير صحيح.");

  return { status: 200, body: { data: { confirmed: true } } };
}

// How many times the site checked each post address with HEAD (GET /__requests?path=/api/v1/posts/…).
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

// A list of posts: one of the captured lists, paged 12 at a time as the platform does (per_page is 12 whatever is
// asked, except a smaller number for "more from …"); otherwise the posts fixture filtered.
function posts(params) {
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

// Campaign dates would run out one day and empty every slot in CI: the fake campaigns never end.
function timeless(body) {
  return { ...body, data: body.data.map((slot) => ({ ...slot, ads: slot.ads.map((ad) => ({ ...ad, starts_at: null, ends_at: null })) })) };
}

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
  const listAnswer = listRoute(url.pathname);
  if (listAnswer) return json(listAnswer.status, listAnswer.body);
  let name = routes[url.pathname];
  if (url.pathname === "/api/v1/posts" && url.searchParams.get("breaking") === "1") name = "breaking";
  if (!name) {
    response.writeHead(404, { "Content-Type": "application/json" });
    return response.end(JSON.stringify({ error: { code: "not_found", message: "غير موجود" } }));
  }
  const body = name === "ads" ? timeless(fixture(name)) : fixture(name);
  response.writeHead(200, { "Content-Type": "application/json" });
  response.end(JSON.stringify(body));
}).listen(port, "127.0.0.1", () => console.log(`Fake API on http://127.0.0.1:${port}/api/v1`));
