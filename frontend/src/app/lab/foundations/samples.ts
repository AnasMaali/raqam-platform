import "server-only";

import { connection } from "next/server";

import { apiGet } from "@/lib/server/api";
import { getAds, getBreaking, getMenus, getRatesBoard, getSite, getTicker, getWeather } from "@/lib/server/site-data";
import type { City, Post } from "@/lib/types";

type Block = { type: string; posts?: Post[] };

/**
 * The live demo content, and the states it lacks made from it: a sponsored item, an item without a picture, a very
 * long headline, a stale weather reading, an unknown rate change, an empty ad slot. Development only.
 */
export async function loadSamples() {
  await connection();
  const [home, menus, site, ticker, board, weather, ads, breaking] = await Promise.all([
    apiGet<{ data: Block[] }>("/home", { cache: "no-store" }),
    getMenus(),
    getSite(),
    getTicker(),
    getRatesBoard(),
    getWeather(),
    getAds(),
    getBreaking(),
  ]);
  const posts = (type: string) => home.data.filter((b) => b.type === type).flatMap((b) => b.posts ?? []);
  const all = home.data.flatMap((b) => b.posts ?? []);
  const lead = posts("hero")[0];
  const story = all.find((p) => p.kind.code === "story") ?? all[1];
  const plain = all.find((p) => p.kind.code === "news" && !p.is_breaking) ?? all[2];
  const longest = [...all].sort((a, b) => b.title.length - a.title.length)[0];

  const withFigure: Post =
    story && !(story.facts as { figures?: unknown[] } | null)?.figures?.length
      ? { ...story, facts: { ...(story.facts ?? {}), figures: [{ value: "120", label: "موظفاً وموظفة" }] } }
      : story;

  const cities: City[] = weather?.cities ?? [];
  const staleWeather = weather && {
    ...weather,
    cities: cities.map((c) => (c.is_default && c.now ? { ...c, now: { ...c.now, stale: true } } : c)),
  };
  const lonelyWeather = weather && { ...weather, cities: cities.filter((c) => c.is_default) };

  const unknownTicker = ticker && {
    ...ticker,
    rates: ticker.rates.map((r, i) => (i === 1 ? { ...r, change_pct: null, direction: null } : i === 2 ? { ...r, mid: null } : r)),
  };

  const slot = (code: string) => ads?.slots.find((s) => s.code === code);
  const leaderboard = slot("header_leaderboard");
  const houseOnly = leaderboard && { ...leaderboard, ads: [] };
  const hidden = leaderboard && { ...leaderboard, ads: [], empty: "hide" as const };

  return {
    menus,
    site,
    ticker,
    unknownTicker,
    board,
    weather,
    staleWeather,
    lonelyWeather,
    adLabel: ads?.label ?? "إعلان",
    leaderboard,
    houseOnly,
    hidden,
    mpu: slot("sidebar_mpu"),
    between: slot("between_sections"),
    breaking: breaking?.length ? breaking : posts("breaking"),
    lead,
    feature: plain,
    figure: withFigure,
    noPicture: plain && { ...plain, cover: null },
    sponsored: plain && { ...plain, id: plain.id + 100000, sponsor: { name: "شركة تجريبية" } },
    long: longest && { ...longest, title: `${longest.title}: ${longest.title}` },
    latest: posts("latest").slice(0, 4),
    mostRead: posts("most_read").length ? posts("most_read").slice(0, 5) : all.slice(0, 5),
    chapters: home.data
      .filter((b) => b.type === "category" || b.type === "section")
      .map((b, i) => ({ id: `bab-${i}`, name: (b as { title?: string }).title ?? "", href: `#bab-${i}` })),
  };
}
