import { resolve } from "node:path";

import type { NextConfig } from "next";
import { PHASE_PRODUCTION_BUILD, PHASE_PRODUCTION_SERVER } from "next/constants";

// A browser-visible variable must never carry a credential: NEXT_PUBLIC_* values are copied
// into the JavaScript sent to every visitor.
const leaking = Object.keys(process.env).filter(
  (name) => name.startsWith("NEXT_PUBLIC_") && /TOKEN|SECRET|KEY|PASSWORD/i.test(name),
);
if (leaking.length > 0) {
  throw new Error(`Browser-visible variables that look like credentials: ${leaking.join(", ")}`);
}

// The site's public address. For the production build and server it must be exactly an origin: https, or http for
// localhost and 127.0.0.1 only, with no path and no closing slash. The forms' routes take a request only when its
// Origin is this, character for character (src/lib/server/visitor.ts), so a wrong value would refuse every form.
function checkSiteUrl() {
  const value = process.env.SITE_URL ?? "";
  const url = URL.canParse(value) ? new URL(value) : null;
  const local = url !== null && url.protocol === "http:" && ["localhost", "127.0.0.1"].includes(url.hostname);
  if (!url || value !== url.origin || !(url.protocol === "https:" || local)) {
    const message = `SITE_URL must be the site's public origin, exactly: https://<host> (http only for localhost and 127.0.0.1), with no path and no closing slash. It is ${JSON.stringify(value)}.`;
    console.error(message);
    throw new Error(message);
  }
}

// The browser features the site uses: the visitor's position for «أقرب مدينة» (asked only on that press), and four for
// the YouTube player, which it may use only because they are handed to it here and in its frame's `allow`. Every other
// feature is off for the site and anything it frames.
const youtube = '(self "https://www.youtube-nocookie.com")';
const permissions = [
  "geolocation=(self)",
  `autoplay=${youtube}`,
  `encrypted-media=${youtube}`,
  `picture-in-picture=${youtube}`,
  `fullscreen=${youtube}`,
  ...["accelerometer", "camera", "display-capture", "gyroscope", "magnetometer", "microphone", "midi", "payment", "usb", "browsing-topics"].map((f) => `${f}=()`),
].join(", ");

// Where the platform's pictures and uploaded videos come from: MEDIA_ORIGINS (origins, separated by spaces or commas),
// or else the API's own origin. Read when the site is built, like the rest of this file.
function mediaOrigins(): string[] {
  const listed = (process.env.MEDIA_ORIGINS ?? "").split(/[\s,]+/).filter(Boolean);
  const api = process.env.API_BASE_URL?.trim();
  const origins = listed.length > 0 ? listed : api ? [api] : [];

  return origins.map((value) => {
    const url = URL.canParse(value) ? new URL(value) : null;
    const bare = listed.length === 0 || (url !== null && value.replace(/\/$/, "") === url.origin);
    if (!url || !bare || !["http:", "https:"].includes(url.protocol)) {
      throw new Error(`MEDIA_ORIGINS holds origins only (scheme, host and port), not ${JSON.stringify(value)}`);
    }

    return url.origin;
  });
}

// The content security policy (README, "Content security policy"): scripts from the site, its inline scripts (Next's
// page data, which changes with every page, so neither a hash nor a nonce can name it) and Turnstile; no inline event
// handler and no eval; pictures and videos from the site and the platform; frames for the YouTube player and
// Turnstile; requests to the site only. Violations are reported to /api/csp-report.
const development = process.env.NODE_ENV === "development";
const media = mediaOrigins();
const contentSecurityPolicy = [
  "default-src 'self'",
  // React's development build evaluates code; the production build never does.
  `script-src 'self' 'unsafe-inline'${development ? " 'unsafe-eval'" : ""} https://challenges.cloudflare.com`,
  "script-src-attr 'none'",
  "style-src 'self' 'unsafe-inline'",
  // i.ytimg.com: a video's picture when the platform could not keep its own copy.
  `img-src 'self' ${[...media, "https://i.ytimg.com"].join(" ")}`,
  "font-src 'self'",
  `media-src ${media.length > 0 ? media.join(" ") : "'none'"}`,
  "frame-src https://www.youtube-nocookie.com https://challenges.cloudflare.com",
  "connect-src 'self'",
  "object-src 'none'",
  "base-uri 'none'",
  "form-action 'self'",
  "frame-ancestors 'none'",
  // Only when every origin above is https: on a test machine the platform's pictures are plain http.
  ...(media.length > 0 && media.every((origin) => origin.startsWith("https://")) ? ["upgrade-insecure-requests"] : []),
  "report-uri /api/csp-report",
  "report-to csp",
].join("; ");

// On every answer of the site: pages, its routes, its files and its build's files (README, "Security headers").
const securityHeaders = [
  // HTTPS only for a year, subdomains too; not on the browsers' preload list (hard to leave once on it).
  { key: "Strict-Transport-Security", value: "max-age=31536000; includeSubDomains" },
  { key: "X-Content-Type-Options", value: "nosniff" },
  // Another site (an advertiser) learns which site a visitor came from, never which page.
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  { key: "Permissions-Policy", value: permissions },
  // No site may frame this one (the content security policy's frame-ancestors says the same to newer browsers).
  { key: "X-Frame-Options", value: "DENY" },
  { key: "Cross-Origin-Opener-Policy", value: "same-origin" },
  { key: "Content-Security-Policy", value: contentSecurityPolicy },
  { key: "Reporting-Endpoints", value: 'csp="/api/csp-report"' },
];

const nextConfig: NextConfig = {
  async headers() {
    return [{ source: "/:path*", headers: securityHeaders }];
  },
  cacheComponents: true,
  partialPrefetching: true,
  agentRules: false,
  // Lifetimes the API asks for (docs/api/openapi.yaml). Webhooks refresh rates, ads and posts sooner by their tags; a
  // campaign or a manual rate that ends by its own time sends nothing, hence the short server revalidation. Weather
  // sends no webhook and changes hourly. Expiry is long, so a slow API never empties the frame of a page already served.
  // `stale` (how long a browser reuses what it has) stays at 5 minutes: shorter keeps the part out of the page's
  // instant shell, and a bar that streams in late would push the page down.
  cacheLife: {
    rates: { stale: 300, revalidate: 180, expire: 86400 },
    weather: { stale: 300, revalidate: 600, expire: 86400 },
    ads: { stale: 300, revalidate: 90, expire: 3600 },
    breaking: { stale: 300, revalidate: 60, expire: 3600 },
    // The home page: the webhook refreshes it at once; the time limit catches a scheduled post going live.
    home: { stale: 300, revalidate: 120, expire: 86400 },
    // One post: the webhook refreshes it at once (an edit, a correction, a take-down); the hour is a safety net.
    post: { stale: 300, revalidate: 3600, expire: 604800 },
  },
  // Pages kept in memory, not in files named after their address (an Arabic slug passes the 255-byte limit of a
  // file name): cache-handler.js.
  cacheHandler: resolve("cache-handler.js"),
  cacheMaxMemorySize: 0,
  poweredByHeader: false,
  reactStrictMode: true,
  // Tailwind runs on every stylesheet of the site. A CSS module must stay a module after it: renaming every file to
  // "*.css" turned modules into plain stylesheets and their class names came back undefined.
  turbopack: {
    rules: {
      "*.css": [
        {
          condition: { all: [{ not: "foreign" }, { path: /\.module\.css$/ }] },
          loaders: ["@tailwindcss/turbopack"],
          as: "*.module.css",
        },
        {
          condition: { all: [{ not: "foreign" }, { not: { path: /\.module\.css$/ } }] },
          loaders: ["@tailwindcss/turbopack"],
          as: "*.css",
        },
      ],
    },
  },
};

// Checked when the site is built and when its server starts; not by the development server, nor by `next typegen`
// (npm run typecheck), which loads this file as a build does but only writes types.
export default function config(phase: string): NextConfig {
  const typegen = process.argv.includes("typegen");
  if ((phase === PHASE_PRODUCTION_BUILD && !typegen) || phase === PHASE_PRODUCTION_SERVER) checkSiteUrl();

  return nextConfig;
}
