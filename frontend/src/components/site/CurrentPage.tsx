"use client";

import { usePathname } from "next/navigation";
import { useEffect, useSyncExternalStore } from "react";

/*
 * Marks the menu's link to the page being read with aria-current="page", in the header's row and in the phone's sheet.
 * The header is drawn on the server once for every page (and kept in the cache), so the mark is set here, in the
 * browser, after the page shows: it changes a colour and an underline only, never the links' size (no layout shift).
 *
 * A link is current when the address is its own or lies under it (/currencies/usd under /currencies, /video/23 under
 * /video, a podcast episode under /podcast); the longest such link wins. A news item's address does not name its
 * category, so its page says where it belongs with <NavPlace path="/category/…" />.
 */

let place: string | null = null;
const listeners = new Set<() => void>();
const placeStore = {
  subscribe(listener: () => void) {
    listeners.add(listener);

    return () => listeners.delete(listener);
  },
  get: () => place,
  set(value: string | null) {
    place = value;
    listeners.forEach((l) => l());
  },
};

/** Says which menu entry a page belongs to when its address does not (a news item: its category). */
export function NavPlace({ path }: { path: string }) {
  useEffect(() => {
    placeStore.set(path);

    return () => {
      if (placeStore.get() === path) placeStore.set(null);
    };
  }, [path]);

  return null;
}

const plain = (path: string) => {
  try {
    return decodeURI(path).replace(/\/+$/, "") || "/";
  } catch {
    return path;
  }
};

/** The menu link (by its address) the page belongs to, or null. */
export function currentOf(links: string[], pathname: string, belongsTo: string | null): string | null {
  const here = plain(pathname);
  const under = (href: string) => here === href || (href !== "/" && here.startsWith(`${href}/`));
  const own = links.map(plain).filter(under).sort((a, b) => b.length - a.length)[0];
  if (own) return own;
  const there = belongsTo ? plain(belongsTo) : null;

  return there && links.map(plain).includes(there) ? there : null;
}

export function CurrentPage() {
  const pathname = usePathname();
  const belongsTo = useSyncExternalStore(placeStore.subscribe, placeStore.get, () => null);

  useEffect(() => {
    const navs = document.querySelectorAll<HTMLElement>("header nav[data-site-menu]");
    const links = [...navs].flatMap((nav) => [...nav.querySelectorAll<HTMLAnchorElement>("a[href^='/']")]);
    const current = currentOf(
      links.map((a) => a.getAttribute("href")!),
      pathname,
      belongsTo,
    );
    for (const a of links) {
      if (current !== null && plain(a.getAttribute("href")!) === current) a.setAttribute("aria-current", "page");
      else a.removeAttribute("aria-current");
    }
    // A group (the sections) whose list holds the current page shows it on its button too.
    for (const nav of navs) {
      for (const group of nav.querySelectorAll<HTMLElement>("[data-menu-group]")) {
        group.toggleAttribute("data-current", !!group.querySelector("a[aria-current='page']"));
      }
    }
  }, [pathname, belongsTo]);

  return null;
}
