/*
 * What the content security policy blocked (next.config.ts), as the browser reports it: one line in the server's log
 * for each report, with the directive, the blocked address and the page, both addresses without their query or
 * fragment (a search or a token can sit there). Never the visitor's address or browser. Only the two kinds of CSP
 * report, at most 8 KB; the answer is never stored.
 *
 *   POST /api/csp-report  application/csp-report (report-uri) or application/reports+json (report-to)
 */

const maxBytes = 8 * 1024;
const reportUri = "application/csp-report";
const reportTo = "application/reports+json";

const answer = (status: number) => new Response(null, { status, headers: { "Cache-Control": "no-store" } });

type Violation = { directive: string; blocked: string; page: string };

export async function POST(request: Request) {
  const type = (request.headers.get("content-type") ?? "").split(";")[0].trim().toLowerCase();
  if (type !== reportUri && type !== reportTo) return answer(415);
  if (Number(request.headers.get("content-length") ?? 0) > maxBytes) return answer(413);
  const text = await readAtMost(request, maxBytes);
  if (text === null) return answer(413);

  let body: unknown;
  try {
    body = JSON.parse(text);
  } catch {
    return answer(400);
  }
  const violations = (type === reportUri ? [fromReportUri(body)] : Array.isArray(body) ? body.map(fromReportTo) : []).filter((v): v is Violation => v !== null);
  if (violations.length === 0) return answer(400);
  for (const { directive, blocked, page } of violations) console.warn(`[csp] ${directive} blocked ${blocked} on ${page}`);

  return answer(204);
}

/** The body as text, or null once it passes the limit (a sender may leave out Content-Length or understate it). */
async function readAtMost(request: Request, limit: number): Promise<string | null> {
  if (!request.body) return "";
  const reader = request.body.getReader();
  const chunks: Uint8Array[] = [];
  let size = 0;
  for (;;) {
    const { done, value } = await reader.read();
    if (done) break;
    size += value.byteLength;
    if (size > limit) {
      await reader.cancel();

      return null;
    }
    chunks.push(value);
  }

  return Buffer.concat(chunks).toString("utf8");
}

/** report-uri: { "csp-report": { "effective-directive", "blocked-uri", "document-uri" } }. */
function fromReportUri(body: unknown): Violation | null {
  const report = record(record(body)?.["csp-report"]);

  return report ? violation(report["effective-directive"] ?? report["violated-directive"], report["blocked-uri"], report["document-uri"]) : null;
}

/** report-to: [{ "type": "csp-violation", "body": { "effectiveDirective", "blockedURL", "documentURL" } }, …]. */
function fromReportTo(entry: unknown): Violation | null {
  const report = record(entry);
  const body = record(report?.body);

  return report?.type === "csp-violation" && body ? violation(body.effectiveDirective, body.blockedURL, body.documentURL) : null;
}

const record = (value: unknown) => (value && typeof value === "object" && !Array.isArray(value) ? (value as Record<string, unknown>) : null);

function violation(directive: unknown, blocked: unknown, page: unknown): Violation | null {
  if (typeof directive !== "string" || !/^[a-z-]{1,40}$/.test(directive)) return null;
  const pageAddress = address(page);
  if (!pageAddress?.startsWith("http")) return null;

  return { directive, blocked: address(blocked) ?? "unknown", page: pageAddress };
}

/** An http(s) address as origin and path (at most 300 characters), or the browser's word for the rest (inline, eval…). */
function address(value: unknown): string | null {
  if (typeof value !== "string") return null;
  if (/^[a-z-]{1,20}$/.test(value)) return value;
  const url = URL.canParse(value) ? new URL(value) : null;
  if (!url) return null;
  if (url.protocol === "http:" || url.protocol === "https:") return `${url.origin}${url.pathname}`.slice(0, 300);

  return url.protocol.replace(/:$/, "").slice(0, 20);
}
