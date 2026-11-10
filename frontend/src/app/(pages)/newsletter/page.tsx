import type { Metadata } from "next";

import type { Crumb } from "@/components/article/ArticleParts";
import { NewsletterBlock } from "@/components/newsletter/NewsletterBlock";
import { PageHeader } from "@/components/pages/PageParts";

/*
 * The newsletter's own page (page inventory: /newsletter): the address, then the 6-digit code the platform mails,
 * then the confirmation, in the same form as everywhere else (placement newsletter_page). noindex: a form, not
 * content.
 */

export const metadata: Metadata = {
  title: "النشرة البريدية",
  description: "اشترك في نشرة رَقَم البريدية: أهم أخبار الاقتصاد في بريدك.",
  robots: { index: false, follow: true },
  alternates: { canonical: "/newsletter" },
};

const crumbs: Crumb[] = [
  { name: "الرئيسية", path: "/" },
  { name: "النشرة البريدية", path: "/newsletter" },
];

export default function NewsletterPage() {
  return (
    <div className="mx-auto max-w-page px-4 pt-2 pb-16 sm:px-6 lg:px-8">
      <PageHeader title="النشرة البريدية" crumbs={crumbs}>
        <p className="mt-3 max-w-text text-lead text-muted">اكتب بريدك، ثم الرمز المكوّن من 6 أرقام الذي نرسله إليك، ليصلك العدد القادم.</p>
      </PageHeader>
      <div className="mt-8">
        <NewsletterBlock title="اشترك في النشرة" placement="newsletter_page" path="/newsletter" />
      </div>
    </div>
  );
}
