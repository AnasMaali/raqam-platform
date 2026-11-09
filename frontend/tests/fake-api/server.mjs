// A stand-in for the platform API, for the build and the browser tests in CI: it answers the site's requests from the
// fixtures captured by capture.mjs (made-up demo content) and serves one placeholder picture for every media file.
//   node tests/fake-api/server.mjs [port]   (default 4010; API_BASE_URL=http://127.0.0.1:4010/api/v1)
import { readFileSync } from "node:fs";
import { createServer } from "node:http";

const port = Number(process.argv[2] ?? 4010);
const fixture = (name) => JSON.parse(readFileSync(new URL(`./fixtures/${name}.json`, import.meta.url), "utf8"));

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

createServer((request, response) => {
  const url = new URL(request.url ?? "/", `http://127.0.0.1:${port}`);
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
