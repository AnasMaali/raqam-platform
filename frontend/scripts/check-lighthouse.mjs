// Lighthouse on a phone (its default: a mid-range phone on a slow 4G line, simulated) against the production server.
// Fails unless SEO is 100, accessibility is at least 95, LCP is under 2.5 s and CLS under 0.1, on every page given.
// Each page runs three times and the run with the median LCP counts (Lighthouse CI does the same): one run on a shared
// CI machine can swing by half a second (LIGHTHOUSE_RUNS sets another number).
// Each line also names the LCP element, the four parts of its time, and how many of the site's scripts had started
// before it showed: the simulation counts a script that started before the LCP as part of it, so a page whose largest
// element is text in the first frame can land on either side of a script by a few milliseconds.
//   node scripts/check-lighthouse.mjs http://127.0.0.1:3100/ [more urls…]
import { execFileSync } from "node:child_process";
import { existsSync, mkdtempSync, readFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

import { chromium } from "playwright";

// Percent-encoded, so an Arabic address reaches the browser as one argument.
const urls = process.argv.slice(2).map((u) => new URL(u).href);
if (urls.length === 0) {
  console.error("Usage: node scripts/check-lighthouse.mjs <url> [url…]");
  process.exit(2);
}

const budget = { seo: 1, accessibility: 0.95, lcp: 2500, cls: 0.1 };
const dir = mkdtempSync(join(tmpdir(), "lighthouse-"));
const lighthouse = new URL("../node_modules/.bin/lighthouse", import.meta.url).pathname;
const failures = [];
const runs = Math.max(1, Number(process.env.LIGHTHOUSE_RUNS ?? 3));

function run(url, out) {
  execFileSync(
    lighthouse,
    [url, "--quiet", "--output=json", `--output-path=${out}`, "--save-assets", "--only-categories=performance,accessibility,seo", "--chrome-flags=--headless=new --no-sandbox"],
    // From a temporary folder: on WSL the browser's profile folder can land in the working directory.
    { cwd: dir, env: { ...process.env, CHROME_PATH: chromium.executablePath() }, stdio: ["ignore", "ignore", "inherit"] },
  );

  const report = JSON.parse(readFileSync(out, "utf8"));
  report.scriptsBeforeLcp = scriptsBeforeLcp(out.replace(/\.json$/, "-0.trace.json"));

  return report;
}

/** The site's scripts (by file name) whose evaluation started before the page's last LCP candidate, from the trace. */
function scriptsBeforeLcp(tracePath) {
  if (!existsSync(tracePath)) return null;
  const events = JSON.parse(readFileSync(tracePath, "utf8")).traceEvents;
  const lcp = events.filter((e) => e.name === "largestContentfulPaint::Candidate").at(-1);
  if (!lcp) return null;
  const names = events
    .filter((e) => e.name === "EvaluateScript" && e.pid === lcp.pid && e.ts < lcp.ts && /\.js(\?|$)/.test(e.args?.data?.url ?? ""))
    .map((e) => new URL(e.args.data.url).pathname.split("/").pop());

  return [...new Set(names)];
}

/** The LCP element and the four parts of its time (to the first byte, until its resource was asked for, its download, its drawing). */
function lcpDetail(report) {
  const items = report.audits["lcp-breakdown-insight"]?.details?.items ?? [];
  const parts = (items.find((i) => i.type === "table")?.items ?? []).map((p) => `${p.subpart} ${Math.round(p.duration)} ms`).join(", ");
  const node = items.find((i) => i.type === "node");
  const scripts = report.scriptsBeforeLcp;

  return `element ${node?.selector ?? "?"}; ${parts}; scripts started before it: ${scripts === null ? "?" : scripts.length}`;
}

for (const [i, url] of urls.entries()) {
  const reports = Array.from({ length: runs }, (_, r) => run(url, join(dir, `report-${i}-${r}.json`)));
  const byLcp = [...reports].sort((a, b) => a.audits["largest-contentful-paint"].numericValue - b.audits["largest-contentful-paint"].numericValue);
  const report = byLcp[Math.floor((byLcp.length - 1) / 2)];
  const score = (key) => report.categories[key].score;
  const lcp = report.audits["largest-contentful-paint"].numericValue;
  const cls = report.audits["cumulative-layout-shift"].numericValue;
  // What the LCP is made of, so a slow run on a shared machine can be read: the server's answer, the first paint, the
  // main thread's blocking.
  const parts = `FCP ${(report.audits["first-contentful-paint"].numericValue / 1000).toFixed(2)} s, TTFB ${Math.round(report.audits["server-response-time"]?.numericValue ?? 0)} ms, TBT ${Math.round(report.audits["total-blocking-time"].numericValue)} ms`;
  const all = runs > 1 ? ` (LCP of the ${runs} runs: ${byLcp.map((r) => (r.audits["largest-contentful-paint"].numericValue / 1000).toFixed(2)).join(", ")} s)` : "";
  console.log(
    `${url}: performance ${Math.round(score("performance") * 100)}, accessibility ${Math.round(score("accessibility") * 100)}, SEO ${Math.round(score("seo") * 100)}, LCP ${(lcp / 1000).toFixed(2)} s, CLS ${cls.toFixed(3)} (${parts})${all}`,
  );
  console.log(`  LCP ${lcpDetail(report)}`);
  if (runs > 1) for (const r of byLcp) console.log(`    run: LCP ${(r.audits["largest-contentful-paint"].numericValue / 1000).toFixed(2)} s, scripts started before it: ${r.scriptsBeforeLcp?.length ?? "?"}`);
  if (score("seo") < budget.seo) failures.push(`${url}: SEO ${score("seo") * 100}, needs 100`);
  if (score("accessibility") < budget.accessibility) failures.push(`${url}: accessibility ${score("accessibility") * 100}, needs 95`);
  if (lcp >= budget.lcp) failures.push(`${url}: LCP ${(lcp / 1000).toFixed(2)} s, needs under 2.5 s`);
  if (cls >= budget.cls) failures.push(`${url}: CLS ${cls.toFixed(3)}, needs under 0.1`);
  for (const key of ["seo", "accessibility"]) {
    for (const ref of report.categories[key].auditRefs) {
      const audit = report.audits[ref.id];
      if (ref.weight > 0 && audit.score !== null && audit.score < 1) console.log(`  ${key}: ${audit.id}: ${audit.title}`);
    }
  }
}

if (failures.length > 0) {
  console.error(failures.map((f) => `✗ ${f}`).join("\n"));
  process.exit(1);
}
console.log("Lighthouse budget met.");
