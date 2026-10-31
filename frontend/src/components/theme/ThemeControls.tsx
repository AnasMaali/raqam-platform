"use client";

import { useId, useSyncExternalStore } from "react";

import { Icon } from "../ui/Icon";

import { choose, snapshot, subscribe, type Choice } from "./theme";

/** The choice and the theme it gives; unknown while the page is drawn on the server. */
function useTheme(): { choice: Choice; dark: boolean } | null {
  const value = useSyncExternalStore(subscribe, snapshot, () => null);
  if (!value) return null;
  const [choice, resolved] = value.split(":") as [Choice, "light" | "dark"];

  return { choice, dark: resolved === "dark" };
}

/**
 * The header's switch between light and dark. Its icon shows the theme it leads to and is drawn by CSS, so it is
 * right before the script runs.
 */
export function ThemeToggle() {
  const theme = useTheme();

  return (
    <button
      type="button"
      aria-label="الوضع الداكن"
      aria-pressed={theme ? theme.dark : undefined}
      onClick={() => choose(theme?.dark ? "light" : "dark")}
      className="inline-flex size-11 items-center justify-center rounded-sm hover:bg-tint"
    >
      <Icon name="moon" className="dark:hidden" />
      <Icon name="sun" className="hidden dark:block" />
    </button>
  );
}

const options: { value: Choice; label: string }[] = [
  { value: "system", label: "حسب الجهاز" },
  { value: "light", label: "فاتح" },
  { value: "dark", label: "داكن" },
];

/** The full choice, including going back to the device's setting: in the footer and the phone menu. */
export function ThemeChoice() {
  const theme = useTheme();
  const name = useId();

  return (
    <fieldset>
      <legend className="text-small font-bold">المظهر</legend>
      <div className="mt-2 inline-flex rounded-sm border border-line-strong p-0.5">
        {options.map((option) => (
          <label
            key={option.value}
            className="relative inline-flex min-h-11 cursor-pointer items-center rounded-xs px-3 text-small has-checked:bg-brand has-checked:text-on-brand has-focus-visible:outline-2 has-focus-visible:outline-brand"
          >
            <input
              type="radio"
              name={name}
              value={option.value}
              checked={theme?.choice === option.value}
              onChange={() => choose(option.value)}
              className="sr-only"
            />
            {option.label}
          </label>
        ))}
      </div>
    </fieldset>
  );
}
