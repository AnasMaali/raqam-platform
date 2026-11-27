// Error reports from the site's Node server (Sentry): off without an EU DSN, and every report without people or secrets.
// Run: npm run test:unit (node --test; react-server conditions so "server-only" resolves as it does on the server).
import assert from "node:assert/strict";
import { test } from "node:test";

import { createTransport } from "@sentry/core";
import * as Sentry from "@sentry/node";

import { euDsn, scrubEvent, scrubText } from "../../src/lib/server/error-reports.ts";
import { reportRequestError, startErrorReports } from "../../src/lib/server/sentry.ts";

const EU = "https://publickey1234@o123.ingest.de.sentry.io/456";

test("off without a DSN, and off with a DSN outside the EU region", () => {
  assert.equal(euDsn(undefined), null);
  assert.equal(euDsn(""), null);
  assert.equal(euDsn("https://k@o1.ingest.us.sentry.io/2"), null);
  assert.equal(euDsn("https://k@o1.ingest.sentry.io/2"), null);
  assert.equal(euDsn("http://k@o1.ingest.de.sentry.io/2"), null);
  assert.equal(euDsn("https://k@o1.ingest.de.sentry.io.evil.example/2"), null);
  assert.equal(euDsn("not a dsn"), null);
  assert.equal(euDsn(EU), EU);
});

test("a full report keeps the error, its route and the address's path, nothing about people or secrets", () => {
  process.env.API_SITE_TOKEN = "site-token-value-0123456789";
  const event = scrubEvent({
    user: { ip_address: "198.51.100.7", email: "reader@example.com" },
    server_name: "web-1",
    request: { url: "https://raqam.example/news/5?token=abc&q=x", method: "POST", headers: { cookie: "a=b", "x-raqam-site-token": "site-token-value-0123456789" }, data: { email: "reader@example.com" }, query_string: "q=x" },
    transaction: "/news/[id]?x=1",
    exception: { values: [{ type: "Error", value: "API refused site-token-value-0123456789 for reader@example.com from 198.51.100.7 / 2001:db8::7 at 12:30:45", stacktrace: { frames: [{ filename: "a.js", vars: { token: "x" } }] } }] },
    breadcrumbs: [{ message: "fetch https://api.example/x?appid=abcdef", data: { to: "reader@example.com" } }],
    extra: { note: "from 203.0.113.9" },
  });

  assert.equal(event.user, undefined);
  assert.equal(event.server_name, undefined);
  assert.deepEqual(event.request, { url: "https://raqam.example/news/5", method: "POST" });
  assert.equal(event.transaction, "/news/[id]");
  assert.equal(event.exception.values[0].value, "API refused [hidden] for [email] from [ip] / [ip] at 12:30:45");
  assert.deepEqual(event.exception.values[0].stacktrace.frames, [{ filename: "a.js" }]);
  assert.equal(event.breadcrumbs[0].message, "fetch https://api.example/x?appid=[hidden]");
  assert.deepEqual(event.breadcrumbs[0].data, { to: "[email]" });
  assert.deepEqual(event.extra, { note: "from [ip]" });
  assert.equal(scrubText("signed?signature=abc123&expires=9"), "signed?signature=[hidden]&expires=9");
});

test("through the SDK: a request error reaches the transport cleaned (the envelope header carries only the public DSN, as Sentry requires)", async () => {
  process.env.SENTRY_DSN = EU;
  process.env.API_SITE_TOKEN = "site-token-value-0123456789";
  const sent = [];
  const transport = (options) => createTransport(options, async (request) => {
    sent.push(String(request.body));
    return { statusCode: 200 };
  });

  startErrorReports(EU, transport);
  await reportRequestError(new Error("Render failed for reader@example.com with site-token-value-0123456789"), "/news/[id]", "render");

  assert.equal(sent.length, 1);
  const body = sent[0];
  assert.ok(body.includes("Render failed for [email] with [hidden]"), body);
  assert.ok(body.includes("/news/[id]"));
  for (const secret of ["reader@example.com", "site-token-value-0123456789", "publickey1234@"]) {
    assert.ok(!body.split("\n").slice(1).join("\n").includes(secret), `the report holds ${secret}`);
  }
  await Sentry.close(1000);
});
