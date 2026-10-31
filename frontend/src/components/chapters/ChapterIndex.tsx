export type Chapter = { id: string; name: string; href: string };

const tab =
  "slant-end inline-flex min-h-11 items-center bg-tint ps-3 pe-9 text-small font-medium text-brand hover:bg-brand hover:text-on-brand";

/**
 * The page's chapters as a short row of slanted tabs, swiped by hand. On phones and tablets it comes after the first
 * screen (it is not above the main story); from 1280 px the tabs stand on the page's edge instead (ChapterEdge).
 */
export function ChapterRow({ chapters, id = "chapters-row" }: { chapters: Chapter[]; id?: string }) {
  if (chapters.length < 2) return null;

  return (
    <nav aria-labelledby={`${id}-title`} className="xl:hidden">
      <h2 id={`${id}-title`} className="text-small font-bold text-muted">
        الأبواب
      </h2>
      <ul className="scroll-strip relative mt-2 flex min-w-0 gap-2">
        {chapters.map((c) => (
          <li key={c.id} className="shrink-0">
            <a href={c.href} className={tab}>
              {c.name}
            </a>
          </li>
        ))}
      </ul>
    </nav>
  );
}

/** From 1280 px: the thumb index on the page's edge, like the tabs of a printed directory. It stays while reading. */
export function ChapterEdge({ chapters, id = "chapters-edge" }: { chapters: Chapter[]; id?: string }) {
  if (chapters.length < 2) return null;

  return (
    <nav aria-labelledby={`${id}-title`} className="hidden xl:block">
      <div className="sticky top-6">
        <h2 id={`${id}-title`} className="text-small font-bold text-muted">
          الأبواب
        </h2>
        <ul className="mt-2 space-y-1">
          {chapters.map((c) => (
            <li key={c.id}>
              <a href={c.href} className={`${tab} w-full`}>
                {c.name}
              </a>
            </li>
          ))}
        </ul>
      </div>
    </nav>
  );
}
