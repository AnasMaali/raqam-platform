import Link from "next/link";

import { CompactCard, PostMeta, RankedCard } from "@/components/cards/PostCard";
import { marked, queryString, type SearchAnswer, type SearchQuery } from "@/lib/search";

/*
 * The search's results, the same on the server's first answer and on the instant answers in the browser: the
 * writers, guests and tags that match, the items with the matched words marked, the pages; and when nothing is found,
 * close words that do find something, the latest items and the most read (10.10).
 */

function People({ title, people }: { title: string; people: SearchAnswer["data"]["authors"] }) {
  if (people.length === 0) return null;

  return (
    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
      <h3 className="text-small font-bold text-muted">{title}</h3>
      <ul className="flex flex-wrap gap-x-3">
        {people.map((p) => (
          <li key={p.path}>
            <Link href={p.path} className="inline-flex min-h-11 items-center hover:text-brand" dangerouslySetInnerHTML={{ __html: marked(p.highlight.name) }} />
          </li>
        ))}
      </ul>
    </div>
  );
}

function Pages({ query, page, last }: { query: SearchQuery; page: number; last: number }) {
  if (last <= 1) return null;
  const to = (n: number) => `/search?${queryString(query, { page: n > 1 ? String(n) : undefined })}`;
  const around = [page - 2, page - 1, page, page + 1, page + 2].filter((n) => n >= 1 && n <= last);
  const button = "inline-flex min-h-11 min-w-11 items-center justify-center rounded-sm px-3";

  return (
    <nav aria-label="صفحات النتائج" className="mt-10">
      <ul className="figures flex flex-wrap items-center gap-2">
        {page > 1 && (
          <li>
            <Link href={to(page - 1)} rel="prev" className={`${button} border border-line-strong hover:border-brand hover:text-brand`}>
              السابق
            </Link>
          </li>
        )}
        {around.map((n) => (
          <li key={n}>
            {n === page ? (
              <span aria-current="page" className={`${button} bg-brand font-bold text-on-brand`}>
                {n}
              </span>
            ) : (
              <Link href={to(n)} className={`${button} border border-line hover:border-brand hover:text-brand`}>
                <span className="sr-only">صفحة </span>
                {n}
              </Link>
            )}
          </li>
        ))}
        {page < last && (
          <li>
            <Link href={to(page + 1)} rel="next" className={`${button} border border-line-strong hover:border-brand hover:text-brand`}>
              التالي
            </Link>
          </li>
        )}
      </ul>
    </nav>
  );
}

/** How many were found, in words a screen reader announces. */
export function countLine(answer: SearchAnswer | null): string {
  if (!answer) return "";
  const { total, page, last_page } = answer.meta;
  if (total === 0) return `لا نتائج لـ«${answer.data.query}».`;

  return `${total === 1 ? "نتيجة واحدة" : total === 2 ? "نتيجتان" : `${total} نتيجة`} لـ«${answer.data.query}»${last_page > 1 ? `، صفحة ${page} من ${last_page}` : ""}.`;
}

export function SearchResults({ answer, query }: { answer: SearchAnswer; query: SearchQuery }) {
  const { data, meta } = answer;

  if (data.posts.length === 0) {
    return (
      <div className="flex flex-col gap-10">
        {data.suggestions && data.suggestions.length > 0 && (
          <section aria-labelledby="suggestions">
            <h2 id="suggestions" className="text-subheading font-bold">
              هل تقصد:
            </h2>
            <ul className="mt-2 flex flex-wrap gap-2">
              {data.suggestions.map((s) => (
                <li key={s}>
                  <Link href={`/search?${queryString({ ...query, q: s, page: undefined })}`} className="inline-flex min-h-11 items-center rounded-sm border border-line px-3 hover:border-brand hover:text-brand">
                    {s}
                  </Link>
                </li>
              ))}
            </ul>
          </section>
        )}
        <div className="grid gap-10 lg:grid-cols-2">
          {data.latest && data.latest.length > 0 && (
            <section aria-labelledby="latest">
              <h2 id="latest" className="text-subheading font-bold">
                أحدث المواد
              </h2>
              <ul className="mt-2 divide-y divide-line">
                {data.latest.slice(0, 6).map((post) => (
                  <li key={post.id}>
                    <CompactCard post={post} />
                  </li>
                ))}
              </ul>
            </section>
          )}
          {data.most_read && data.most_read.length > 0 && (
            <section aria-labelledby="most-read">
              <h2 id="most-read" className="text-subheading font-bold">
                الأكثر قراءة
              </h2>
              <ol className="mt-2 divide-y divide-line">
                {data.most_read.slice(0, 6).map((post, i) => (
                  <li key={post.id}>
                    <RankedCard post={post} rank={i + 1} />
                  </li>
                ))}
              </ol>
            </section>
          )}
        </div>
      </div>
    );
  }

  return (
    <div>
      {(data.authors.length > 0 || data.guests.length > 0 || data.tags.length > 0) && (
        <section aria-label="كتّاب وضيوف ووسوم" className="mb-6 flex flex-col gap-1 border-b border-line pb-4">
          <People title="كتّاب" people={data.authors} />
          <People title="ضيوف" people={data.guests} />
          <People title="وسوم" people={data.tags} />
        </section>
      )}
      <ol className="divide-y divide-line">
        {data.posts.map((post) => (
          <li key={post.id}>
            <article className="group relative py-4 has-[a:focus-visible]:outline-2 has-[a:focus-visible]:outline-offset-4 has-[a:focus-visible]:outline-brand">
              <h2 className="text-subheading font-bold">
                <Link
                  href={post.path}
                  className="after:absolute after:inset-0 focus-visible:outline-none group-hover:text-brand"
                  dangerouslySetInnerHTML={{ __html: marked(post.highlight.title) }}
                />
              </h2>
              {post.highlight.excerpt && <p className="mt-1 max-w-text text-muted" dangerouslySetInnerHTML={{ __html: marked(post.highlight.excerpt) }} />}
              <PostMeta post={post} className="mt-2" />
            </article>
          </li>
        ))}
      </ol>
      <Pages query={query} page={meta.page} last={meta.last_page} />
    </div>
  );
}
