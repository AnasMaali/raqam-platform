// Checks the colour tokens of src/app/globals.css against WCAG AA, for light and dark:
//   - every pair the site draws text or controls with reaches its ratio (4.5 for text, 3 for large text and controls);
//   - the two copies of the dark values (the device's setting, the reader's choice) are the same;
//   - DESIGN.md's contrast table is exactly the table these values give (`--write` rewrites it);
//   - no component writes a colour by hand ("bg-[#…]", "text-[rgb(…)]") instead of a token.
import { readFileSync, readdirSync, statSync, writeFileSync } from "node:fs";
import { join, relative } from "node:path";

const root = new URL("..", import.meta.url).pathname;
const css = readFileSync(join(root, "src/app/globals.css"), "utf8");
const designPath = join(root, "../DESIGN.md");

function block(selector) {
  const start = css.indexOf(selector);
  if (start < 0) throw new Error(`globals.css has no ${selector}`);
  const open = css.indexOf("{", start);
  const close = css.indexOf("}", open);
  const values = {};
  for (const [, name, value] of css.slice(open + 1, close).matchAll(/--([a-z-]+):\s*(#[0-9a-f]{6})\s*;/gi)) values[name] = value.toLowerCase();

  return values;
}

const light = block(":root {");
const darkByDevice = block(':root:not([data-theme="light"]) {');
const darkByChoice = block(':root[data-theme="dark"] {');
const failures = [];

if (JSON.stringify(darkByDevice) !== JSON.stringify(darkByChoice)) {
  failures.push("The dark values differ between the device's setting and the reader's choice.");
}

const luminance = (hex) => {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const ratio = (a, b) => {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
};

// [foreground, background, minimum, what it is used for]
const pairs = [
  ["ink", "ground", 4.5, "Text"],
  ["ink", "surface", 4.5, "Text on the footer and picture frames"],
  ["ink", "tint", 4.5, "Text on a tab"],
  ["muted", "ground", 4.5, "Secondary text"],
  ["muted", "surface", 4.5, "Secondary text in the footer"],
  ["muted", "breaking-surface", 4.5, "The time in the breaking bar"],
  ["brand", "ground", 4.5, "Links, chapter names, focus ring"],
  ["brand", "surface", 4.5, "Links in the footer"],
  ["brand", "tint", 4.5, "A chapter tab's name"],
  ["on-brand", "brand", 4.5, "Text on a brand-coloured control"],
  ["ink", "breaking-surface", 4.5, "The breaking headline"],
  ["ground", "breaking", 4.5, "The «عاجل» label"],
  ["breaking", "ground", 4.5, "«عاجل» in a card's line"],
  ["rise", "ground", 4.5, "A rise"],
  ["fall", "ground", 4.5, "A fall"],
  ["success", "ground", 4.5, "A success message"],
  ["error", "ground", 4.5, "An error message"],
  ["line-strong", "ground", 3, "A control's border"],
  ["line-strong", "surface", 3, "A control's border in the footer"],
];

const rows = pairs.map(([fg, bg, min, use]) => {
  const l = ratio(light[fg], light[bg]);
  const d = ratio(darkByChoice[fg], darkByChoice[bg]);
  for (const [theme, value, palette] of [["light", l, light], ["dark", d, darkByChoice]]) {
    if (value < min) failures.push(`${fg} on ${bg} (${theme}: ${palette[fg]} on ${palette[bg]}) is ${value.toFixed(2)}, needs ${min}.`);
  }
  return `| \`${fg}\` on \`${bg}\` | ${l.toFixed(2)} | ${d.toFixed(2)} | ${min} | ${use} |`;
});
const table = ["| Pair | Light | Dark | Needs | Use |", "|---|---|---|---|---|", ...rows].join("\n");

const design = readFileSync(designPath, "utf8");
const marker = /(<!-- contrast:start -->)[\s\S]*?(<!-- contrast:end -->)/;
if (!marker.test(design)) {
  failures.push("DESIGN.md has no <!-- contrast:start --> … <!-- contrast:end --> table.");
} else if (process.argv.includes("--write")) {
  writeFileSync(designPath, design.replace(marker, `$1\n${table}\n$2`));
  console.log("DESIGN.md contrast table written.");
} else if (!design.includes(`<!-- contrast:start -->\n${table}\n<!-- contrast:end -->`)) {
  failures.push("DESIGN.md's contrast table does not match globals.css. Run: npm run check:tokens -- --write");
}

// Colours written by hand in a component, outside the design studies of steps 3 to 5.
const studies = ["src/app/lab/_home", "src/app/lab/home-", "src/app/lab/fonts", "src/app/lab/brand", "src/app/lab/page.tsx"];
const walk = (dir) => readdirSync(dir).flatMap((name) => (statSync(join(dir, name)).isDirectory() ? walk(join(dir, name)) : [join(dir, name)]));
for (const file of walk(join(root, "src")).filter((f) => f.endsWith(".tsx"))) {
  const path = relative(root, file);
  if (studies.some((s) => path.startsWith(s))) continue;
  for (const [match] of readFileSync(file, "utf8").matchAll(/\b[a-z-]+-\[(?:#|rgb|hsl|oklch|color-mix|var\(--)[^\]]*\]/g)) {
    failures.push(`${path}: "${match}" writes a colour by hand; use a token.`);
  }
}

if (failures.length > 0) {
  console.error(failures.map((f) => `✗ ${f}`).join("\n"));
  process.exit(1);
}
console.log(`Colour tokens: ${pairs.length} pairs pass AA in light and dark; DESIGN.md matches; no hand-written colours.`);
