/*
 * Weather conditions, drawn in the interface icons' stroke on a 24 px grid. The API names a condition by its key and
 * the time of day ("rain-night"); the 18 keys share eight drawings.
 */

const sun = "M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM12 3v1.5M12 19.5V21M5.6 5.6l1.1 1.1M17.3 17.3l1.1 1.1M3 12h1.5M19.5 12H21M5.6 18.4l1.1-1.1M17.3 6.7l1.1-1.1";
const moon = "M18.5 14.2A7 7 0 0 1 9.8 5.5a7 7 0 1 0 8.7 8.7Z";
const cloud = "M7 18.5h10a4 4 0 0 0 .6-7.95A5.5 5.5 0 0 0 7.1 9.6 4.5 4.5 0 0 0 7 18.5Z";
const highCloud = "M7 14.5h10a4 4 0 0 0 .6-7.95A5.5 5.5 0 0 0 7.1 5.6 4.5 4.5 0 0 0 7 14.5Z";
const smallSun = "M8.5 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM8.5 1.8v.9M2.8 7.5h.9M4.5 3.5l.6.6M12.5 3.5l-.6.6";
const smallMoon = "M12 9.6A4.6 4.6 0 0 1 6.4 4a4.6 4.6 0 1 0 5.6 5.6Z";
const partCloud = "M10 20h8a3.5 3.5 0 0 0 .5-6.96 4.8 4.8 0 0 0-9.2-.9A3.9 3.9 0 0 0 10 20Z";

const drawings = {
  clear: (day: boolean) => (day ? sun : moon),
  partly: (day: boolean) => `${day ? smallSun : smallMoon}${partCloud}`,
  cloud: () => cloud,
  rain: () => `${highCloud}M9 17.5l-1 3M13 17.5l-1 3M17 17.5l-1 3`,
  thunder: () => `${highCloud}M12.5 15l-2 3.5h3l-2 3.5`,
  snow: () => `${highCloud}M8.5 18.5v.01M12 20v.01M15.5 18.5v.01M10 21.5v.01M14 21.5v.01`,
  fog: () => "M4 9h16M6 12.5h12M4 16h16M7 19.5h10",
  wind: () => "M3 9h11a2.5 2.5 0 1 0-2.5-2.5M3 13h15a2.5 2.5 0 1 1-2.5 2.5M3 17h7",
};

const byKey: Record<string, keyof typeof drawings> = {
  clear: "clear",
  "partly-cloudy": "partly",
  cloudy: "cloud",
  overcast: "cloud",
  drizzle: "rain",
  showers: "rain",
  rain: "rain",
  "rain-heavy": "rain",
  thunder: "thunder",
  "thunder-rain": "thunder",
  snow: "snow",
  sleet: "snow",
  fog: "fog",
  mist: "fog",
  haze: "fog",
  dust: "fog",
  wind: "wind",
  tornado: "wind",
};

/** The drawing for an API icon such as "partly-cloudy-day". Decorative: the condition is always written beside it. */
export function WeatherIcon({ icon, size = 24, className }: { icon: string; size?: number; className?: string }) {
  const day = !icon.endsWith("-night");
  const key = icon.replace(/-(day|night)$/, "");
  const draw = drawings[byKey[key] ?? "cloud"];

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
      <path d={draw(day)} />
    </svg>
  );
}
