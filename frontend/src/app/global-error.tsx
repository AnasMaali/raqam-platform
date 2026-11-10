"use client";

import Link from "next/link";

import { HeaderLogo } from "@/components/brand/Logo";
import { themeScript } from "@/components/theme/theme";

import { alexandria } from "./fonts";
import "./globals.css";

/**
 * The site's 500 page: what shows when even the page's frame failed (the root layout). It brings its own document,
 * fonts, styles and theme (Next.js renders it without the root layout), says what happened in plain words, and offers
 * to try again or go home. Never indexed.
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
        <header className="border-b border-line">
          <div className="mx-auto flex max-w-page items-center px-4 py-3 sm:px-6 lg:px-8">
            <Link href="/" aria-label="رَقَم: الصفحة الرئيسية" className="inline-flex min-h-11 items-center">
              <HeaderLogo />
            </Link>
          </div>
        </header>
        <main id="main" className="mx-auto w-full max-w-page flex-1 px-4 py-16 sm:px-6 lg:px-8">
          <p className="text-small font-bold text-brand">خطأ في الخادم</p>
          <h1 className="mt-2 text-title font-bold">حدث خطأ من عندنا</h1>
          <p className="mt-4 max-w-text text-lead text-muted">لم نتمكّن من عرض هذه الصفحة الآن. المشكلة ليست منك، ونعمل على إصلاحها. حاول مرة أخرى بعد قليل.</p>
          <p className="mt-8 flex flex-wrap gap-4">
            <button type="button" onClick={() => retry()} className="inline-flex min-h-11 items-center rounded-sm bg-brand px-5 font-bold text-on-brand hover:bg-brand/90">
              حاول مرة أخرى
            </button>
            <Link href="/" className="inline-flex min-h-11 items-center rounded-sm border border-line-strong px-5 font-bold hover:border-brand hover:text-brand">
              الصفحة الرئيسية
            </Link>
          </p>
        </main>
      </body>
    </html>
  );
}
