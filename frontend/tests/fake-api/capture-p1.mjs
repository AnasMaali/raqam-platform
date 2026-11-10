// Captures the team, the questions and the «أعلن معنا» page for the fake API from a local platform (run from
// frontend/: node tests/fake-api/capture-p1.mjs; reads API_BASE_URL and API_SITE_TOKEN from .env.local).
//
// The demo has no contact settings and no writer's links; the fixture fills those with made-up values (a fictional
// phone number, example.org), and takes one writer's photo away, so every state is drawn.
import { readFileSync, writeFileSync } from "node:fs";

const env = Object.fromEntries(
  readFileSync(new URL("../../.env.local", import.meta.url), "utf8")
    .split("\n")
    .filter((line) => line.includes("=") && !line.startsWith("#"))
    .map((line) => [line.slice(0, line.indexOf("=")), line.slice(line.indexOf("=") + 1).trim()]),
);
const base = env.API_BASE_URL.replace(/\/+$/, "");
const storage = new URL(base).origin + "/storage/";
const get = async (path) => (await fetch(base + path, { headers: { Accept: "application/json", "X-Raqam-Site-Token": env.API_SITE_TOKEN } })).json();

// Pictures point at the fake API's one placeholder; only the sizes the site asks for are kept.
const media = (value) => {
  if (Array.isArray(value)) return value.map(media);
  if (value && typeof value === "object") {
    const out = {};
    for (const [key, inner] of Object.entries(value)) {
      if (key === "variants") out.variants = Object.fromEntries(Object.entries(inner).filter(([size]) => ["thumb", "sm", "md", "lg"].includes(size)).map(([k, v]) => [k, media(v)]));
      else out[key] = media(inner);
    }
    return out;
  }
  if (typeof value === "string" && value.startsWith(storage)) return `http://127.0.0.1:4010/media/${value.split("/").pop()}`;
  return value;
};

// Made up: a number from the range kept for fiction, and example.org.
const phone = "+15555550123";
const links = (topic) => ({
  whatsapp: `https://wa.me/${phone.slice(1)}?text=${encodeURIComponent(`مرحباً، أود الاستفسار عن «${topic}»`)}`,
  phone: `tel:${phone}`,
  email: `mailto:contact@example.org?subject=${encodeURIComponent(`استفسار: ${topic}`)}`,
});

const team = media(await get("/team"));
team.data[0].links = { website: "https://example.org/team/first", x: "https://example.org/x/first" };
team.data.at(-1).photo = null;

const faqs = await get("/faqs");

const advertise = media(await get("/advertise"));
advertise.data.contact = links("الإعلان في رَقَم");
for (const p of advertise.data.packages) {
  p.contact = links(p.name);
  if (p.price.offer) p.price.offer.ends_at = "2030-12-31T21:59:59Z";
}

writeFileSync(new URL("./fixtures/p1.json", import.meta.url), JSON.stringify({ team, faqs, advertise }) + "\n");
console.log("team", team.data.length, "faqs", faqs.data.length, "places", advertise.data.placements.length, "packages", advertise.data.packages.length);
