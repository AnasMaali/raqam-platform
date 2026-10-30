// The layout and the first load of the home page and of an article carry no motion code (DESIGN.md, Motion: Budget).
// Motion is loaded only by the component that needs it, with a dynamic import. Against a running production server
// (`next start`), this fetches each page, collects the scripts the page loads, and fails if any of them holds Motion.
//
//   node scripts/check-motion-free.mjs http://127.0.0.1:3100 [/route ...]

const base = process.argv[2] ?? "http://127.0.0.1:3100";
const extra = process.argv.slice(3);

// The home page now; the article route joins this list when the article page is built.
const routes = extra.length > 0 ? extra : ["/"];

// Text that survives minification: the first from motion/react (full or lazy), the second from motion/mini.
const fingerprints = [
  { name: "motion/react", pattern: "data-framer-appear-id" },
  { name: "motion/mini", pattern: 'linear(0, 1)"' },
];

let failed = false;
const checked = new Set();

for (const route of routes) {
  const response = await fetch(new URL(route, base));
  if (!response.ok) {
    console.error(`${route}: answered ${response.status}`);
    failed = true;
    continue;
  }
  const html = await response.text();
  const scripts = [...html.matchAll(/<script[^>]+src="([^"]+\.js[^"]*)"/g)].map((m) => m[1]);
  if (scripts.length === 0) {
    console.error(`${route}: no scripts found, nothing was checked`);
    failed = true;
    continue;
  }

  for (const src of scripts) {
    const url = new URL(src, base).href;
    const body = await (await fetch(url)).text();
    checked.add(url);
    for (const { name, pattern } of fingerprints) {
      if (body.includes(pattern)) {
        console.error(`${route}: ${name} is in ${src}`);
        failed = true;
      }
    }
  }
}

if (failed) {
  process.exit(1);
}
console.log(`No motion code in the first load of ${routes.join(", ")} (${checked.size} scripts).`);
