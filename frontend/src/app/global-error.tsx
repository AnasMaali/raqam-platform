"use client";

import { ServerError } from "@/components/site/ServerError";
import { themeScript } from "@/components/theme/theme";

import { alexandria } from "./fonts";
import "./globals.css";

/**
 * The site's 500 page: what shows when even the page's frame failed (the root layout). It brings its own document,
 * fonts, styles and theme (Next.js renders it without the root layout). What it says is components/site/ServerError,
 * which the lab (/lab/foundations) shows too. Never indexed.
 */
export default function GlobalError({ retry }: { error: unknown; retry: () => void }) {
  return (
    <html lang="ar" dir="rtl" className={alexandria.variable} suppressHydrationWarning>
      <head>
        <title>حدث خطأ | رقم</title>
        <meta name="robots" content="noindex" />
        <script dangerouslySetInnerHTML={{ __html: themeScript }} />
      </head>
      <body className="flex min-h-dvh flex-col">
        <ServerError retry={retry} />
      </body>
    </html>
  );
}
