import "server-only";

import { serverEnv } from "./env";

export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
  ) {
    super(message);
  }
}

/**
 * How long the site waits for the platform (QA-6). A platform that accepts the request and never answers (a stuck
 * server) would otherwise hold the visitor for the framework's own limit and end in a bare server error; after this the
 * request fails like any other platform error and the page says so. The API answers in well under a second (the search waits as long).
 */
export const API_TIMEOUT_MS = 5000;

export function apiTimeout(): AbortSignal {
  return AbortSignal.timeout(API_TIMEOUT_MS);
}

/** GET a JSON document from the platform API, sending the site token from this server only. */
export async function apiGet<T>(path: string, init: RequestInit = {}): Promise<T> {
  const { apiBaseUrl, siteToken } = serverEnv();
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");
  if (siteToken) {
    headers.set("X-Raqam-Site-Token", siteToken);
  }

  const response = await fetch(`${apiBaseUrl}${path}`, { signal: apiTimeout(), ...init, headers, redirect: "manual" });
  if (!response.ok) {
    const body = await response.json().catch(() => null);
    throw new ApiError(
      response.status,
      body?.error?.code ?? "http_error",
      body?.error?.message ?? `API answered ${response.status}`,
    );
  }

  return (await response.json()) as T;
}

/** The visitor a form request comes from, as the platform's site-only routes need it. */
export type Visitor = { ip: string; userAgent: string };

/**
 * POST JSON to a site-only route of the platform (forms) with the site token and the visitor's address. Never cached;
 * the answer's status and body come back as they are, for the form to show the platform's own message.
 */
export async function apiPost(path: string, body: unknown, visitor: Visitor): Promise<{ status: number; body: unknown }> {
  const { apiBaseUrl, siteToken } = serverEnv();
  const headers = new Headers({ Accept: "application/json", "Content-Type": "application/json" });
  if (siteToken) headers.set("X-Raqam-Site-Token", siteToken);
  headers.set("X-Raqam-Visitor-Ip", visitor.ip);
  headers.set("X-Raqam-Visitor-Ua", visitor.userAgent.slice(0, 512));

  const response = await fetch(`${apiBaseUrl}${path}`, { method: "POST", headers, body: JSON.stringify(body), redirect: "manual", cache: "no-store", signal: apiTimeout() });

  return { status: response.status, body: await response.json().catch(() => null) };
}
