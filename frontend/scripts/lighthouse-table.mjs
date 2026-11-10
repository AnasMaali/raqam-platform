// The calibration's table: every page's median LCP, its worst and its spread (the largest minus the smallest), from the
// JSON lines check-lighthouse.mjs writes with LIGHTHOUSE_SUMMARY. Printed as a Markdown table, in the order given by
// the pages' list when one is passed, and added to the run's summary page in CI.
//   node scripts/lighthouse-table.mjs <folder of .jsonl files> [url…]
import { appendFileSync, readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";

const [folder, ...order] = process.argv.slice(2);
if (!folder) {
  console.error("Usage: node scripts/lighthouse-table.mjs <folder> [url…]");
  process.exit(2);
}

const rows = readdirSync(folder, { recursive: true })
  .filter((f) => String(f).endsWith(".jsonl"))
  .flatMap((f) => readFileSync(join(folder, String(f)), "utf8").split("\n").filter(Boolean).map((l) => JSON.parse(l)));
const place = (url) => {
  const i = order.findIndex((u) => decodeURI(new URL(u).href) === url);
  return i === -1 ? order.length : i;
};
rows.sort((a, b) => place(a.url) - place(b.url));

const s = (ms) => (ms / 1000).toFixed(2);
const lines = [
  "| Page | Method | Runs | Median LCP | Worst LCP | Spread | CLS (median) | SEO | Accessibility |",
  "|---|---|---|---|---|---|---|---|---|",
  ...rows.map((r) => {
    const sorted = [...r.lcps].sort((a, b) => a - b);
    const median = sorted[Math.floor((sorted.length - 1) / 2)];
    return `| ${decodeURI(new URL(r.url).pathname)} | ${r.method} | ${sorted.length} | ${s(median)} | ${s(sorted.at(-1))} | ${s(sorted.at(-1) - sorted[0])} | ${r.cls.toFixed(3)} | ${Math.round(r.seo * 100)} | ${Math.round(r.accessibility * 100)} |`;
  }),
];
if (rows.length > 0) {
  const worst = Math.max(...rows.flatMap((r) => r.lcps));
  lines.push("", `Highest worst LCP: ${s(worst)} s.`);
}

console.log(lines.join("\n"));
if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, `${lines.join("\n")}\n`);
