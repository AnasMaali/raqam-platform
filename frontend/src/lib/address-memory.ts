/*
 * The proxy's memory of what the platform said about an address (src/proxy.ts): a page, a move, or nothing there.
 * The proxy and the webhook's route are bundled apart and so load this module apart, but they run in one server
 * process: the memory hangs on that process (globalThis), so the webhook can forget it. Any change the platform
 * announces may move or remove an address (a post renamed, a page taken down), and a minute of the old answer would
 * serve the old page at an address that has moved.
 */

export type Verdict = { kind: "page" } | { kind: "moved"; to: string; status?: 301 | 302 } | { kind: "missing" };

const maxEntries = 5000;
const keepFor = { page: 60_000, moved: 60_000, missing: 30_000 } as const;
const key = Symbol.for("raqam.address-memory");
const shared = globalThis as typeof globalThis & { [key]?: Map<string, { verdict: Verdict; until: number }> };
const answers = (shared[key] ??= new Map());

export function recall(address: string): Verdict | null {
  const entry = answers.get(address);
  if (!entry) return null;
  if (entry.until > Date.now()) return entry.verdict;
  answers.delete(address);

  return null;
}

export function remember(address: string, verdict: Verdict) {
  answers.delete(address);
  // A Map keeps its insertion order: the first key is the oldest.
  while (answers.size >= maxEntries) answers.delete(answers.keys().next().value!);
  answers.set(address, { verdict, until: Date.now() + keepFor[verdict.kind] });
}

/** Everything the proxy remembers, forgotten: the next visit of each address asks the platform again. */
export function forgetAddresses() {
  answers.clear();
}
