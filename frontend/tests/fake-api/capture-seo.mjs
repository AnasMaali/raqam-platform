// Captures the files search engines read (robots, llms, the sitemaps, the site's feed) for the fake API from a local
// platform (run from frontend/: node tests/fake-api/capture-seo.mjs; reads .env.local). Run after the other captures:
// the sitemaps keep only the addresses the fake API can answer (so a test can ask every one of them), plus the pages
// the site does not serve yet, which the site must leave out. The crawlers' names are replaced by made-up ones.
import { readdirSync, readFileSync, writeFileSync } from "node:fs";

const env = Object.fromEntries(
  readFileSync(new URL("../../.env.local", import.meta.url), "utf8")
    .split("\n")
    .filter((line) => line.includes("=") && !line.startsWith("#"))
    .map((line) => [line.slice(0, line.indexOf("=")), line.slice(line.indexOf("=") + 1).trim()]),
);
const base = env.API_BASE_URL.replace(/\/+$/, "");
const get = async (path) => (await fetch(base + path, { headers: { Accept: "application/json", "X-Raqam-Site-Token": env.API_SITE_TOKEN } })).json();
const fixture = (name) => JSON.parse(readFileSync(new URL(`./fixtures/${name}.json`, import.meta.url), "utf8"));

const robots = await get("/robots");
robots.data.groups = robots.data.groups.map((g) => (g.purpose === "search" ? g : { ...g, agents: g.purpose === "answer" ? ["ExampleAnswerBot", "ExampleAssistant"] : ["ExampleTrainingBot"] }));

const llms = await get("/llms");

// What the fake API answers: its sections, lists, pages, services, currencies and cities.
const lists = fixture("lists");
const pagesData = fixture("pages");
const markets = fixture("markets");
const currencies = fixture("currencies");
const served = new Set([
  "/",
  "/news",
  ...Object.keys(fixture("sections-pages").detail).map((s) => `/${s}`),
  ...Object.entries(lists.entities).flatMap(([kind, all]) => Object.keys(all).map((slug) => `/${kind}/${slug}`)),
  ...pagesData.pageList.data.map((p) => `/${p.slug}`),
  "/services",
  ...Object.keys(pagesData.detail).map((slug) => `/services/${slug}`),
  "/currencies",
  ...currencies.data.map((r) => r.path),
  "/weather",
  ...markets.cities.data.map((c) => c.path),
]);
const pages = await get("/sitemaps/pages");
const notYet = ["/video", "/video/playlists/", "/awareness/characters/"].map((prefix) => pages.data.find((p) => p.path.startsWith(prefix))).filter(Boolean);
pages.data = [...pages.data.filter((p) => served.has(p.path)), ...notYet];

const postFiles = readdirSync(new URL("./fixtures/posts/", import.meta.url)).filter((f) => f.endsWith(".json"));
const posts = { data: postFiles.map((f) => JSON.parse(readFileSync(new URL(`./fixtures/posts/${f}`, import.meta.url), "utf8")).data).map((p) => ({ path: p.path, updated_at: p.updated_at })), meta: { page: 1, per_page: 2000, total: postFiles.length, last_page: 1 } };
const news = { data: posts.data.slice(0, 2).map((p, i) => ({ path: p.path, title: `خبر تجريبي ${i + 1} & «رقم»`, published_at: p.updated_at, keywords: [] })), meta: { publication: { name: "رَقَم", language: "ar" }, hours: 48 } };
const videos = await get("/sitemaps/videos");
videos.data = videos.data.slice(0, 2);
const index = await get("/sitemaps");
index.meta.posts_total = posts.data.length;
const feed = await get("/feeds/posts");
feed.data.items = feed.data.items.slice(0, 5);

writeFileSync(new URL("./fixtures/seo.json", import.meta.url), JSON.stringify({ robots, llms, index, pages, posts, news, videos, feed }) + "\n");
console.log("pages", pages.data.length, "posts", posts.data.length, "not yet built", notYet.map((p) => p.path));
