import type { Metadata } from "next";
import Link from "next/link";

import { Main } from "@/components/site/Main";

// Kept out of search engines however it is served: from a route's notFound(), from the check's rewrite (src/proxy.ts),
// or from the page cache after a refresh, where Next.js does not always add its own noindex.
export const metadata: Metadata = { title: "الصفحة غير موجودة", robots: { index: false } };

/** Any address the site does not have, inside the site's frame, so the reader can carry on from the menus or the search. */
export default function NotFound() {
  return (
    <Main>
      <div className="mx-auto max-w-page px-4 py-16 sm:px-6 lg:px-8">
        <h1 className="text-title font-bold">الصفحة غير موجودة</h1>
        <p className="mt-4 max-w-text text-lead text-muted">ربما تغيّر عنوانها أو حُذفت. ابحث عمّا تريد، أو ابدأ من الصفحة الرئيسية.</p>
        <p className="mt-6 flex flex-wrap gap-x-6">
          <Link href="/search" className="inline-flex min-h-11 items-center font-medium text-brand underline">
            البحث
          </Link>
          <Link href="/" className="inline-flex min-h-11 items-center font-medium text-brand underline">
            الصفحة الرئيسية
          </Link>
        </p>
      </div>
    </Main>
  );
}
