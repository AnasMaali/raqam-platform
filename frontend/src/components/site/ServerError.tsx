"use client";

import Link from "next/link";

import { HeaderLogo } from "@/components/brand/Logo";

/**
 * What the 500 page shows (app/global-error.tsx): the logo, what happened in plain words, try again, and the home page.
 * The lab shows the same component (`preview`) so it can be seen and photographed: there its parts are plain boxes, not
 * the page's banner and main landmarks, its heading sits under the lab's own (h4), and «try again» reloads the lab page.
 */
export function ServerError({ retry, preview = false }: { retry?: () => void; preview?: boolean }) {
  const Banner = preview ? "div" : "header";
  const Body = preview ? "div" : "main";
  const Heading = preview ? "h4" : "h1";

  return (
    <>
      <Banner className="border-b border-line">
        <div className="mx-auto flex max-w-page items-center px-4 py-3 sm:px-6 lg:px-8">
          {/* The logo is the link home itself: wrapped in another link it was an <a> inside an <a>, which the browser
              splits into an empty link and a stray one. */}
          <HeaderLogo className="h-10 w-auto lg:h-12" />
        </div>
      </Banner>
      <Body id={preview ? undefined : "main"} className="mx-auto w-full max-w-page flex-1 px-4 py-16 sm:px-6 lg:px-8">
        <p className="text-small font-bold text-brand">خطأ في الخادم</p>
        <Heading className="mt-2 text-title font-bold">حدث خطأ من عندنا</Heading>
        <p className="mt-4 max-w-text text-lead text-muted">لم نتمكّن من عرض هذه الصفحة الآن. المشكلة ليست منك، ونعمل على إصلاحها. حاول مرة أخرى بعد قليل.</p>
        <p className="mt-8 flex flex-wrap gap-4">
          <button
            type="button"
            onClick={() => (retry ? retry() : window.location.reload())}
            className="inline-flex min-h-11 items-center rounded-sm bg-brand px-5 font-bold text-on-brand hover:bg-brand/90"
          >
            حاول مرة أخرى
          </button>
          <Link href="/" className="inline-flex min-h-11 items-center rounded-sm border border-line-strong px-5 font-bold hover:border-brand hover:text-brand">
            الصفحة الرئيسية
          </Link>
        </p>
      </Body>
    </>
  );
}
