// Fails when the browser bundle could carry the site token: run after `next build`.
import { readdir, readFile } from "node:fs/promises";
import { join } from "node:path";

const root = new URL("../.next/static/", import.meta.url).pathname;
const token = process.env.API_SITE_TOKEN?.trim();
const needles = ["API_SITE_TOKEN", "X-Raqam-Site-Token", "x-raqam-site-token"];
if (token) needles.push(token);

async function* files(dir) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) yield* files(path);
    else if (/\.(js|mjs|json|html|css|txt)$/.test(entry.name)) yield path;
  }
}

const hits = [];
let scanned = 0;
for await (const path of files(root)) {
  scanned++;
  const text = await readFile(path, "utf8");
  for (const needle of needles) {
    if (text.includes(needle)) hits.push(`${path.slice(root.length)}: ${needle === token ? "<token value>" : needle}`);
  }
}

if (scanned === 0) {
  console.error("No browser files found under .next/static; run next build first.");
  process.exit(1);
}
if (hits.length > 0) {
  console.error("The browser bundle mentions the site token:\n" + hits.join("\n"));
  process.exit(1);
}
console.log(`Browser bundle clean (${scanned} files, token value ${token ? "checked" : "not set"}).`);
