// Captures the fixtures of the fake API from a local platform filled with `php artisan raqam:demo` (made-up content).
// Run from frontend/: node tests/fake-api/capture.mjs  (reads API_BASE_URL and API_SITE_TOKEN from .env.local).
import { readFileSync, writeFileSync } from "node:fs";

const env = Object.fromEntries(
  readFileSync(new URL("../../.env.local", import.meta.url), "utf8")
    .split("\n")
    .filter((line) => line.includes("=") && !line.startsWith("#"))
    .map((line) => [line.slice(0, line.indexOf("=")), line.slice(line.indexOf("=") + 1).trim()]),
);
const base = env.API_BASE_URL.replace(/\/+$/, "");
const storage = new URL(base).origin + "/storage/";

const endpoints = {
  site: "/site",
  menus: "/menus",
  currencies: "/currencies",
  ticker: "/currencies/ticker",
  weather: "/weather/widget",
  ads: "/ads",
  breaking: "/posts?breaking=1",
  home: "/home",
};

// Pictures point at the fake API's one placeholder; only the sizes the site asks for are kept.
const media = (value) => {
  if (Array.isArray(value)) return value.map(media);
  if (value && typeof value === "object") {
    const out = {};
    for (const [key, inner] of Object.entries(value)) {
      if (key === "variants") {
        out.variants = Object.fromEntries(Object.entries(inner).filter(([size]) => ["sm", "md", "lg"].includes(size)).map(([k, v]) => [k, media(v)]));
      } else if (key === "click_url" && typeof inner === "string") {
        // The signature belongs to the local platform's key: the fixture keeps the address's shape only.
        out.click_url = `http://127.0.0.1:4010${new URL(inner).pathname.replace(/[^/]+$/, "fixture")}`;
      } else out[key] = media(inner);
    }
    return out;
  }
  if (typeof value === "string" && value.startsWith(storage)) return `http://127.0.0.1:4010/media/${value.slice(storage.length).split("/").pop()}`;
  return value;
};

for (const [name, path] of Object.entries(endpoints)) {
  const response = await fetch(base + path, { headers: { Accept: "application/json", "X-Raqam-Site-Token": env.API_SITE_TOKEN } });
  let body = await response.json();
  // The home answer is large: three items per block are enough for every state.
  if (name === "home") body = { ...body, data: body.data.map((b) => ({ ...b, ...(b.posts ? { posts: b.posts.slice(0, 3) } : {}), ...(b.videos ? { videos: b.videos.slice(0, 3) } : {}) })) };
  if (name === "breaking") body = { ...body, data: body.data.slice(0, 1) };
  writeFileSync(new URL(`./fixtures/${name}.json`, import.meta.url), JSON.stringify(media(body)) + "\n");
  console.log(name, response.status);
}
