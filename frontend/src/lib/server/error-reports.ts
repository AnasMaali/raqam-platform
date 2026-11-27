/*
 * What an error report from the site's server may hold (Sentry, launch preparation). Off without SENTRY_DSN, and only in
 * Sentry's EU region; never in the browser (no browser SDK). A report keeps the error, its stack and the route, and
 * loses everything about people and secrets: no user, cookies, headers, body or query string; every secret in the
 * server's environment, key-like query parameters, e-mail addresses and IP addresses written over. Plain functions with
 * no imports, so they can be tested outside Next.js.
 */

export const EU_HOST = ".ingest.de.sentry.io";

/** The DSN to use, or null: none set, or one outside the EU region. */
export function euDsn(dsn: string | undefined): string | null {
  if (!dsn) return null;
  try {
    const url = new URL(dsn);
    return url.protocol === "https:" && url.hostname.toLowerCase().endsWith(EU_HOST) ? dsn : null;
  } catch {
    return null;
  }
}

/** Every secret in the environment: a value of 8 characters or more under a name that says key, secret, token, password or dsn. */
export function secrets(env: NodeJS.ProcessEnv = process.env): string[] {
  return Object.entries(env)
    .filter(([name, value]) => /(KEY|SECRET|TOKEN|PASSWORD|DSN)$/i.test(name) && typeof value === "string" && value.length >= 8)
    .map(([, value]) => value as string)
    .sort((a, b) => b.length - a.length);
}

export function scrubText(text: string, hidden: string[] = secrets()): string {
  let clean = text;
  for (const secret of hidden) {
    clean = clean.split(secret).join("[hidden]").split(encodeURIComponent(secret)).join("[hidden]");
  }
  return clean
    .replace(/\b(appid|api_?key|key|token|access_key|signature)=[^&\s"'<>]*/gi, "$1=[hidden]")
    .replace(/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g, "[email]")
    .replace(/\b(?:\d{1,3}\.){3}\d{1,3}\b/g, "[ip]")
    .replace(
      /(?<![A-Za-z0-9:])(?:(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}|(?:[0-9a-f]{1,4}:)*[0-9a-f]{0,4}::(?:[0-9a-f]{1,4}:)*[0-9a-f]{0,4})(?![A-Za-z0-9:])/gi,
      "[ip]",
    );
}

/** An address without its query string or fragment, in clean text. */
export function scrubUrl(url: string, hidden: string[] = secrets()): string {
  return scrubText(url.replace(/[?#][\s\S]*$/, ""), hidden);
}

function deep(value: unknown, hidden: string[]): unknown {
  if (typeof value === "string") return scrubText(value, hidden);
  if (Array.isArray(value)) return value.map((item) => deep(item, hidden));
  if (value && typeof value === "object") {
    return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, deep(item, hidden)]));
  }
  return value;
}

type Crumb = { message?: string; data?: Record<string, unknown> };

export function scrubBreadcrumb<T extends Crumb>(crumb: T, hidden: string[] = secrets()): T {
  return {
    ...crumb,
    ...(crumb.message === undefined ? {} : { message: scrubText(crumb.message, hidden) }),
    ...(crumb.data === undefined ? {} : { data: deep(crumb.data, hidden) as Record<string, unknown> }),
  };
}

type Report = {
  user?: unknown;
  server_name?: string;
  request?: { url?: string; method?: string };
  transaction?: string;
  message?: string;
  exception?: { values?: { value?: string; stacktrace?: { frames?: { vars?: unknown }[] } }[] };
  breadcrumbs?: Crumb[];
  extra?: Record<string, unknown>;
  tags?: Record<string, unknown>;
  contexts?: Record<string, unknown>;
};

/** A stack frame without its variables' values. */
function withoutVars<F extends { vars?: unknown }>(frame: F): F {
  const clean = { ...frame };
  delete clean.vars;
  return clean;
}

/** Sentry's beforeSend: the report, cleaned. */
export function scrubEvent<T extends Report>(event: T): T {
  const hidden = secrets();
  const clean: T = { ...event };
  delete clean.user;
  delete clean.server_name;
  if (event.request) {
    clean.request = {
      ...(event.request.url ? { url: scrubUrl(event.request.url, hidden) } : {}),
      ...(event.request.method ? { method: event.request.method } : {}),
    };
  }
  if (event.transaction) clean.transaction = scrubUrl(event.transaction, hidden);
  if (event.message) clean.message = scrubText(event.message, hidden);
  if (event.exception?.values) {
    clean.exception = {
      ...event.exception,
      values: event.exception.values.map((ex) => ({
        ...ex,
        ...(ex.value === undefined ? {} : { value: scrubText(ex.value, hidden) }),
        ...(ex.stacktrace?.frames ? { stacktrace: { ...ex.stacktrace, frames: ex.stacktrace.frames.map(withoutVars) } } : {}),
      })),
    };
  }
  if (event.breadcrumbs) clean.breadcrumbs = event.breadcrumbs.map((crumb) => scrubBreadcrumb(crumb, hidden));
  for (const part of ["extra", "tags", "contexts"] as const) {
    if (event[part]) clean[part] = deep(event[part], hidden) as T[typeof part];
  }
  return clean;
}
