/*
 * Light or dark. The device's setting decides until the reader chooses; the choice is kept in this browser only
 * (localStorage, no cookie), and the page follows it before its first paint (themeScript, in the document's head).
 */

export type Choice = "system" | "light" | "dark";

const key = "raqam-theme";
const grounds = { light: "#ffffff", dark: "#0b0e14" };

/**
 * Runs in the head before the page paints. It must stay tiny and free of imports: it is inlined as text. It also
 * points the browser's bar colour at the chosen theme.
 */
export const themeScript = `(function(){try{var t=localStorage.getItem("${key}");if(t==="light"||t==="dark"){var d=document.documentElement;d.dataset.theme=t;var c=t==="dark"?"${grounds.dark}":"${grounds.light}";document.querySelectorAll('meta[name="theme-color"]').forEach(function(m){m.content=c})}}catch(e){}})();`;

const listeners = new Set<() => void>();
const media = () => window.matchMedia("(prefers-color-scheme: dark)");

export function readChoice(): Choice {
  try {
    const stored = window.localStorage.getItem(key);
    return stored === "light" || stored === "dark" ? stored : "system";
  } catch {
    return "system";
  }
}

export function resolve(choice: Choice): "light" | "dark" {
  if (choice !== "system") return choice;

  return media().matches ? "dark" : "light";
}

function paintBrowserBar(choice: Choice) {
  document.querySelectorAll<HTMLMetaElement>('meta[name="theme-color"]').forEach((meta) => {
    const scheme = meta.media.includes("dark") ? "dark" : "light";
    meta.content = grounds[choice === "system" ? scheme : choice];
  });
}

export function choose(choice: Choice) {
  try {
    if (choice === "system") window.localStorage.removeItem(key);
    else window.localStorage.setItem(key, choice);
  } catch {
    // Storage may be blocked: the choice then lasts for this page only.
  }
  const root = document.documentElement;
  if (choice === "system") delete root.dataset.theme;
  else root.dataset.theme = choice;
  paintBrowserBar(choice);
  listeners.forEach((listener) => listener());
}

/** For useSyncExternalStore: the choice and the theme it gives, as one string ("system:dark"). */
export function snapshot() {
  const choice = readChoice();

  return `${choice}:${resolve(choice)}`;
}

export function subscribe(listener: () => void) {
  listeners.add(listener);
  const onStorage = (event: StorageEvent) => {
    if (event.key === key) choose(readChoice());
  };
  const list = media();
  window.addEventListener("storage", onStorage);
  list.addEventListener("change", listener);

  return () => {
    listeners.delete(listener);
    window.removeEventListener("storage", onStorage);
    list.removeEventListener("change", listener);
  };
}
