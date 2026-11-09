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
const samples: Record<string, string> = { section: "news", id: "123", slug: "x", code: "usd", n: "2", step: "subscribe" };

function routes(dir: string, parts: string[] = []): string[][] {
  const found: string[][] = [];
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) {
      // Private folders hold no route; a route group's name is not part of the address.
      if (name.startsWith("_")) continue;
      found.push(...routes(path, /^\(.*\)$/.test(name) ? parts : [...parts, name]));
    } else if (/^(page|route)\.(tsx?|jsx?)$/.test(name)) found.push(parts);
  }

  return found;
}

const all = routes("src/app");

test("every page and route under src/app gets past the check", () => {
  expect(all.length).toBeGreaterThan(20);
  const missingSamples: string[] = [];
  const refused: string[] = [];
  for (const parts of all) {
    const address = parts.map((part) => {
      const param = /^\[{1,2}(?:\.\.\.)?([^\]]+)\]{1,2}$/.exec(part)?.[1];
      if (!param) return part;
      if (!(param in samples)) missingSamples.push(`${param} in /${parts.join("/")}`);
      return samples[param] ?? "x";
    });
    const path = `/${address.join("/")}`;
    if (routeOf(path) === "not-a-page") refused.push(`/${parts.join("/")} (as ${path})`);
  }
  expect(missingSamples, "add a sample value for these parameters").toEqual([]);
  expect(refused, "src/proxy.ts would answer 404 to these routes: teach routeOf() about them").toEqual([]);
});

test("the matcher leaves out the same folders as the check", () => {
  const generic = config.matcher.at(-1)!;
  for (const folder of leftAlone) expect(generic, folder).toContain(`${folder}/`);
});

test("addresses that are no page are still turned away", () => {
  for (const path of ["/x/1/a", "/x/123", "/currencies/usd/more", "/weather/x/y", "/awareness/characters/x"]) expect(routeOf(path), path).toBe("not-a-page");
});
