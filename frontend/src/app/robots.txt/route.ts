import { getRobots, textResponse } from "@/lib/server/seo-files";
import { serverEnv } from "@/lib/server/env";

/*
 * robots.txt from the platform's policy (/robots): one group per entry, its User-agent lines, then "Disallow: /" for a
 * closed group or the platform's disallowed paths for an open one (a crawler follows only the most specific group that
 * names it), then the sitemaps with the site's full address. The site adds its own server routes (/api/).
 */

const siteOwn = ["/api/"];

export async function GET() {
  let policy;
  try {
    policy = await getRobots();
  } catch (error) {
    console.error(`[site] robots.txt: ${(error as Error).message}`);
    // Without the platform: everyone may crawl what is public, nothing is closed by mistake.
    return textResponse(`User-agent: *\nDisallow: /api/\n`);
  }
  const base = serverEnv().siteUrl.origin;
  const disallow = [...new Set([...policy.disallow, ...siteOwn])];
  const groups = policy.groups.map((group) =>
    [...group.agents.map((agent) => `User-agent: ${agent}`), ...(group.allow ? disallow.map((path) => `Disallow: ${path}`) : ["Disallow: /"])].join("\n"),
  );
  const sitemaps = policy.sitemaps.map((path) => `Sitemap: ${path.startsWith("http") ? path : `${base}${path}`}`);

  return textResponse(`${groups.join("\n\n")}\n\n${sitemaps.join("\n")}\n`);
}
