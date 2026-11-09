import "server-only";

import { createHmac, timingSafeEqual } from "node:crypto";

/*
 * The platform's webhook (backend SendSiteWebhook): the body is signed with HMAC-SHA256 under the shared secret over
 * "{timestamp}.{body}", sent as `X-Raqam-Signature: sha256=<hex>` with `X-Raqam-Timestamp: <unix seconds>`.
 */

/** How far a message's time may be from this server's clock, either way, before it is refused as old (or forged ahead). */
export const toleranceSeconds = 300;

/** The largest body accepted; the platform's messages are a few hundred bytes. */
export const maxBodyBytes = 16 * 1024;

export type Verdict = { ok: true } | { ok: false; reason: "no_secret" | "unsigned" | "old" | "bad_signature" };

export function verifyWebhook({
  secret,
  timestamp,
  signature,
  body,
  now = Date.now(),
}: {
  secret: string | undefined;
  timestamp: string | null;
  signature: string | null;
  body: string;
  now?: number;
}): Verdict {
  // Without a secret nothing can be checked, so nothing is accepted.
  if (!secret) return { ok: false, reason: "no_secret" };
  if (!timestamp || !/^\d{1,12}$/.test(timestamp) || !signature) return { ok: false, reason: "unsigned" };

  const given = /^sha256=([0-9a-f]{64})$/.exec(signature)?.[1];
  if (!given) return { ok: false, reason: "unsigned" };

  // The signature is checked before the time, so an answer never tells a stranger which of the two was wrong.
  const expected = createHmac("sha256", secret).update(`${timestamp}.${body}`).digest();
  // Both are 32 bytes here (the format above), as the constant-time comparison requires.
  if (!timingSafeEqual(Buffer.from(given, "hex"), expected)) return { ok: false, reason: "bad_signature" };

  if (Math.abs(now / 1000 - Number(timestamp)) > toleranceSeconds) return { ok: false, reason: "old" };

  return { ok: true };
}
