import type { Post } from "./data";
import { formatDate } from "./ui";

type Org = { name: string; sector: { name: string } | null; city: { name: string } | null };

/** The one line a card shows of an item's own facts (season and episode, company and sector, era and years…). */
export function factsLine(post: Post): string | null {
  const f = post.facts as Record<string, never> | null;
  if (!f) return null;
  switch (post.kind.code) {
    case "episode": {
      const season = f.season as { number: number } | undefined;
      return season ? `الموسم ${season.number} · الحلقة ${f.episode_number}` : null;
    }
    case "story": {
      const org = f.organization as Org | null;
      return org ? [org.name, org.sector?.name, org.city?.name].filter(Boolean).join(" · ") : null;
    }
    case "product": {
      const org = f.organization as Org | null;
      return org ? [org.name, org.city?.name].filter(Boolean).join(" · ") : null;
    }
    case "history": {
      const era = f.era as { name: string } | null;
      const years = f.year_from ? (f.year_to ? `${f.year_from}–${f.year_to}` : `${f.year_from}`) : f.period_label;
      return [era?.name, years].filter(Boolean).join(" · ") || null;
    }
    case "initiative":
      return f.status === "ongoing" ? "مبادرة جارية" : "مبادرة منتهية";
    case "roundtable":
      return f.held_on ? `جلسة ${formatDate(`${f.held_on}T12:00:00Z`)}` : null;
    case "awareness": {
      const character = f.character as { name: string } | undefined;
      return character ? `يحكيها: ${character.name}` : null;
    }
  }

  return null;
}

/** The highlighted figure of a success story, when it has one: "120 موظفاً". */
export function storyFigure(post: Post): { value: string; label: string } | null {
  const figures = (post.facts as { figures?: { value: string; label: string }[] } | null)?.figures;

  return figures?.[0] ?? null;
}

/** The year a history item starts at, for placing it on a timeline. */
export function historyYear(post: Post): number | null {
  const y = (post.facts as { year_from?: number | null } | null)?.year_from;

  return typeof y === "number" ? y : null;
}
