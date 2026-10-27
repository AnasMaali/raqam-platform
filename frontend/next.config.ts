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
  poweredByHeader: false,
  reactStrictMode: true,
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
};

export default nextConfig;
