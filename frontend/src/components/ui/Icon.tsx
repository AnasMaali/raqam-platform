/*
 * The site's interface icons, drawn on a 24 px grid with one stroke (1.75, round ends). Never a typed glyph or emoji.
 * Directional icons are drawn for a right-to-left page: "end" points to the end of the line, the left.
 */

const paths = {
  menu: "M4 7h16M4 12h16M4 17h16",
  close: "M6 6l12 12M18 6 6 18",
  search: "M10.5 17a6.5 6.5 0 1 0 0-13 6.5 6.5 0 0 0 0 13ZM15.3 15.3 20 20",
  sun: "M12 16.5a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9ZM12 2.5v2M12 19.5v2M4.6 4.6 6 6M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4",
  moon: "M20 14.6A8.5 8.5 0 0 1 9.4 4 8.5 8.5 0 1 0 20 14.6Z",
  end: "M14.5 5.5 8 12l6.5 6.5",
  down: "M6 9.5l6 6 6-6",
  up: "M12 19V5.5M6.5 11 12 5.5 17.5 11",
  // Play points right in every language: it is the player's sign, not a direction of reading.
  play: "M8 5.5v13l10.5-6.5L8 5.5Z",
  download: "M12 4.5v11M7 11l5 5 5-5M5 19.5h14",
  expand: "M14.5 4.5h5v5M9.5 19.5h-5v-5M19.5 4.5 13.5 10.5M4.5 19.5l6-6",
  phone: "M6.5 3.5h3l1.5 4-2 1.5a11 11 0 0 0 6 6l1.5-2 4 1.5v3a2 2 0 0 1-2 2A16 16 0 0 1 4.5 5.5a2 2 0 0 1 2-2Z",
  mail: "M4 6.5h16v11H4zM4.5 7l7.5 6 7.5-6",
  // A speech bubble: the message apps' shared sign, never one app's logo.
  chat: "M5 18.5 4 21l3.5-1.5A8.5 8.5 0 1 0 5 18.5ZM8.5 12h.01M12 12h.01M15.5 12h.01",
  pin: "M12 21s-6.5-6-6.5-11a6.5 6.5 0 0 1 13 0c0 5-6.5 11-6.5 11ZM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z",
} as const;

export type IconName = keyof typeof paths;

export function Icon({ name, size = 20, className }: { name: IconName; size?: number; className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      width={size}
      height={size}
      fill="none"
      stroke="currentColor"
      strokeWidth="1.75"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
      className={className}
    >
      <path d={paths[name]} />
    </svg>
  );
}
