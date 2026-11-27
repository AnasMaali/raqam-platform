import "server-only";

import * as Sentry from "@sentry/node";

import { scrubBreadcrumb, scrubEvent } from "./error-reports";

/*
 * The Sentry client of the site's Node server (see src/instrumentation.ts). Errors only: no tracing, no profiling, no
 * OpenTelemetry, no automatic instrumentation of requests, console or modules; just uncaught errors, rejected promises
 * and the request errors Next.js hands over. Every report goes through scrubEvent.
 */

/** `transport` is for the unit test only (a fake that keeps what would be sent). */
export function startErrorReports(dsn: string, transport?: Sentry.NodeOptions["transport"]) {
  Sentry.init({
    dsn,
    ...(transport ? { transport } : {}),
    environment: process.env.SENTRY_ENVIRONMENT || process.env.NODE_ENV,
    release: process.env.SENTRY_RELEASE || undefined,
    // Nothing about people or requests is collected in the first place; scrubEvent cleans whatever still comes.
    dataCollection: {
      userInfo: false,
      cookies: false,
      httpHeaders: false,
      httpBodies: [],
      urlQueryParams: false,
      databaseQueryData: false,
      stackFrameVariables: false,
      genAI: { inputs: false, outputs: false },
      mcp: { inputs: false, outputs: false },
      graphQL: { document: false, variables: false },
    },
    enableOpenTelemetrySetup: false,
    enableRuntimeChannelInjection: false,
    defaultIntegrations: false,
    integrations: [
      Sentry.eventFiltersIntegration(),
      Sentry.linkedErrorsIntegration(),
      Sentry.dedupeIntegration(),
      Sentry.onUncaughtExceptionIntegration(),
      Sentry.onUnhandledRejectionIntegration(),
    ],
    beforeSend: (event) => scrubEvent(event),
    beforeBreadcrumb: (breadcrumb) => scrubBreadcrumb(breadcrumb),
  });
}

export async function reportRequestError(error: unknown, routePath: string, routeType: string) {
  Sentry.withScope((scope) => {
    scope.setTransactionName(routePath);
    scope.setTag("route_type", routeType);
    Sentry.captureException(error);
  });
  await Sentry.flush(2000);
}
