import "server-only";

export type ServerEnv = {
  apiBaseUrl: string;
  siteToken: string | null;
  siteUrl: URL;
};

/** Read the server-only settings; the site token is optional in development only. */
export function serverEnv(): ServerEnv {
  const apiBaseUrl = (process.env.API_BASE_URL ?? "").replace(/\/+$/, "");
  const siteToken = process.env.API_SITE_TOKEN?.trim() || null;
  const siteUrl = process.env.SITE_URL?.trim() || "http://localhost:3000";

  if (!apiBaseUrl) {
    throw new Error("API_BASE_URL is not set");
  }

  return { apiBaseUrl, siteToken, siteUrl: new URL(siteUrl) };
}
