"use client";

import { Main } from "@/components/site/Main";

/**
 * A page that failed to render (the platform did not answer, or answered with an error). It says so, offers to try
 * again, and is never kept in the cache: the next visit renders the page anew.
 */
export default function PageError({ retry }: { error: unknown; retry: () => void }) {
  return (
    <Main>
      <div className="mx-auto max-w-page px-4 py-16 sm:px-6 lg:px-8">
        <h1 className="text-title font-bold">تعذّر تحميل الصفحة الآن</h1>
        <p className="mt-4 max-w-text text-lead text-muted">حدث خطأ أثناء تحميل هذه الصفحة. حاول مرة أخرى بعد قليل.</p>
        <button type="button" onClick={() => retry()} className="mt-6 inline-flex min-h-11 items-center rounded-sm bg-brand px-5 font-bold text-on-brand hover:bg-brand/90">
          حاول مرة أخرى
        </button>
      </div>
    </Main>
  );
}
