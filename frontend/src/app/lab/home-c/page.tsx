import type { Metadata } from "next";
import { Suspense } from "react";

import { loadHome, postsOf, type Block, type Home, type Post } from "../_home/data";
import { factsLine, storyFigure } from "../_home/facts";
import { Ad, alexandria, Arrow, formatDate, Logo, Pic, since, weatherCredit } from "../_home/ui";
import "./home-c.css";

export const metadata: Metadata = { title: "الرئيسية: لوحة الصرّاف" };

type Row = Extract<Block, { type: "category" | "section" }>;

const minus = "−";
const pct = new Intl.NumberFormat("ar-u-nu-latn", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function Icon({ name }: { name: "menu" | "search" | "next" }) {
  const d = { menu: "M3 6h18M3 12h18M3 18h18", search: "M10.5 17a6.5 6.5 0 1 0 0-13 6.5 6.5 0 0 0 0 13Zm5-1.5L21 21", next: "M15 5 8 12l7 7" }[name];

  return (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d={d} />
    </svg>
  );
}

function Header({ home }: { home: Home }) {
  return (
    <header className="bg-[var(--sheet)]">
      <div className="mx-auto flex max-w-[80rem] items-center justify-between gap-6 px-4 py-3 lg:px-8">
        <Logo className="h-9 w-auto lg:h-10" />
        <nav aria-label="الأقسام الرئيسية" className="hidden lg:block">
          <ul className="flex gap-6 text-[15px] font-medium">
            {home.menu.slice(1, 8).map((item) => (
              <li key={item.label}>
                <a href={item.href} className="inline-flex min-h-11 items-center hover:text-[var(--link)]">
                  {item.label}
                </a>
              </li>
            ))}
          </ul>
        </nav>
        <div className="flex items-center gap-1">
          <button type="button" aria-label="بحث" className="inline-flex size-11 items-center justify-center hover:bg-[var(--ground)]">
            <Icon name="search" />
          </button>
          <button type="button" aria-label="القائمة" className="inline-flex size-11 items-center justify-center hover:bg-[var(--ground)] lg:hidden">
            <Icon name="menu" />
          </button>
        </div>
      </div>
    </header>
  );
}

/** The board: one type size; the header row is reversed, the dollar row is bold. */
function Board({ home }: { home: Home }) {
  const city = home.cities.find((c) => c.is_default) ?? home.cities[0];

  return (
    <section aria-labelledby="board-title" className={`hc-board bg-[var(--board)] text-[15px] text-[var(--board-ink)]`}>
      <div className="flex items-center justify-between gap-3 border-b border-[var(--board-rule)] px-4 py-2.5">
        <h2 id="board-title" className="font-bold">
          أسعار اليوم مقابل الشيكل
        </h2>
        {home.rateDate && <p className="text-[var(--board-muted)]">{formatDate(`${home.rateDate}T12:00:00Z`)}</p>}
      </div>
      <table className="w-full">
        <caption className="sr-only">أسعار العملات مقابل الشيكل: الشراء والبيع والتغيّر</caption>
        <thead>
          <tr className="bg-[var(--board-head)] text-[var(--board-head-ink)]">
            <th scope="col" className="px-4 py-2 text-start font-bold">العملة</th>
            <th scope="col" className="px-2 py-2 text-end font-bold">شراء</th>
            <th scope="col" className="px-2 py-2 text-end font-bold">بيع</th>
            <th scope="col" className="px-4 py-2 text-end font-bold">التغيّر</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-[var(--board-rule)]">
          {home.rates.map((r, i) => {
            const change = r.change_pct === null ? null : Number(r.change_pct);

            return (
              <tr key={r.code} className={i >= 3 ? "hidden lg:table-row" : undefined}>
                <th scope="row" className={`px-4 py-2.5 text-start ${i === 0 ? "font-bold" : "font-normal"}`}>
                  <a href={r.path} className="hover:underline">{r.name}</a>
                </th>
                <td dir="ltr" className={`px-2 py-2.5 text-start tabular-nums ${i === 0 ? "font-bold" : ""}`}>{r.buy}</td>
                <td dir="ltr" className={`px-2 py-2.5 text-start tabular-nums ${i === 0 ? "font-bold" : ""}`}>{r.sell}</td>
                <td className="px-4 py-2.5 text-end">
                  {change === null ? (
                    "—"
                  ) : (
                    <span className="inline-flex items-center gap-1 text-[var(--board-muted)]">
                      <Arrow direction={change > 0 ? "up" : change < 0 ? "down" : "same"} className={change > 0 ? "text-[var(--board-head)]" : ""} />
                      <span className="sr-only">{change > 0 ? "ارتفاع" : change < 0 ? "انخفاض" : "بدون تغيّر"}</span>
                      <span dir="ltr" className="tabular-nums">{`${change > 0 ? "+" : change < 0 ? minus : ""}${pct.format(Math.abs(change))}%`}</span>
                    </span>
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t border-[var(--board-rule)] px-4 py-2.5 text-[var(--board-muted)]">
        <a href="/currencies" className="inline-flex min-h-11 items-center gap-1 font-bold text-[var(--board-ink)] hover:underline lg:hidden">
          كل العملات <Icon name="next" />
        </a>
        <p className="hidden lg:block">{home.rateNote}</p>
        {city?.now && (
          <p>
            {city.name} <span dir="ltr" className="font-bold text-[var(--board-ink)]">{Math.round(city.now.temperature)}°</span> {city.now.condition.text}
            <span dir="ltr" className="block text-end text-xs">{weatherCredit}</span>
          </p>
        )}
      </div>
    </section>
  );
}

function Lead({ post }: { post: Post }) {
  return (
    <article className="bg-[var(--sheet)]">
      <a href={post.path} tabIndex={-1} aria-hidden="true" className="block">
        <Pic media={post.cover} sizes="(min-width: 1024px) 55vw, 100vw" priority className="aspect-[16/9] w-full object-cover" />
      </a>
      <div className="p-4 lg:p-6">
        <h2 className="text-[clamp(1.5rem,1.1rem+2vw,2.6rem)] leading-[1.3] font-bold text-balance">
          <a href={post.path} className="hover:text-[var(--link)]">
            {post.title}
          </a>
        </h2>
        {post.excerpt && <p className="mt-3 text-[1.0625rem] leading-[1.8] text-[var(--muted)]">{post.excerpt}</p>}
        <p className="mt-2 text-sm text-[var(--muted)]">
          {post.category?.name} · {since(post.published_at)}
        </p>
      </div>
    </article>
  );
}

/** A row of the page in the board's grammar: the label cell, then the value cells. */
function BoardRow({ id, label, href, posts, ranked = false }: { id: string; label: string; href?: string; posts: Post[]; ranked?: boolean }) {
  if (posts.length === 0) return null;

  return (
    <section aria-labelledby={id} className="bg-[var(--sheet)]">
      <div className="flex items-stretch justify-between">
        <h2 id={id} className={`hc-label bg-[var(--label-bg)] py-2 ps-4 pe-8 text-[15px] font-bold text-[var(--label-ink)]`}>
          {label}
        </h2>
        {href && (
          <a href={href} className="inline-flex min-h-11 items-center gap-1 px-4 text-sm font-medium hover:text-[var(--link)]">
            الكل <Icon name="next" />
          </a>
        )}
      </div>
      <ol className="grid divide-y divide-[var(--rule)] sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-4">
        {posts.slice(0, 4).map((p, i) => {
          const figure = storyFigure(p);

          return (
            <li key={p.id} className={`flex gap-3 p-4 sm:border-[var(--rule)] sm:[&:nth-child(n+3)]:border-t lg:[&:nth-child(n+3)]:border-t-0 lg:[&:not(:first-child)]:border-s ${i >= 3 ? "hidden sm:flex" : ""}`}>
              {ranked && (
                <span aria-hidden="true" className="text-2xl leading-none font-bold text-[var(--link)] tabular-nums">
                  {i + 1}
                </span>
              )}
              <div className="min-w-0">
                {i === 0 && !ranked && p.cover && (
                  <a href={p.path} tabIndex={-1} aria-hidden="true" className="mb-3 block">
                    <Pic media={p.cover} sizes="(min-width: 1024px) 22vw, (min-width: 640px) 45vw, 100vw" className="aspect-[3/2] w-full object-cover" />
                  </a>
                )}
                {figure && (
                  <p className="text-[var(--link)]">
                    <span className="text-2xl font-bold">{figure.value}</span> {figure.label}
                  </p>
                )}
                <h3 className="leading-[1.6] font-bold">
                  <a href={p.path} className="hover:text-[var(--link)]">
                    {p.title}
                  </a>
                </h3>
                <p className="mt-1 text-sm text-[var(--muted)]">{factsLine(p) ?? since(p.published_at)}</p>
              </div>
            </li>
          );
        })}
      </ol>
    </section>
  );
}

async function BoardHome() {
  const home = await loadHome();
  const [lead, ...top] = postsOf(home, "hero");
  const rows = home.blocks.filter((b): b is Row => b.type === "category" || b.type === "section");
  const between = home.ads.find((a) => a.code === "between_sections");
  const awareness = postsOf(home, "awareness")[0];

  return (
    <div className={`hc-page ${alexandria.className} min-h-dvh`}>
      <Header home={home} />
      <main className="mx-auto max-w-[80rem] space-y-3 px-0 pb-10 sm:px-4 lg:px-8">
        <h1 className="sr-only">رقم: لوحة اليوم</h1>
        <div className="grid gap-3 md:grid-cols-12">
          <div className="md:col-span-6 lg:col-span-5">
            <Board home={home} />
            {awareness && (
              <a href={awareness.path} className="mt-3 hidden items-center gap-4 bg-[var(--sheet)] p-4 hover:text-[var(--link)] lg:flex">
                <Pic media={awareness.cover} sizes="96px" className="size-24 shrink-0 object-cover" />
                <span>
                  <span className="block text-sm text-[var(--muted)]">{factsLine(awareness)}</span>
                  <span className="block text-lg leading-[1.5] font-bold">{awareness.title}</span>
                </span>
              </a>
            )}
          </div>
          <div className="md:col-span-6 lg:col-span-7">{lead && <Lead post={lead} />}</div>
        </div>
        <BoardRow id="row-top" label="أهم الأخبار" posts={top} />
        {rows.slice(0, 3).map((r) => (
          <BoardRow key={r.id} id={`row-${r.id}`} label={r.type === "category" ? r.category.name : r.section.name} href={r.type === "category" ? `/category/${r.category.slug}` : `/${r.section.slug}`} posts={r.posts} />
        ))}
        <div className="bg-[var(--sheet)] px-4 py-6">
          <Ad slot={between} />
        </div>
        <BoardRow id="row-most" label="الأكثر قراءة" posts={postsOf(home, "most_read")} ranked />
        {rows.slice(3).map((r) => (
          <BoardRow key={r.id} id={`row-${r.id}`} label={r.type === "category" ? r.category.name : r.section.name} href={r.type === "category" ? `/category/${r.category.slug}` : `/${r.section.slug}`} posts={r.posts} />
        ))}
      </main>
      <footer className="bg-[var(--board)] py-8 text-[var(--board-ink)]">
        <div className="mx-auto flex max-w-[80rem] flex-wrap items-center justify-between gap-4 px-4 lg:px-8">
          {/* eslint-disable-next-line @next/next/no-img-element -- the logo file as it is */}
          <img src="/brand/logo-ar-horizontal-white.svg" alt="رقم" width={372} height={135} className="h-8 w-auto" />
          <p className="text-sm text-[var(--board-muted)]">تاريخ الاقتصاد ومستقبله · نابلس</p>
        </div>
      </footer>
    </div>
  );
}

export default function HomeC() {
  return (
    <Suspense fallback={<p className="p-8">جاري التحميل…</p>}>
      <BoardHome />
    </Suspense>
  );
}
