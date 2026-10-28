import Link from "next/link";
import { Suspense } from "react";
import { connection } from "next/server";

import { apiGet } from "@/lib/server/api";

const pages = [{ href: "/lab/brand", title: "الشعار", note: "كل نسخ الشعار على خلفية فاتحة وداكنة" }] as const;

type SiteAnswer = { data: { name?: string } };

async function ApiStatus() {
  await connection();
  const status = await apiGet<SiteAnswer>("/site", { cache: "no-store" }).then(
    (site) => `يعمل: ${site.data.name ?? "بدون اسم"}`,
    (error: unknown) => `لا يعمل: ${error instanceof Error ? error.message : "خطأ غير معروف"}`,
  );

  return <p>الاتصال بالـ API {status}</p>;
}

export default function LabIndex() {
  return (
    <main className="mx-auto w-full max-w-3xl px-4 py-10">
      <h1 className="mb-6 text-2xl font-bold">المختبر</h1>
      <Suspense fallback={<p>جاري فحص الاتصال…</p>}>
        <ApiStatus />
      </Suspense>
      <ul className="mt-8 space-y-3">
        {pages.map((page) => (
          <li key={page.href}>
            <Link href={page.href} className="inline-flex min-h-11 items-center underline underline-offset-4">
              {page.title}
            </Link>
            <span className="ms-2 opacity-70">{page.note}</span>
          </li>
        ))}
      </ul>
    </main>
  );
}
