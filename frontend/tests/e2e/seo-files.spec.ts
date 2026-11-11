import { readFileSync } from "node:fs";

import { expect, test } from "@playwright/test";

/*
 * The files search engines and answer engines read, against the fake API (capture-seo.mjs, crawler names made up):
 * robots.txt, llms.txt, the sitemap and its parts (every address in them answers 200; pages not built yet and noindex
 * pages are left out), the site's RSS feed, the IndexNow key file, and the branded 500 page.
 */

const seo: {
  robots: { data: { groups: { agents: string[]; allow: boolean }[]; disallow: string[] } };
  pages: { data: { path: string }[] };
  posts: { data: { path: string }[] };
  news: { data: { title: string }[] };
  videos: { data: unknown[] };
  llms: { data: { name: string; groups: { title: string }[] } };
} = JSON.parse(readFileSync("tests/fake-api/fixtures/seo.json", "utf8"));
const site = "https://example.org";
const locs = (body: string) => [...body.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1].replace(/&amp;/g, "&"));

test("robots.txt: one group per policy, the platform's closed paths and the site's own routes, the sitemap", async ({ request }) => {
  const response = await request.get("/robots.txt");
  expect(response.status()).toBe(200);
  expect(response.headers()["content-type"]).toContain("text/plain");
  const text = await response.text();
  for (const group of seo.robots.data.groups) for (const agent of group.agents) expect(text).toContain(`User-agent: ${agent}`);
  // The training crawlers are closed; search engines are open but for the closed paths.
  expect(text).toMatch(/User-agent: ExampleTrainingBot\nDisallow: \/\n/);
  for (const path of seo.robots.data.disallow) expect(text).toContain(`Disallow: ${path}`);
  expect(text).toContain("Disallow: /api/");
  expect(text).toContain(`Sitemap: ${site}/sitemap.xml`);
});

test("llms.txt: the name, the groups of links with full addresses, and where the whole site is", async ({ request }) => {
  const response = await request.get("/llms.txt");
  expect(response.status()).toBe(200);
  const text = await response.text();
  expect(text.startsWith(`# ${seo.llms.data.name}`)).toBe(true);
  expect(text).toContain(`## ${seo.llms.data.groups[0].title}`);
  expect(text).toMatch(new RegExp(`- \\[[^\\]]+\\]\\(${site}/`));
  expect(text).toContain(`${site}/feeds/posts`);
});

test("the sitemap: an index of its parts; every address in them answers 200; nothing noindex, nothing not built yet, no lab", async ({ request }) => {
  const index = await request.get("/sitemap.xml");
  expect(index.status()).toBe(200);
  expect(index.headers()["content-type"]).toContain("xml");
  const parts = locs(await index.text());
  expect(parts).toEqual([`${site}/sitemaps/pages.xml`, `${site}/sitemaps/posts-1.xml`, `${site}/sitemaps/news.xml`, `${site}/sitemaps/videos.xml`]);

  const all: string[] = [];
  for (const part of parts) {
    const response = await request.get(new URL(part).pathname);
    expect(response.status(), part).toBe(200);
    all.push(...locs(await response.text()));
  }
  // No /lab and no noindex page; the video pages and the characters' pages are in.
  expect(all.some((u) => /\/awareness\/characters\//.test(u))).toBe(true);
  expect(all.some((u) => /\/video\/\d+$/.test(u))).toBe(true);
  expect(all.some((u) => /\/(lab|search|newsletter)(\/|$)/.test(new URL(u).pathname))).toBe(false);
  for (const page of ["/currencies/usd", "/services", "/about", "/weather", "/podcast"]) expect(all).toContain(`${site}${encodeURI(page)}`);
  expect(all.length).toBe(seo.pages.data.length + seo.posts.data.length + seo.news.data.length + seo.videos.data.length);
  for (const url of all) {
    const path = new URL(url).pathname;
    expect((await request.get(path, { maxRedirects: 0 })).status(), decodeURI(path)).toBe(200);
  }
});

test("the news sitemap carries Google News' publication, date and title, escaped", async ({ request }) => {
  const text = await (await request.get("/sitemaps/news.xml")).text();
  expect(text).toContain('xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"');
  expect(text).toContain("<news:name>رَقَم</news:name><news:language>ar</news:language>");
  expect(text).toContain("خبر تجريبي 1 &amp; «رقم»");
  expect(text).toMatch(/<news:publication_date>\d{4}-\d{2}-\d{2}T/);
});

test("an unknown sitemap part, or a page of items past the last, is a 404; the videos' part names each video", async ({ request }) => {
  for (const path of ["/sitemaps/nope.xml", "/sitemaps/posts-2.xml", "/sitemaps/posts-0.xml"]) expect((await request.get(path)).status(), path).toBe(404);
  const videos = await (await request.get("/sitemaps/videos.xml")).text();
  expect(videos).toContain('xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"');
  expect(locs(videos)).toHaveLength(seo.videos.data.length);
  expect(videos).toMatch(/<video:title>[^<]+<\/video:title>/);
});

test("the site's RSS feed", async ({ request }) => {
  const response = await request.get("/feeds/posts");
  expect(response.status()).toBe(200);
  expect(response.headers()["content-type"]).toContain("application/rss+xml");
  expect((await response.text()).match(/<item>/g)?.length).toBeGreaterThan(0);
});

test("the IndexNow key file: the key from the environment, as plain text; no other .txt file", async ({ request }) => {
  const response = await request.get("/ci-only-indexnow-key-0000.txt");
  expect(response.status()).toBe(200);
  expect(await response.text()).toBe("ci-only-indexnow-key-0000");
  expect((await request.get("/another-key-1234.txt")).status()).toBe(404);
});
