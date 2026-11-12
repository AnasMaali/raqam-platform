/*
 * The visitor's city (decisions 12.4 and 12.5), kept in this browser only: its slug in localStorage, never a position.
 * Every access is guarded: with storage blocked (a private window, a setting, a full disk) the site works the same
 * and shows the default city.
 *
 * The nearest city is computed here from the cities' coordinates. The position is asked for only when the visitor
 * presses the button, and never leaves the browser: no request, event or log carries it.
 */

const key = "raqam:weather-city";

/** The remembered city's slug, or null (none, or storage blocked). */
export function rememberedCity(): string | null {
  try {
    return window.localStorage.getItem(key);
  } catch {
    return null;
  }
}

const listeners = new Set<() => void>();

/** Remembers a city; false when this browser does not let the site keep it. */
export function rememberCity(slug: string): boolean {
  try {
    window.localStorage.setItem(key, slug);
  } catch {
    return false;
  }
  listeners.forEach((l) => l());

  return true;
}

/** For useSyncExternalStore: a change here or in another tab. The server (and the first paint) has no city. */
export const cityStore = {
  subscribe(listener: () => void) {
    listeners.add(listener);
    const onStorage = (event: StorageEvent) => {
      if (event.key === key || event.key === null) listener();
    };
    window.addEventListener("storage", onStorage);

    return () => {
      listeners.delete(listener);
      window.removeEventListener("storage", onStorage);
    };
  },
  get: rememberedCity,
  server: () => null,
};

export type Place = { slug: string; path: string; name: string; latitude: number; longitude: number };

const rad = (degrees: number) => (degrees * Math.PI) / 180;

/** The great-circle distance in km (haversine). */
export function km(a: { latitude: number; longitude: number }, b: { latitude: number; longitude: number }): number {
  const h = Math.sin(rad(b.latitude - a.latitude) / 2) ** 2 + Math.cos(rad(a.latitude)) * Math.cos(rad(b.latitude)) * Math.sin(rad(b.longitude - a.longitude) / 2) ** 2;

  return 2 * 6371 * Math.asin(Math.sqrt(h));
}

/** The city nearest to a position, or null without cities. */
export function nearestCity<T extends Place>(cities: T[], position: { latitude: number; longitude: number }): T | null {
  return cities.reduce<T | null>((best, city) => (best === null || km(position, city) < km(position, best) ? city : best), null);
}
