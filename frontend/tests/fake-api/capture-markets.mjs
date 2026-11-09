// Captures the markets and weather fixtures of the fake API from a local platform (run from frontend/:
// node tests/fake-api/capture-markets.mjs; reads API_BASE_URL and API_SITE_TOKEN from .env.local). Kept apart from
// capture.mjs so the rates and the weather can be captured again without touching the content fixtures.
//
// The rates keep the board already captured in currencies.json: each currency's 30-day history ends on the board's
// own rates day, so the board, the converter and the history tell the same story. The weather is captured whole (the
// widget and every city), and one city is made stale, a state a fresh capture never has.
import { readFileSync, writeFileSync } from "node:fs";

const env = Object.fromEntries(
  readFileSync(new URL("../../.env.local", import.meta.url), "utf8")
    .split("\n")
    .filter((line) => line.includes("=") && !line.startsWith("#"))
    .map((line) => [line.slice(0, line.indexOf("=")), line.slice(line.indexOf("=") + 1).trim()]),
);
const base = env.API_BASE_URL.replace(/\/+$/, "");
const get = async (path) => (await fetch(base + path, { headers: { Accept: "application/json", "X-Raqam-Site-Token": env.API_SITE_TOKEN } })).json();
const fixture = (name) => JSON.parse(readFileSync(new URL(`./fixtures/${name}.json`, import.meta.url), "utf8"));

const board = fixture("currencies");
const day = board.meta.rate_date;
const byCode = Object.fromEntries(board.data.map((r) => [r.code, r]));

// The converter as the platform answers it, with the board's rates.
const converter = await get("/currencies/converter");
converter.data.currencies = converter.data.currencies.map((c) => ({ ...c, buy: byCode[c.code]?.buy ?? c.buy, sell: byCode[c.code]?.sell ?? c.sell }));
converter.meta = board.meta;

const history = {};
for (const { code } of board.data) {
  const body = await get(`/currencies/${code.toLowerCase()}/history?days=60`);
  // Up to the board's day, the last 30 days of it, and the board's own rate on its day.
  const points = body.data.points.filter((p) => p.date <= day && p.date > shift(day, -30));
  const last = points.at(-1);
  if (last?.date === day) last.mid = byCode[code].mid;
  history[code] = { data: { ...body.data, points }, meta: board.meta };
  console.log("history", code, points.length);
}

function shift(date, days) {
  const d = new Date(`${date}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);

  return d.toISOString().slice(0, 10);
}

const widget = await get("/weather/widget");
const cities = await get("/weather/cities");
const city = {};
for (const c of cities.data) {
  city[c.slug] = await get(`/weather/${encodeURIComponent(c.slug)}`);
  console.log("city", c.slug, city[c.slug].data.forecast.length);
}

// One city's reading is old: the widget and its page say when it was taken.
const stale = cities.data.find((c) => !c.is_default && city[c.slug].data.now)?.slug;
if (stale) {
  const at = new Date(Date.parse(city[stale].data.now.observed_at) - 5 * 3600_000).toISOString().replace(/\.\d+Z$/, "Z");
  city[stale].data.now = { ...city[stale].data.now, stale: true, observed_at: at };
  widget.data = widget.data.map((c) => (c.slug === stale ? { ...c, now: { ...c.now, stale: true, observed_at: at } } : c));
}

writeFileSync(new URL("./fixtures/weather.json", import.meta.url), JSON.stringify(widget) + "\n");
writeFileSync(new URL("./fixtures/markets.json", import.meta.url), JSON.stringify({ converter, history, cities, city, stale }) + "\n");
console.log("markets: stale city", stale);
