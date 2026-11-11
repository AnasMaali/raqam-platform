import { readdirSync, statSync } from "node:fs";
import { join } from "node:path";

import { expect, test } from "@playwright/test";

import { config, leftAlone, routeOf } from "../../src/proxy";

/*
 * The check before the page (src/proxy.ts) answers 404 to any address of two or three parts it does not know, so a
 * new route it was not told about would answer 404 to every visitor. This reads every page and route under src/app and
 * fails when the check would turn one away. A new route with a new parameter needs a sample value below.
 */

// A real-looking value for each route parameter, in the shape the check expects.
const samples: Record<string, string> = { section: "news", id: "123", slug: "x", code: "usd", n: "2", step: "subscribe", name: "pages.xml" };

// A metadata file's address: robots.ts serves /robots.txt, sitemap.ts /sitemap.xml, icon.svg itself.
const metadataFiles: Record<string, (file: string) => string> = {
  robots: () => "robots.txt",
  sitemap: () => "sitemap.xml",
  manifest: () => "manifest.webmanifest",
  icon: (file) => (/\.(tsx?|jsx?)$/.test(file) ? "icon" : file),
  "apple-icon": (file) => (/\.(tsx?|jsx?)$/.test(file) ? "apple-icon" : file),
  favicon: (file) => file,
};

function routes(dir: string, parts: string[] = []): string[][] {
  const found: string[][] = [];
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) {
      // Private folders hold no route; a route group's name is not part of the address.
      if (name.startsWith("_")) continue;
      found.push(...routes(path, /^\(.*\)$/.test(name) ? parts : [...parts, name]));
    } else if (/^(page|route)\.(tsx?|jsx?)$/.test(name)) found.push(parts);
    // Next.js's metadata files are routes too, under their own address.
    else if (metadataFiles[name.replace(/\.[^.]+$/, "")] && !/\.(test|spec)\./.test(name)) found.push([...parts, metadataFiles[name.replace(/\.[^.]+$/, "")](name)]);
  }

  return found;
}

const all = routes("src/app");

test("every page and route under src/app gets past the check", () => {
  expect(all.length).toBeGreaterThan(20);
  const missingSamples: string[] = [];
  const refused: string[] = [];
  const asked: string[] = [];
  for (const parts of all) {
    const address = parts.map((part) => {
      const param = /^\[{1,2}(?:\.\.\.)?([^\]]+)\]{1,2}$/.exec(part)?.[1];
      if (!param) return part;
      if (!(param in samples)) missingSamples.push(`${param} in /${parts.join("/")}`);
      return samples[param] ?? "x";
    });
    const path = `/${address.join("/")}`;
    const kind = routeOf(path);
    if (kind === "not-a-page") refused.push(`/${parts.join("/")} (as ${path})`);
    // A route of the site's own, without parameters, is its own page: the check must not ask the platform about it
    // (it would ask for a page from the panel and answer 404). The sections and the video library are checked on
    // purpose (their filters and pages).
    if (address.join("/") === parts.join("/") && !["left-alone", "section", "video-library"].includes(kind)) asked.push(`${path} (${kind})`);
  }
  expect(missingSamples, "add a sample value for these parameters").toEqual([]);
  expect(refused, "src/proxy.ts would answer 404 to these routes: teach routeOf() about them").toEqual([]);
  expect(asked, "the site's own pages the check would look up on the platform: add them to ownPages in src/proxy.ts").toEqual([]);
});

test("the matcher leaves out the same folders as the check", () => {
  const generic = config.matcher.filter((m) => m.startsWith("/((?!"));
  expect(generic.length).toBeGreaterThan(0);
  for (const entry of generic) for (const folder of leftAlone) expect(entry, folder).toContain(folder);
});

test("addresses that are no page are still turned away", () => {
  for (const path of ["/x/1/a", "/x/123", "/currencies/usd/more", "/weather/x/y", "/awareness/characters/x/y", "/video/x", "/login", "/x.php", "/favicon.ico", "/wp-login.php"]) {
    expect(routeOf(path), path).toBe("not-a-page");
  }
  // The site's folders keep their files, and its own root files are its own.
  expect(routeOf("/brand/logo-512.png")).toBe("left-alone");
  for (const file of ["/robots.txt", "/sitemap.xml", "/llms.txt"]) expect(routeOf(file), file).toBe("left-alone");
  // One part that is not the site's own: a page from the panel, asked about.
  expect(routeOf("/about")).toBe("page");
  // A bare folder's name is no page (but the development lab's index); a word that only starts like one is a page's
  // address like any other, so it is asked about.
  for (const folder of ["/api", "/feeds", "/sitemaps", "/brand", "/og", "/_next"]) expect(routeOf(folder), folder).toBe("not-a-page");
  expect(routeOf("/lab")).toBe("left-alone");
  for (const word of ["/labour", "/ogre", "/brandon", "/apiary", "/feedsx"]) expect(routeOf(word), word).toBe("page");
});

test("every address of one part goes through the check, and an unknown one answers 404 before the page", async ({ request }) => {
  // The matcher runs the check on all of them (the folders' contents are deeper); none answers 200 by mistake.
  expect(config.matcher).toContain("/([^/]+)");
  for (const path of ["/labour", "/ogre", "/brandon", "/apiary", "/feedsx", "/brand", "/og", "/sitemaps", "/feeds", "/api"]) {
    expect((await request.get(path, { maxRedirects: 0 })).status(), path).toBe(404);
  }
});
