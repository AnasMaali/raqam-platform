// Captures the video library and the awareness characters for the fake API from a local platform (run from
// frontend/: node tests/fake-api/capture-video.mjs; reads .env.local). Two pages of the library, the sections' and the
// playlists' videos, every playlist, every video those name, and each character with its cards.
import { readFileSync, writeFileSync } from "node:fs";

const env = Object.fromEntries(
  readFileSync(new URL("../../.env.local", import.meta.url), "utf8")
    .split("\n")
    .filter((line) => line.includes("=") && !line.startsWith("#"))
    .map((line) => [line.slice(0, line.indexOf("=")), line.slice(line.indexOf("=") + 1).trim()]),
);
const base = env.API_BASE_URL.replace(/\/+$/, "");
const origin = new URL(base).origin;
const get = async (path) => (await fetch(base + path, { headers: { Accept: "application/json", "X-Raqam-Site-Token": env.API_SITE_TOKEN } })).json();
const media = (value) => {
  if (Array.isArray(value)) return value.map(media);
  if (value && typeof value === "object") {
    const out = {};
    for (const [key, inner] of Object.entries(value)) {
      if (key === "variants") out.variants = Object.fromEntries(Object.entries(inner).filter(([size]) => ["sm", "md", "lg"].includes(size)).map(([k, v]) => [k, media(v)]));
      else out[key] = media(inner);
    }
    return out;
  }
  if (typeof value === "string" && value.startsWith(`${origin}/storage/`)) return `http://127.0.0.1:4010/media/${value.split("/").pop()}`;
  return value;
};

const library = {};
for (const page of [1, 2]) library[`page=${page}`] = media(await get(`/videos?page=${page}`));
const sections = ["podcast", "round-table"];
for (const section of sections) library[`section=${section}`] = media(await get(`/videos?section=${section}`));

const playlists = media(await get("/playlists"));
const playlist = {};
for (const p of playlists.data) playlist[p.slug] = media(await get(`/playlists/${encodeURIComponent(p.slug)}`));

const ids = new Set([...Object.values(library).flatMap((l) => l.data.map((v) => v.id)), ...Object.values(playlist).flatMap((p) => p.data.videos.map((v) => v.id)), 1, 2]);
const video = {};
for (const id of ids) video[id] = media(await get(`/videos/${id}`));

const characters = media(await get("/characters"));
const character = {};
for (const c of characters.data) {
  character[c.slug] = { detail: media(await get(`/characters/${c.slug}`)), cards: media(await get(`/characters/${c.slug}/cards`)) };
}

writeFileSync(new URL("./fixtures/video.json", import.meta.url), JSON.stringify({ library, playlists, playlist, video, characters, character }) + "\n");
console.log("library", Object.keys(library).length, "playlists", playlists.data.length, "videos", ids.size, "characters", characters.data.length);
