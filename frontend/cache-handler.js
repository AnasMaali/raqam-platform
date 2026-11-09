// @ts-check
/*
 * The cache of rendered pages (ISR) and route answers, kept in this server's memory.
 *
 * Next.js's built-in cache writes each page to a file named after its address. An article's address holds its Arabic
 * slug, percent-encoded at six bytes a letter, and a long headline passes the file system's 255-byte limit for one
 * name: the write fails (ENAMETOOLONG) and the page is rendered again on every visit. Here the key is only a key, so
 * any address can be kept.
 *
 * - At most PAGE_CACHE_MAX_MB (default 256) of pages; the least recently used go first.
 * - A tag the platform's webhook refreshes (revalidateTag) empties every page that carries it, as Next.js's own cache
 *   does: the next visit renders it again.
 * - A restart starts empty: pages are rendered again on their next visit. One server (DEPLOY: a second instance would
 *   need a shared store, e.g. Redis).
 */

const maxBytes = (Number(process.env.PAGE_CACHE_MAX_MB) || 256) * 1024 * 1024;
const debug = process.env.PAGE_CACHE_DEBUG === "1";

/** @type {Map<string, { value: any, lastModified: number, cacheControl: any, size: number }>} */
const entries = new Map();
let used = 0;

/** @type {Map<string, { stale?: number, expired?: number }>} */
const tags = new Map();

const length = (/** @type {any} */ data) => (data == null ? 0 : typeof data === "string" || Buffer.isBuffer(data) ? data.length : 0);

/** A rough size of what is kept, as Next.js estimates its own memory cache. */
function sizeOf(/** @type {string} */ key, /** @type {any} */ value) {
  let size = 25;
  if (value?.kind === "APP_PAGE") {
    size = length(value.html) + length(value.rscData) + length(value.postponed);
    for (const [path, buffer] of value.segmentData ?? []) size += path.length + length(buffer);
  } else if (value?.kind === "APP_ROUTE") size = length(value.body);
  else if (value?.kind === "PAGES") size = length(value.html) + JSON.stringify(value.pageData ?? "").length;
  else if (value) size = JSON.stringify(value.data ?? value.props ?? "").length;

  return key.length + Math.max(1, size);
}

/** A tag refreshed after the entry was made, and due now, empties it (Next.js's areTagsExpired). */
function expired(/** @type {string[]} */ list, /** @type {number} */ madeAt) {
  const now = Date.now();
  for (const tag of list) {
    const at = tags.get(tag)?.expired;
    if (typeof at === "number" && at <= now && at > madeAt) return true;
  }

  return false;
}

function forget(/** @type {string} */ key) {
  const entry = entries.get(key);
  if (entry) {
    used -= entry.size;
    entries.delete(key);
  }
}

module.exports = class MemoryCacheHandler {
  constructor() {}

  /** @param {string} key @param {any} ctx */
  async get(key, ctx) {
    const entry = entries.get(key);
    if (debug) console.log(`[page cache] get ${ctx?.kind} ${key} ${entry ? `found, ${Math.round((Date.now() - entry.lastModified) / 1000)} s old` : "missing"}`);
    if (!entry) return null;
    // Most recently used goes last.
    entries.delete(key);
    entries.set(key, entry);

    const value = entry.value;
    const own =
      value && (value.kind === "APP_PAGE" || value.kind === "APP_ROUTE" || value.kind === "PAGES")
        ? String(value.headers?.["x-next-cache-tags"] ?? "").split(",").filter(Boolean)
        : [...(ctx?.tags ?? []), ...(ctx?.softTags ?? [])];
    if (expired(own, entry.lastModified)) {
      forget(key);

      return null;
    }

    return { lastModified: entry.lastModified, value, cacheControl: entry.cacheControl };
  }

  /** @param {string} key @param {any} data @param {any} ctx */
  async set(key, data, ctx) {
    forget(key);
    const size = sizeOf(key, data);
    if (debug) console.log(`[page cache] set ${data?.kind} ${key} ${size} bytes, revalidate ${ctx?.cacheControl?.revalidate}`);
    if (size > maxBytes) return;
    while (used + size > maxBytes && entries.size > 0) forget(/** @type {string} */ (entries.keys().next().value));
    entries.set(key, { value: data, lastModified: Date.now(), cacheControl: ctx?.fetchCache ? undefined : ctx?.cacheControl, size });
    used += size;
  }

  /** @param {string | string[]} list @param {{ expire?: number } | undefined} durations */
  async revalidateTag(list, durations) {
    const now = Date.now();
    for (const tag of typeof list === "string" ? [list] : list) {
      const entry = { ...tags.get(tag) };
      if (durations) {
        entry.stale = now;
        if (durations.expire !== undefined) entry.expired = now + durations.expire * 1000;
      } else entry.expired = now;
      tags.set(tag, entry);
    }
  }

  resetRequestCache() {}
};
