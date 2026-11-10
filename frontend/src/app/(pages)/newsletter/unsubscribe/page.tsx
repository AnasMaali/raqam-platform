import type { Metadata } from "next";
import Link from "next/link";

import type { Crumb } from "@/components/article/ArticleParts";
import { UnsubscribeButton } from "@/components/newsletter/UnsubscribeButton";
import { PageHeader } from "@/components/pages/PageParts";
import { linkValues } from "@/lib/server/unsubscribe";

/*
 * Unsubscribing (page inventory: /newsletter/unsubscribe?s=&t=, the link in every newsletter). Opening the page
 * changes nothing: a mail program's link scanner opens links too. The visitor presses the button, and the site's route
 * asks the platform. noindex, and the link's signature never leaves in a Referer.
 */
export const instant = false;

export const metadata: Metadata = {
  title: "إلغاء الاشتراك في النشرة",
  robots: { index: false, follow: false },
  referrer: "no-referrer",
};

const crumbs: Crumb[] = [
  { name: "الرئيسية", path: "/" },
  { name: "النشرة البريدية", path: "/newsletter" },
  { name: "إلغاء الاشتراك", path: "/newsletter/unsubscribe" },
];

export default async function UnsubscribePage({ searchParams }: PageProps<"/newsletter/unsubscribe">) {
  const query = await searchParams;
  const one = (key: string) => (Array.isArray(query[key]) ? query[key][0] : query[key]);
  const values = linkValues(one("s"), one("t"));

  return (
    <div className="mx-auto max-w-page px-4 pt-2 pb-16 sm:px-6 lg:px-8">
      <PageHeader title="إلغاء الاشتراك في النشرة" crumbs={crumbs} />
      <div className="mt-6 max-w-text">
        {values ? (
          <>
            <p className="text-lead">هل تريد إيقاف نشرة رَقَم البريدية؟ لن يتغيّر شيء حتى تضغط الزر.</p>
            <div className="mt-6">
              <UnsubscribeButton s={values.subscriber} t={values.signature} />
            </div>
          </>
        ) : (
          <>
            <p className="text-lead">هذا الرابط غير مكتمل. افتح رابط إلغاء الاشتراك كما وصلك في آخر النشرة.</p>
            <p className="mt-4">
              <Link href="/contact" className="inline-flex min-h-11 items-center font-medium text-brand underline">
                أو تواصل معنا لإيقافها
              </Link>
            </p>
          </>
        )}
      </div>
    </div>
  );
}
