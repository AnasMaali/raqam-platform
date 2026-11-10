import { getLlms, servedHere, textResponse } from "@/lib/server/seo-files";
import { serverEnv } from "@/lib/server/env";

/*
 * llms.txt from the platform (/llms): "# name", "> summary", then "## title" and "- [title](full address): description"
 * lines, and where to find the whole site (the feed, the sitemap). Links to pages the site does not serve yet are left
 * out.
 */

const line = (text: string) => text.replace(/\s+/g, " ").trim();

export async function GET() {
  let llms;
  try {
    llms = await getLlms();
  } catch (error) {
    console.error(`[site] llms.txt: ${(error as Error).message}`);
    return new Response("Not available\n", { status: 503, headers: { "Content-Type": "text/plain; charset=utf-8", "Retry-After": "300" } });
  }
  const base = serverEnv().siteUrl.origin;
  const url = (path: string) => (path.startsWith("http") ? path : `${base}${encodeURI(path)}`);
  const parts = [`# ${line(llms.name)}`];
  if (llms.summary) parts.push(`> ${line(llms.summary)}`);
  for (const group of llms.groups) {
    const links = group.links.filter((l) => servedHere(l.path));
    if (!links.length) continue;
    parts.push(`## ${line(group.title)}\n\n${links.map((l) => `- [${line(l.title)}](${url(l.path)})${l.description ? `: ${line(l.description)}` : ""}`).join("\n")}`);
  }
  const whole = [llms.feed ? `- [آخر المواد (RSS)](${url(llms.feed)})` : null, llms.sitemap ? `- [خريطة الموقع](${url(llms.sitemap)})` : null].filter(Boolean);
  if (whole.length) parts.push(`## الموقع كاملاً\n\n${whole.join("\n")}`);

  return textResponse(`${parts.join("\n\n")}\n`);
}
