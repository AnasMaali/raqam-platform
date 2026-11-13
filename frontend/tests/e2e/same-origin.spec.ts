import { expect, test, type APIRequestContext } from "@playwright/test";

import { fakeApi, site, startSite } from "./helpers";

/*
 * The forms' and reports' routes take a request only from the site's own pages: its Origin must be SITE_URL's origin,
 * character for character. With www or without, http or https, another port: refused. The request's Host and
 * X-Forwarded-Host change nothing. And the server does not start with a SITE_URL that is not exactly an origin.
 */

/** Every route that checks the Origin, with a body that gets past that check (the route may refuse it later). */
const forms = [
  { name: "the chat", path: "/api/chat", data: { message: "سؤال" } },
  { name: "the contact form", path: "/api/contact", data: {} },
  { name: "the newsletter", path: "/api/newsletter/subscribe", data: {} },
  { name: "unsubscribing", path: "/api/newsletter/unsubscribe", data: {} },
  { name: "a service's contact", path: "/api/services/podcast-episode/contact", data: {} },
];

const unique = () => `${Date.now()}${Math.random().toString(36).slice(2, 8)}`;

/** The two reports (always 204): whether the platform was told about a page view and an ad view sent from `origin`. */
async function reportsCounted(request: APIRequestContext, base: string, headers: Record<string, string>) {
  const marker = unique();
  const viewId = `view${marker}`;
  const asBeacon = (data: unknown) => ({ data: Buffer.from(JSON.stringify(data)), headers: { ...headers, "Content-Type": "text/plain" } });
  expect((await request.post(`${base}/api/stats`, asBeacon({ type: "view", path: `/?m=${marker}`, referrer: null }))).status()).toBe(204);
  expect((await request.post(`${base}/api/ads/impressions`, asBeacon({ view_id: viewId, items: [{ campaign_id: 1, placement: "top_banner" }] }))).status()).toBe(204);
  const hits = ((await (await request.get(`${fakeApi}/__hits`)).json()) as { hits: { path: string }[] }).hits;
  const views = ((await (await request.get(`${fakeApi}/__impressions`)).json()) as { impressions: { view_id: string }[] }).impressions;

  return { pageView: hits.some((h) => h.path.includes(marker)), adView: views.some((v) => v.view_id === viewId) };
}

/** What each route answers a request carrying these headers: refused (403) or not. */
async function refusals(request: APIRequestContext, base: string, headers: Record<string, string>) {
  const answers: Record<string, boolean> = {};
  for (const { name, path, data } of forms) answers[name] = (await request.post(`${base}${path}`, { headers, data })).status() === 403;
  const counted = await reportsCounted(request, base, headers);
  answers["a page view"] = !counted.pageView;
  answers["an ad view"] = !counted.adView;

  return answers;
}

const everyRoute = [...forms.map((f) => f.name), "a page view", "an ad view"];
const allRefused = Object.fromEntries(everyRoute.map((name) => [name, true]));
const noneRefused = Object.fromEntries(everyRoute.map((name) => [name, false]));

test.describe("the site's own Origin, character for character", () => {
  test.beforeEach(({}, info) => test.skip(info.project.name !== "desktop", "routes, the same at every size"));

  test(`SITE_URL is ${site}: its Origin passes, a forged X-Forwarded-Host or Host changes nothing`, async ({ request }) => {
    expect(await refusals(request, site, { Origin: site })).toEqual(noneRefused);
    expect(await refusals(request, site, { Origin: site, "X-Forwarded-Host": "elsewhere.example" })).toEqual(noneRefused);
  });

  test("https for http, another name for the same machine, another port, no Origin: refused", async ({ request }) => {
    for (const origin of ["https://127.0.0.1:3100", "http://localhost:3100", "http://127.0.0.1:3101", `${site}/`, "null"]) {
      expect(await refusals(request, site, { Origin: origin }), origin).toEqual(allRefused);
    }
    expect(await refusals(request, site, {}), "no Origin").toEqual(allRefused);
  });

  test("another site naming this one in X-Forwarded-Host or Host: refused", async ({ request }) => {
    const host = new URL(site).host;
    expect(await refusals(request, site, { Origin: "https://elsewhere.example", "X-Forwarded-Host": host })).toEqual(allRefused);
    expect(await refusals(request, site, { Origin: "https://elsewhere.example", "X-Forwarded-Host": host, "X-Forwarded-Proto": "http", Host: host })).toEqual(allRefused);
  });
});

test.describe("with www or without, as SITE_URL says", () => {
  test.describe.configure({ mode: "serial" });
  test.beforeEach(({}, info) => test.skip(info.project.name !== "desktop", "routes, the same at every size"));

  const servers = [
    { port: 3102, siteUrl: "https://raqam.example", other: "https://www.raqam.example" },
    { port: 3103, siteUrl: "https://www.raqam.example", other: "https://raqam.example" },
  ];
  let running: ReturnType<typeof startSite>[] = [];

  test.beforeAll(async ({}, info) => {
    if (info.project.name !== "desktop") return;
    running = servers.map(({ port, siteUrl }) => startSite(port, { SITE_URL: siteUrl }));
    for (const server of running) await server.ready();
  });

  test.afterAll(() => running.forEach((server) => server.stop()));

  for (const { port, siteUrl, other } of servers) {
    test(`SITE_URL ${siteUrl}: its Origin passes; ${other}, and http, are refused`, async ({ request }) => {
      const base = `http://127.0.0.1:${port}`;
      expect(await refusals(request, base, { Origin: siteUrl })).toEqual(noneRefused);
      expect(await refusals(request, base, { Origin: other }), other).toEqual(allRefused);
      expect(await refusals(request, base, { Origin: siteUrl.replace("https:", "http:") }), "http").toEqual(allRefused);
      // The forged headers naming the site do not make another page's request its own.
      expect(await refusals(request, base, { Origin: other, "X-Forwarded-Host": new URL(siteUrl).host, Host: new URL(siteUrl).host })).toEqual(allRefused);
    });
  }
});

test.describe("the server refuses to start without an exact SITE_URL", () => {
  test.beforeEach(({}, info) => test.skip(info.project.name !== "desktop", "the server, the same at every size"));

  const wrong = ["", "https://raqam.example/", "https://raqam.example/ar", "http://raqam.example", "raqam.example", "https://Raqam.example", "https://raqam.example?x=1", " https://raqam.example"];
  for (const [i, value] of wrong.entries()) {
    test(`SITE_URL ${JSON.stringify(value)}: it stops, and says why`, async () => {
      // A port each: the server opens its port a moment before it reads its settings.
      const server = startSite(3110 + i, { SITE_URL: value });
      try {
        const code = await Promise.race([server.exited, new Promise<"running">((r) => setTimeout(() => r("running"), 30_000))]);
        expect(code, server.output()).not.toBe("running");
        expect(code).not.toBe(0);
        expect(server.output()).toContain(`SITE_URL must be the site's public origin, exactly: https://<host> (http only for localhost and 127.0.0.1), with no path and no closing slash. It is ${JSON.stringify(value)}.`);
      } finally {
        server.stop();
      }
    });
  }

  for (const [port, value] of [[3105, "http://localhost:3105"], [3106, "https://raqam.example"]] as const) {
    test(`SITE_URL ${value}: it starts`, async () => {
      const server = startSite(port, { SITE_URL: value });
      try {
        await server.ready();
      } finally {
        server.stop();
      }
    });
  }
});
