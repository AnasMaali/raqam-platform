import type { NextConfig } from "next";

// A browser-visible variable must never carry a credential: NEXT_PUBLIC_* values are copied
// into the JavaScript sent to every visitor.
const leaking = Object.keys(process.env).filter(
  (name) => name.startsWith("NEXT_PUBLIC_") && /TOKEN|SECRET|KEY|PASSWORD/i.test(name),
);
if (leaking.length > 0) {
  throw new Error(`Browser-visible variables that look like credentials: ${leaking.join(", ")}`);
}

const nextConfig: NextConfig = {
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
  },
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

export default nextConfig;
