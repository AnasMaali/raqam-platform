// A stand-in for the platform API, for the build and the browser tests in CI: it answers the site's requests from the
// fixtures captured by capture.mjs (made-up demo content) and serves one placeholder picture for every media file.
//   node tests/fake-api/server.mjs [port]   (default 4010; API_BASE_URL=http://127.0.0.1:4010/api/v1)
import { readFileSync } from "node:fs";
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
};

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
