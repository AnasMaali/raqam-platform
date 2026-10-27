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

/** GET a JSON document from the platform API, sending the site token from this server only. */
export async function apiGet<T>(path: string, init: RequestInit = {}): Promise<T> {
  const { apiBaseUrl, siteToken } = serverEnv();
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");
  if (siteToken) {
    headers.set("X-Raqam-Site-Token", siteToken);
  }

  const response = await fetch(`${apiBaseUrl}${path}`, { ...init, headers, redirect: "manual" });
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
