// Lighthouse on a phone (its default: a mid-range phone on a slow 4G line) against the production server.
// Fails unless SEO is 100, accessibility is at least 95, LCP is under the cap and CLS under 0.1, on every page given.
// Each page runs three times and each figure is the median of its runs (LIGHTHOUSE_RUNS sets another number).
//
// Two ways to slow the page down (README, "Lighthouse"):
// - devtools (the gate): the browser is really held to the phone's speed and the line's, and the page is timed as it
//   loads. Its numbers are steady from run to run.
// - simulate (LIGHTHOUSE_THROTTLING=simulate; a report only in CI, LIGHTHOUSE_REPORT_ONLY=1): the page loads at full
//   speed and Lighthouse estimates the slow phone afterwards, as PageSpeed Insights does. It counts a script that
//   started before the LCP as part of it, so a page whose largest element is text in the first frame reads 1.8 s or
//   2.7 s depending on a few milliseconds.
// Each line also names the LCP element, the four parts of its time, and how many of the site's scripts had started
// before it showed. With five runs or more each page also gets its median, its worst and its spread (the calibration),
// and LIGHTHOUSE_SUMMARY=<file> writes them as JSON lines for scripts/lighthouse-table.mjs.
//   node scripts/check-lighthouse.mjs http://127.0.0.1:3100/ [more urls…]
import { execFileSync, spawn } from "node:child_process";
import { appendFileSync, existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

import { Launcher } from "chrome-launcher";
import { chromium } from "playwright";

// Percent-encoded, so an Arabic address reaches the browser as one argument.
const urls = process.argv.slice(2).map((u) => new URL(u).href);
if (urls.length === 0) {
  console.error("Usage: node scripts/check-lighthouse.mjs <url> [url…]");
  process.exit(2);
}

const method = process.env.LIGHTHOUSE_THROTTLING ?? "devtools";
if (!["devtools", "simulate"].includes(method)) {
  console.error(`LIGHTHOUSE_THROTTLING is devtools or simulate, not ${method}`);
  process.exit(2);
}
const reportOnly = process.env.LIGHTHOUSE_REPORT_ONLY === "1";
const runsText = process.env.LIGHTHOUSE_RUNS ?? "3";
const runs = Number(runsText);
if (!/^[0-9]+$/.test(runsText) || runs < 1 || runs > 20) {
  console.error(`LIGHTHOUSE_RUNS is a whole number from 1 to 20, not ${JSON.stringify(runsText)}`);
  process.exit(2);
}
// The LCP cap is the gate's (devtools), from the calibration on the CI's machines (README, "Lighthouse"): the highest
// worst of ten runs a page was 1.71 s, and most pages spread 0.01 to 0.03 s, so 2.0 s catches a page that got about
// half a second slower than its usual figure. The simulated report is held to the 2.5 s of the Core Web Vitals instead:
// its estimate reads 0.3 to 1.2 s above the gate's, so the gate's cap would flag nearly every page.
const budget = { seo: 1, accessibility: 0.95, lcp: method === "devtools" ? 2000 : 2500, cls: 0.1 };
const dir = mkdtempSync(join(tmpdir(), "lighthouse-"));
const lighthouse = new URL("../node_modules/.bin/lighthouse", import.meta.url).pathname;
const failures = [];

// Lighthouse opens a blank page before each run. about:blank has no opener policy and the site's is same-origin
// (README, "Security headers"), so going from one to the other moves the tab to a new group of windows, and in about
// one run in ten the trace then lost the page's start (NO_NAVSTART). The site's robots.txt carries the same headers as
// its pages, so from it the page opens in the same group. Lighthouse empties the cache after the blank page.
function config(url) {
  const path = join(dir, `config-${new URL(url).origin.replace(/\W/g, "-")}.json`);
  if (!existsSync(path)) writeFileSync(path, JSON.stringify({ extends: "lighthouse:default", settings: { blankPage: new URL("/robots.txt", url).href } }));

  return path;
}

// A fresh browser for each run, with Lighthouse's own flags, its profile in a new folder of the system's temporary
// space (TMPDIR), removed afterwards. Lighthouse would launch it itself, but on WSL it takes the browser for a Windows
// one and hands it a Windows path (C:\Users\…), which a Linux browser makes into a folder under the working directory.
function browser() {
  const profile = mkdtempSync(join(tmpdir(), "lighthouse-profile-"));
  const flags = [...Launcher.defaultFlags(), "--headless=new", "--no-sandbox", "--disable-setuid-sandbox", "--remote-debugging-port=0", `--user-data-dir=${profile}`, "about:blank"];
  const chrome = spawn(chromium.executablePath(), flags, { detached: true, stdio: "ignore" });
  const portFile = join(profile, "DevToolsActivePort");
  // The browser writes its port there once it listens; the wait blocks, as the runs do.
  const portOf = () => (existsSync(portFile) ? Number(readFileSync(portFile, "utf8").split("\n")[0]) || 0 : 0);
  const wait = new Int32Array(new SharedArrayBuffer(4));
  let port = portOf();
  for (let i = 0; i < 200 && !port; i++, port = portOf()) Atomics.wait(wait, 0, 0, 50);
  const close = () => {
    try {
      process.kill(-chrome.pid, "SIGKILL");
    } catch {
      // Already gone.
    }
    rmSync(profile, { recursive: true, force: true, maxRetries: 10, retryDelay: 250 });
  };
  if (!port) {
    close();
    throw new Error("The browser did not open its debugging port within 10 s");
  }

  return { port, close };
}

function run(url, out) {
  const chrome = browser();
  try {
    execFileSync(
      lighthouse,
      [
        url,
        `--port=${chrome.port}`,
        `--config-path=${config(url)}`,
        "--quiet",
        "--output=json",
        `--output-path=${out}`,
        "--save-assets",
        "--only-categories=performance,accessibility,seo",
        `--throttling-method=${method}`,
      ],
      { cwd: dir, stdio: ["ignore", "ignore", "inherit"] },
    );
  } finally {
    chrome.close();
  }

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

/** The middle value (the lower of the two middle ones for an even count, so a figure is always one a run produced). */
function median(values) {
  const sorted = [...values].sort((a, b) => a - b);
  return sorted[Math.floor((sorted.length - 1) / 2)];
}

const seconds = (ms) => (ms / 1000).toFixed(2);

console.log(`Throttling: ${method}, ${runs} run${runs > 1 ? "s" : ""} a page${reportOnly ? ", report only" : ""}.`);
for (const [i, url] of urls.entries()) {
  const reports = Array.from({ length: runs }, (_, r) => run(url, join(dir, `report-${i}-${r}.json`)));
  const lcpOf = (r) => r.audits["largest-contentful-paint"].numericValue;
  const byLcp = [...reports].sort((a, b) => lcpOf(a) - lcpOf(b));
  // The run whose LCP is the median: its element, parts and the figures that explain it.
  const report = byLcp[Math.floor((byLcp.length - 1) / 2)];
  const lcps = byLcp.map(lcpOf);
  const lcp = median(lcps);
  const cls = median(reports.map((r) => r.audits["cumulative-layout-shift"].numericValue));
  const score = (key) => median(reports.map((r) => r.categories[key].score));
  // What the LCP is made of, so a slow run on a shared machine can be read: the server's answer, the first paint, the
  // main thread's blocking.
  const parts = `FCP ${seconds(report.audits["first-contentful-paint"].numericValue)} s, TTFB ${Math.round(report.audits["server-response-time"]?.numericValue ?? 0)} ms, TBT ${Math.round(report.audits["total-blocking-time"].numericValue)} ms`;
  const all = runs > 1 ? ` (LCP of the ${runs} runs: ${lcps.map(seconds).join(", ")} s)` : "";
  console.log(
    `${url}: performance ${Math.round(score("performance") * 100)}, accessibility ${Math.round(score("accessibility") * 100)}, SEO ${Math.round(score("seo") * 100)}, LCP ${seconds(lcp)} s, CLS ${cls.toFixed(3)} (${parts})${all}`,
  );
  console.log(`  LCP ${lcpDetail(report)}`);
  if (runs > 1) for (const r of byLcp) console.log(`    run: LCP ${seconds(lcpOf(r))} s, scripts started before it: ${r.scriptsBeforeLcp?.length ?? "?"}`);
  if (runs >= 5) {
    const worst = lcps.at(-1);
    console.log(`  calibration: median ${seconds(lcp)} s, worst ${seconds(worst)} s, spread ${seconds(worst - lcps[0])} s`);
  }
  if (process.env.LIGHTHOUSE_SUMMARY) {
    appendFileSync(process.env.LIGHTHOUSE_SUMMARY, `${JSON.stringify({ url: decodeURI(url), method, lcps: lcps.map(Math.round), cls, seo: score("seo"), accessibility: score("accessibility") })}\n`);
  }
  if (score("seo") < budget.seo) failures.push(`${url}: SEO ${score("seo") * 100}, needs 100`);
  if (score("accessibility") < budget.accessibility) failures.push(`${url}: accessibility ${score("accessibility") * 100}, needs 95`);
  if (lcp >= budget.lcp) failures.push(`${url}: LCP ${seconds(lcp)} s, needs under ${seconds(budget.lcp)} s`);
  if (cls >= budget.cls) failures.push(`${url}: CLS ${cls.toFixed(3)}, needs under 0.1`);
  for (const key of ["seo", "accessibility"]) {
    for (const ref of report.categories[key].auditRefs) {
      const audit = report.audits[ref.id];
      if (ref.weight > 0 && audit.score !== null && audit.score < 1) console.log(`  ${key}: ${audit.id}: ${audit.title}`);
    }
  }
}

if (failures.length > 0 && reportOnly) {
  // Shown on the run's page, without failing it.
  const mark = process.env.GITHUB_ACTIONS ? "::warning::" : "! ";
  console.log(failures.map((f) => `${mark}${method}, report only: ${f}`).join("\n"));
  process.exit(0);
}
if (failures.length > 0) {
  console.error(failures.map((f) => `✗ ${f}`).join("\n"));
  process.exit(1);
}
console.log("Lighthouse budget met.");
