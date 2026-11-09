import Link from "next/link";
import type { ReactNode } from "react";

import { Icon } from "../ui/Icon";

/** How many items a chapter shows below 1024 px (DESIGN.md: three per chapter on phones); larger screens show all. */
export const onPhones = 3;
export const phoneOnly = (index: number) => (index >= onPhones ? "max-lg:hidden" : "");

export const chapterAnchor = (id: number) => `bab-${id}`;

/**
 * One chapter (باب) of the directory: a hairline rule opens it, its name stands in blue at the start with the link to
 * its own page, and its items sit beside it (below it on phones).
 */
export function Chapter({ id, name, href, linkText = "كل المواد", children }: { id: number; name: string; href?: string; linkText?: string; children: ReactNode }) {
  const anchor = chapterAnchor(id);

  return (
    <section id={anchor} aria-labelledby={`${anchor}-title`} className="scroll-mt-4 border-t border-line py-8 lg:grid lg:grid-cols-12 lg:gap-8 lg:py-10">
      <div className="mb-5 flex items-baseline justify-between gap-4 lg:col-span-3 lg:mb-0 lg:block xl:col-span-2">
        <h2 id={`${anchor}-title`} className="text-heading font-bold text-brand">
          {name}
        </h2>
        {href && (
          <Link href={href} className="inline-flex min-h-11 shrink-0 items-center gap-1 text-small font-medium hover:text-brand lg:mt-2">
            {linkText}
            <span className="sr-only"> في {name}</span>
            <Icon name="end" size={16} />
          </Link>
        )}
      </div>
      <div className="min-w-0 lg:col-span-9 xl:col-span-10">{children}</div>
    </section>
  );
}
