"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";

import { cleanQuery, kinds, queryString, type SearchAnswer, type SearchQuery } from "@/lib/search";

import { SearchResults, countLine } from "./SearchResults";

/*
 * The search form and its results. Without JavaScript it is a plain GET form: the results page is drawn by the server.
 * With it, the results follow the typing: 250 ms after the last key or change (10.1), the site's own route is asked,
 * and an older request still on its way is cancelled. Pressing Enter opens the results page as usual (the visitor
 * settled on the query, so the platform counts it). The address follows the results, so going back works.
 */

type Option = { slug: string; name: string; children?: { slug: string; name: string }[] };

const fieldClass = "min-h-11 w-full rounded-sm border border-line-strong bg-ground px-3 text-body text-ink";

export function InstantSearch({
  initialQuery,
  initialAnswer,
  initialError,
  categories,
  sections,
}: {
  initialQuery: SearchQuery;
  initialAnswer: SearchAnswer | null;
  initialError: string | null;
  categories: Option[];
  sections: { slug: string; name: string }[];
}) {
  const [query, setQuery] = useState(initialQuery);
  const [answer, setAnswer] = useState(initialAnswer);
  const [error, setError] = useState(initialError);
  const [busy, setBusy] = useState(false);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const pending = useRef<AbortController | null>(null);

  useEffect(
    () => () => {
      if (timer.current) clearTimeout(timer.current);
      pending.current?.abort();
    },
    [],
  );

  function current(form: HTMLFormElement): SearchQuery {
    return cleanQuery(Object.fromEntries(new FormData(form).entries()) as Record<string, string>);
  }

  async function ask(next: SearchQuery) {
    pending.current?.abort();
    const controller = new AbortController();
    pending.current = controller;
    setBusy(true);
    try {
      const response = await fetch(`/api/search?${queryString(next)}`, { signal: controller.signal, headers: { Accept: "application/json" } });
      const body = await response.json().catch(() => null);
      if (controller.signal.aborted) return;
      if (response.ok && body?.data) {
        setAnswer(body as SearchAnswer);
        setError(null);
      } else {
        setError(body?.error?.message ?? "تعذّر البحث الآن. حاول بعد قليل.");
      }
      setQuery(next);
      window.history.replaceState(window.history.state, "", `/search?${queryString(next)}`);
    } catch (problem) {
      if ((problem as Error).name !== "AbortError") setError("تعذّر الاتصال. تأكّد من الشبكة وحاول مرة أخرى.");
    } finally {
      if (pending.current === controller) {
        pending.current = null;
        setBusy(false);
      }
    }
  }

  function changed(event: FormEvent<HTMLFormElement>) {
    const next = current(event.currentTarget);
    if (timer.current) clearTimeout(timer.current);
    if (!next.q || next.q.length < 2) {
      pending.current?.abort();
      return;
    }
    timer.current = setTimeout(() => void ask(next), 250);
  }

  function submitted() {
    // The results page takes over (and counts the search): nothing on its way should land after it.
    if (timer.current) clearTimeout(timer.current);
    pending.current?.abort();
  }

  const filtered = !!(query.category || query.section || query.kind || query.from || query.to || query.sort);

  return (
    <div>
      <form role="search" action="/search" method="get" onInput={changed} onChange={changed} onSubmit={submitted} className="flex flex-col gap-4">
        <div className="flex gap-2">
          <label htmlFor="search-q" className="sr-only">
            ابحث في رَقَم
          </label>
          <input
            id="search-q"
            name="q"
            type="search"
            defaultValue={initialQuery.q ?? ""}
            minLength={2}
            maxLength={120}
            autoComplete="off"
            enterKeyHint="search"
            placeholder="ابحث في الأخبار والتقارير والبودكاست…"
            aria-describedby="search-count"
            className={`${fieldClass} min-w-0 flex-1 text-lead`}
          />
          <button type="submit" className="inline-flex min-h-11 shrink-0 items-center rounded-sm bg-brand px-5 font-bold text-on-brand hover:bg-brand/90">
            ابحث
          </button>
        </div>
        <details open={filtered} className="group rounded-sm border border-line">
          <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between px-3 font-medium [&::-webkit-details-marker]:hidden">
            التصفية والترتيب
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.75" aria-hidden="true" className="transition-transform group-open:rotate-180 motion-reduce:transition-none">
              <path d="M6 9.5l6 6 6-6" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
          </summary>
          <div className="grid gap-4 border-t border-line p-3 sm:grid-cols-2 lg:grid-cols-3">
            <label className="flex flex-col gap-1 text-small font-medium">
              التصنيف
              <select name="category" defaultValue={initialQuery.category ?? ""} className={fieldClass}>
                <option value="">كل التصنيفات</option>
                {categories.map((c) => [
                  <option key={c.slug} value={c.slug}>
                    {c.name}
                  </option>,
                  ...(c.children ?? []).map((child) => (
                    <option key={child.slug} value={child.slug}>
                      {`${c.name}: ${child.name}`}
                    </option>
                  )),
                ])}
              </select>
            </label>
            <label className="flex flex-col gap-1 text-small font-medium">
              القسم
              <select name="section" defaultValue={initialQuery.section ?? ""} className={fieldClass}>
                <option value="">كل الأقسام</option>
                {sections.map((s) => (
                  <option key={s.slug} value={s.slug}>
                    {s.name}
                  </option>
                ))}
              </select>
            </label>
            <label className="flex flex-col gap-1 text-small font-medium">
              النوع
              <select name="kind" defaultValue={initialQuery.kind ?? ""} className={fieldClass}>
                <option value="">كل الأنواع</option>
                {kinds.map((k) => (
                  <option key={k.code} value={k.code}>
                    {k.name}
                  </option>
                ))}
              </select>
            </label>
            <label className="flex flex-col gap-1 text-small font-medium">
              من تاريخ
              <input type="date" name="from" defaultValue={initialQuery.from ?? ""} className={`${fieldClass} figures`} />
            </label>
            <label className="flex flex-col gap-1 text-small font-medium">
              إلى تاريخ
              <input type="date" name="to" defaultValue={initialQuery.to ?? ""} className={`${fieldClass} figures`} />
            </label>
            <label className="flex flex-col gap-1 text-small font-medium">
              الترتيب
              <select name="sort" defaultValue={initialQuery.sort ?? "relevance"} className={fieldClass}>
                <option value="relevance">الأنسب أولاً</option>
                <option value="latest">الأحدث أولاً</option>
              </select>
            </label>
          </div>
        </details>
      </form>

      <p id="search-count" aria-live="polite" className="mt-6 min-h-7 text-small text-muted">
        {busy ? "جارٍ البحث…" : error ? "" : countLine(answer)}
      </p>
      {error && (
        <p role="alert" className="mt-2 font-medium text-error">
          {error}
        </p>
      )}
      {answer && !error && (
        <div className={`mt-2 transition-opacity motion-reduce:transition-none ${busy ? "opacity-60" : ""}`}>
          <SearchResults answer={answer} query={query} />
        </div>
      )}
      {!answer && !error && !initialQuery.q && <p className="mt-2 text-muted">اكتب حرفين أو أكثر، وتظهر النتائج وأنت تكتب.</p>}
    </div>
  );
}
