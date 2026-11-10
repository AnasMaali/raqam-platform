import "server-only";

import { apiPost, type Visitor } from "./api";

/*
 * Unsubscribing with the subscriber's own link (19.5): the link names the subscriber (`s`) and carries its signature
 * (`t`); the platform checks the pair. From this server only, with the visitor's address.
 */

export type Unsubscribed = { ok: true } | { ok: false; status: number; message: string };

/** The link's two values as the platform takes them; null when they cannot be a link the platform made. */
export function linkValues(s: unknown, t: unknown): { subscriber: string; signature: string } | null {
  if (typeof s !== "string" || typeof t !== "string") return null;
  if (!/^[1-9]\d{0,18}$/.test(s) || !/^[A-Za-z0-9_-]{16,128}$/.test(t)) return null;

  return { subscriber: s, signature: t };
}

export async function unsubscribe(values: { subscriber: string; signature: string }, visitor: Visitor): Promise<Unsubscribed> {
  try {
    const sent = await apiPost("/newsletter/unsubscribe", { subscriber: values.subscriber, signature: values.signature }, visitor);
    if (sent.status === 200) return { ok: true };
    const error = (sent.body as { error?: { message?: string } } | null)?.error;
    if (sent.status === 422) return { ok: false, status: 422, message: error?.message ?? "رابط إلغاء الاشتراك غير صالح." };
    if (sent.status === 429) return { ok: false, status: 429, message: "طلبات كثيرة خلال دقيقة. حاول بعد قليل." };

    return { ok: false, status: 502, message: "تعذّر إلغاء الاشتراك الآن. حاول بعد قليل." };
  } catch (error) {
    console.error(`[site] newsletter unsubscribe: ${(error as Error).message}`);

    return { ok: false, status: 502, message: "تعذّر إلغاء الاشتراك الآن. حاول بعد قليل." };
  }
}
