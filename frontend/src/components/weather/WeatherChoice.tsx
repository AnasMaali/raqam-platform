"use client";

import { useEffect } from "react";

import { rememberedCity } from "./city-choice";

/**
 * Shows the city the visitor chose (12.4) in a weather widget drawn on the server: it only moves the `hidden`
 * attributes, on the boxes and on the list's lines, so the list keeps its length. Nothing happens without a remembered
 * city, with one the widget does not hold, or with the site's storage blocked.
 */
export function WeatherChoice({ widget }: { widget: string }) {
  useEffect(() => {
    const slug = rememberedCity();
    const root = document.getElementById(widget);
    const box = root?.querySelector<HTMLElement>(`[data-city="${CSS.escape(slug ?? "")}"]`);
    if (!root || !slug || !box) return;
    const usual = root.querySelector<HTMLElement>("[data-city]:not([hidden])")?.dataset.city;
    for (const other of root.querySelectorAll<HTMLElement>("[data-city]")) other.hidden = other !== box;
    const lines = [...root.querySelectorAll<HTMLElement>("[data-city-item]")];
    // The chosen city's own line; or, when the list does not hold it, the last line other than the usual city's (which
    // now belongs in the list), so the length stays the same.
    const off = lines.find((l) => l.dataset.cityItem === slug) ?? lines.filter((l) => l.dataset.cityItem !== usual).at(-1);
    for (const line of lines) line.hidden = line === off;
  }, [widget]);

  return null;
}
