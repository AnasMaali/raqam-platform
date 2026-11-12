/*
 * The chatbot's answers, as the site shows them (18.3, P1 decision): plain text, with links only to the site's own
 * pages. The platform's `html` is never put on the page; its links are read out of it, and a link that leaves the
 * site, `javascript:` or anything else that is not a path of this site stays as text. The site's server reads every
 * answer this way, and the chat window checks each link again before drawing it.
 */

export type ChatButton = { id: number; label: string };

/** One link of an answer: `path` is a path of this site, or null when the link is shown as text only. */
export type ChatLink = { label: string; path: string | null };

export type ChatReply = {
  kind: "answer" | "live" | "no_answer";
  text: string;
  links: ChatLink[];
  buttons: ChatButton[];
  /** The ready WhatsApp message (18.8), only ever a wa.me link. */
  whatsapp: string | null;
};

export type ChatConfig = { enabled: boolean; welcome: string; buttons: ChatButton[]; maxLength: number };

const maxText = 4000;
const maxLabel = 120;

/**
 * A path of this site, or null: it starts with one "/" (never "//" or "/\", which leave the site), holds no space or
 * control character, and is not too long. An absolute https link to this site's own origin becomes its path.
 */
export function sitePath(href: unknown, siteOrigin?: string): string | null {
  if (typeof href !== "string" || href.length > 500) return null;
  let path = href.trim();
  if (siteOrigin && /^https:\/\//i.test(path)) {
    try {
      const url = new URL(path);
      if (url.origin !== siteOrigin) return null;
      path = `${url.pathname}${url.search}${url.hash}`;
    } catch {
      return null;
    }
  }
  if (!/^\/(?![/\\])/.test(path) || /[\s\u0000-\u001f\u007f\\]/.test(path)) return null;

  return path;
}

/** Only a https://wa.me/… link is a WhatsApp link. */
export function whatsappLink(href: unknown): string | null {
  if (typeof href !== "string" || href.length > 2000) return null;
  try {
    const url = new URL(href);

    return url.protocol === "https:" && url.host === "wa.me" ? url.href : null;
  } catch {
    return null;
  }
}

const entities: Record<string, string> = { amp: "&", lt: "<", gt: ">", quot: '"', apos: "'", nbsp: " " };

/** Text out of a piece of markup: tags dropped, the common entities read back. Never put on the page as markup. */
function textOf(html: string): string {
  return html
    .replace(/<[^>]*>/g, "")
    .replace(/&(#\d{1,7}|#x[0-9a-f]{1,6}|[a-z]+);/gi, (whole, name: string) => {
      if (name[0] === "#") {
        const code = name[1] === "x" || name[1] === "X" ? parseInt(name.slice(2), 16) : parseInt(name.slice(1), 10);
        return code > 0 && code <= 0x10ffff ? String.fromCodePoint(code) : "";
      }
      return entities[name.toLowerCase()] ?? whole;
    })
    .trim();
}

function label(value: unknown): string {
  return typeof value === "string" ? textOf(value).slice(0, maxLabel) : "";
}

function buttonsOf(value: unknown): ChatButton[] {
  if (!Array.isArray(value)) return [];

  return value
    .filter((b): b is { id: number; label: string } => !!b && Number.isInteger(b.id) && b.id > 0 && typeof b.label === "string")
    .slice(0, 12)
    .map((b) => ({ id: b.id, label: label(b.label) }))
    .filter((b) => b.label);
}

/** The platform's answer as the site shows it. */
export function readReply(raw: unknown, siteOrigin?: string): ChatReply {
  const reply = (raw && typeof raw === "object" ? raw : {}) as Record<string, unknown>;
  const kind = reply.kind === "answer" || reply.kind === "live" ? reply.kind : "no_answer";
  const html = typeof reply.html === "string" ? reply.html : "";
  const text = (typeof reply.text === "string" ? reply.text : textOf(html)).slice(0, maxText).trim();

  // The answer's own links (the knowledge base writes them in the answer), then the listed ones (an action, the closest
  // posts). The same path once.
  const links: ChatLink[] = [];
  const seen = new Set<string>();
  const add = (href: unknown, name: string) => {
    if (!name) return;
    const path = sitePath(href, siteOrigin);
    const key = path ?? `text:${name}`;
    if (seen.has(key)) return;
    seen.add(key);
    links.push({ label: name, path });
  };
  for (const [, href, inner] of html.matchAll(/<a\s[^>]*?href\s*=\s*"([^"]*)"[^>]*>([\s\S]*?)<\/a>/gi)) {
    // A link in the answer's text that leaves the site is already there as text; only the site's own become links.
    if (sitePath(textOf(href), siteOrigin)) add(textOf(href), label(inner));
  }
  if (Array.isArray(reply.links)) {
    for (const link of reply.links.slice(0, 12)) {
      if (link && typeof link === "object") add((link as Record<string, unknown>).url, label((link as Record<string, unknown>).label));
    }
  }

  return { kind, text, links: links.slice(0, 12), buttons: buttonsOf(reply.buttons), whatsapp: whatsappLink(reply.whatsapp) };
}

/** GET /chat as the site uses it. */
export function readConfig(raw: unknown): ChatConfig {
  const data = (raw && typeof raw === "object" ? raw : {}) as Record<string, unknown>;
  const maxLength = Number.isInteger(data.max_length) && (data.max_length as number) > 0 ? Math.min(data.max_length as number, 500) : 500;

  return {
    enabled: data.enabled === true,
    welcome: typeof data.welcome === "string" && data.welcome.trim() ? data.welcome.trim().slice(0, 1000) : "أهلاً بك! اسألني أو اختر من الأزرار.",
    buttons: buttonsOf(data.buttons),
    maxLength,
  };
}
