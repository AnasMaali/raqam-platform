import { indexNowFile } from "@/proxy";

/*
 * The IndexNow key file (src/proxy.ts sends /{INDEXNOW_KEY}.txt here): the key itself, as plain text, so search
 * engines trust the platform's pings. Without a key in the environment, there is no file.
 */
export function GET() {
  const file = indexNowFile();
  if (!file) return new Response("Not found\n", { status: 404, headers: { "Content-Type": "text/plain; charset=utf-8" } });

  return new Response(file.replace(/\.txt$/, ""), { headers: { "Content-Type": "text/plain; charset=utf-8", "Cache-Control": "public, max-age=86400" } });
}
