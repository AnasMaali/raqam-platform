import type { Instrumentation } from "next";

import { euDsn } from "./lib/server/error-reports";

/*
 * Error reports from the site's Node server to Sentry: only with an EU DSN (SENTRY_DSN), never in the browser and never
 * in the edge runtime. The SDK is loaded only when it is on, so without a DSN nothing of it runs.
 */

export async function register() {
  if (process.env.NEXT_RUNTIME !== "nodejs") return;
  const dsn = euDsn(process.env.SENTRY_DSN);
  if (!dsn) return;
  const { startErrorReports } = await import("./lib/server/sentry");
  startErrorReports(dsn);
}

export const onRequestError: Instrumentation.onRequestError = async (error, _request, context) => {
  if (process.env.NEXT_RUNTIME !== "nodejs" || !euDsn(process.env.SENTRY_DSN)) return;
  const { reportRequestError } = await import("./lib/server/sentry");
  // The route's file path only: not the visitor's address, query string or headers.
  await reportRequestError(error, context.routePath, context.routeType);
};
