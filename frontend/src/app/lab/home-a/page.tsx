import type { Metadata } from "next";
import { Suspense } from "react";

import { loadHome, postsOf, type Block, type Home, type Post } from "../_home/data";
import { factsLine, storyFigure } from "../_home/facts";
import { Ad, alexandria, Change, Logo, Pic, since, weatherCredit, formatDate } from "../_home/ui";
import "./home-a.css";

export const metadata: Metadata = { title: "الرئيسية: الدليل" };

type Chapter = Extract<Block, { type: "category" | "section" }>;

const anchor = (c: Chapter) => `bab-${c.id}`;

function Icon({ name }: { name: "menu" | "search" | "next" }) {
  const paths = {
    menu: "M3 6h18M3 12h18M3 18h18",
    search: "M10.5 17a6.5 6.5 0 1 0 0-13 6.5 6.5 0 0 0 0 13Zm5-1.5L21 21",
    // Points to the end of the line: left, in Arabic.
    next: "M15 5 8 12l7 7",
  };

  return (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d={paths[name]} />
    </svg>
  );
}

function Header({ home }: { home: Home }) {
  return (
    <header className="border-b border-[var(--rule)]">
      <div className="mx-auto flex max-w-[84rem] items-center justify-between gap-6 px-4 py-3 lg:px-8">
        <Logo className="h-9 w-auto lg:h-11" />
        <nav aria-label="الأقسام الرئيسية" className="hidden lg:block">
          <ul className="flex gap-6 text-[15px] font-medium">
            {home.menu.slice(1, 8).map((item) => (
              <li key={item.label}>
                <a href={item.href} className="inline-flex min-h-11 items-center hover:text-[var(--chapter)]">
                  {item.label}
                </a>
              </li>
            ))}
          </ul>
        </nav>
        <div className="flex items-center gap-1">
          <button type="button" aria-label="بحث" className="inline-flex size-11 items-center justify-center rounded-md hover:bg-[var(--tab)]">
            <Icon name="search" />
          </button>
          <button type="button" aria-label="القائمة" className="inline-flex size-11 items-center justify-center rounded-md hover:bg-[var(--tab)] lg:hidden">
            <Icon name="menu" />
          </button>
        </div>
      </div>
    </header>
  );
}

/** Today's rates, swiped by hand, never moving on their own. */
function RatesStrip({ home }: { home: Home }) {
  return (
    <section aria-labelledby="rates-a" className="border-b border-[var(--rule)]">
      <div className="mx-auto flex max-w-[84rem] items-center gap-4 px-4 lg:px-8">
        <h2 id="rates-a" className="shrink-0 py-2 text-xs font-medium text-[var(--muted)]">
          العملات {home.rateDate && <span className="whitespace-nowrap">· {formatDate(`${home.rateDate}T12:00:00Z`)}</span>}
        </h2>
        <ul className={`ha-strip relative flex min-w-0 snap-x gap-6 overflow-x-auto py-2 text-sm`}>
          {home.rates.map((r) => (
            <li key={r.code} className="flex shrink-0 snap-start items-center gap-2">
              <a href={r.path} className="inline-flex min-h-11 items-center gap-2 hover:text-[var(--chapter)]">
                <span>{r.name}</span>
                <span dir="ltr" className="font-medium tabular-nums">{r.mid}</span>
                {r.change_pct !== null && <Change value={Number(r.change_pct)} tabular className="text-[var(--muted)]" />}
              </a>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}

function Meta({ post }: { post: Post }) {
  return (
    <p className="mt-2 text-sm text-[var(--muted)]">
      {[post.category?.name ?? post.kind.name, since(post.published_at)].join(" · ")}
      {post.sponsor && <span className="ms-2 rounded-sm border border-[var(--rule)] px-1.5 text-xs">محتوى ممول</span>}
    </p>
  );
}

function Lead({ home }: { home: Home }) {
  const [lead, ...rest] = postsOf(home, "hero");
  if (!lead) return null;

  return (
    <section aria-labelledby="lead-a" className="mx-auto max-w-[84rem] px-4 pt-5 lg:px-8 lg:pt-10">
      <div className="grid gap-5 lg:grid-cols-12 lg:gap-8">
        <div className="lg:col-span-5 lg:self-center">
          <h2 id="lead-a" className="text-[clamp(1.6rem,1.15rem+2.2vw,3rem)] leading-[1.3] font-bold text-balance">
            <a href={lead.path} className="hover:text-[var(--chapter)]">
              {lead.title}
            </a>
          </h2>
          {lead.excerpt && <p className="mt-3 text-[1.0625rem] leading-[1.8] text-[var(--muted)] lg:text-lg">{lead.excerpt}</p>}
          <Meta post={lead} />
        </div>
        <a href={lead.path} className="block lg:col-span-7" tabIndex={-1} aria-hidden="true">
          <Pic media={lead.cover} sizes="(min-width: 1024px) 58vw, 100vw" priority className="aspect-[16/9] w-full object-cover" />
        </a>
      </div>
      <ul className="mt-8 grid border-t border-[var(--rule)] sm:grid-cols-2 lg:grid-cols-4">
        {rest.map((post) => (
          <li key={post.id} className="border-b border-[var(--rule)] py-4 sm:even:ps-6 lg:border-b-0 lg:px-6 lg:first:ps-0 lg:[&:not(:last-child)]:border-e">
            <h3 className="text-[1.0625rem] leading-[1.6] font-bold">
              <a href={post.path} className="hover:text-[var(--chapter)]">
                {post.title}
              </a>
            </h3>
            <Meta post={post} />
          </li>
        ))}
      </ul>
    </section>
  );
}

/** One chapter of the directory: its name on the start side, its entries beside it. */
function ChapterRow({ chapter }: { chapter: Chapter }) {
  const [first, ...rest] = chapter.posts;
  const name = chapter.type === "category" ? chapter.category.name : chapter.section.name;
  const href = chapter.type === "category" ? `/category/${chapter.category.slug}` : `/${chapter.section.slug}`;
  if (!first) return null;
  const figure = storyFigure(first);

  return (
    <section id={anchor(chapter)} aria-labelledby={`${anchor(chapter)}-t`} className="scroll-mt-4 border-t border-[var(--rule)] py-8 lg:grid lg:grid-cols-12 lg:gap-8 lg:py-10">
      <div className="mb-5 flex items-baseline justify-between gap-4 lg:col-span-3 lg:mb-0 lg:block">
        <h2 id={`${anchor(chapter)}-t`} className="text-2xl font-bold text-[var(--chapter)] lg:text-[1.75rem]">
          {name}
        </h2>
        <a href={href} className="inline-flex min-h-11 items-center gap-1 text-sm font-medium hover:text-[var(--chapter)] lg:mt-3">
          كل المواد <Icon name="next" />
        </a>
      </div>
      <div className="grid gap-6 sm:grid-cols-2 lg:col-span-9 lg:grid-cols-3">
        <article className="sm:col-span-2">
          <a href={first.path} className="block" tabIndex={-1} aria-hidden="true">
            <Pic media={first.cover} sizes="(min-width: 1024px) 40vw, 100vw" className="aspect-[3/2] w-full object-cover" />
          </a>
          {figure && (
            <p className="mt-4 text-[var(--chapter)]">
              <span className="text-4xl font-bold">{figure.value}</span> <span className="text-lg">{figure.label}</span>
            </p>
          )}
          <h3 className="mt-3 text-xl leading-[1.5] font-bold text-balance">
            <a href={first.path} className="hover:text-[var(--chapter)]">
              {first.title}
            </a>
          </h3>
          {factsLine(first) && <p className="mt-1 text-sm font-medium">{factsLine(first)}</p>}
          <p className="mt-1 text-sm text-[var(--muted)]">{since(first.published_at)}</p>
        </article>
        <ul className="divide-y divide-[var(--rule)] border-y border-[var(--rule)] sm:col-span-2 lg:col-span-1 lg:border-t-0">
          {rest.slice(0, 4).map((post, i) => (
            <li key={post.id} className={`py-3 first:pt-0 ${i >= 2 ? "hidden sm:block" : ""}`}>
              <h3 className="leading-[1.6] font-medium">
                <a href={post.path} className="hover:text-[var(--chapter)]">
                  {post.title}
                </a>
              </h3>
              <p className="mt-1 text-sm text-[var(--muted)]">{factsLine(post) ?? since(post.published_at)}</p>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}

function Markets({ home }: { home: Home }) {
  const city = home.cities.find((c) => c.is_default) ?? home.cities[0];

  return (
    <section id="bab-markets" aria-labelledby="bab-markets-t" className="scroll-mt-4 border-t border-[var(--rule)] py-8 lg:grid lg:grid-cols-12 lg:gap-8 lg:py-10">
      <div className="mb-5 lg:col-span-3 lg:mb-0">
        <h2 id="bab-markets-t" className="text-2xl font-bold text-[var(--chapter)] lg:text-[1.75rem]">
          الأسواق والطقس
        </h2>
        <p className="mt-2 text-sm text-[var(--muted)]">{home.rateNote}</p>
      </div>
      <div className="grid gap-8 lg:col-span-9 lg:grid-cols-3">
        <table className="w-full text-[15px] lg:col-span-2">
          <caption className="sr-only">أسعار العملات مقابل الشيكل</caption>
          <thead>
            <tr className="border-b border-[var(--rule)] text-sm text-[var(--muted)]">
              <th scope="col" className="py-2 text-start font-medium">العملة</th>
              <th scope="col" className="py-2 text-end font-medium">شراء</th>
              <th scope="col" className="py-2 text-end font-medium">بيع</th>
              <th scope="col" className="py-2 text-end font-medium">التغيّر</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[var(--rule)]">
            {home.rates.map((r) => (
              <tr key={r.code}>
                <th scope="row" className="py-2.5 text-start font-normal">
                  <a href={r.path} className="hover:text-[var(--chapter)]">{r.name}</a>
                </th>
                <td dir="ltr" className="py-2.5 text-start tabular-nums">{r.buy}</td>
                <td dir="ltr" className="py-2.5 text-start tabular-nums">{r.sell}</td>
                <td className="py-2.5 text-end">{r.change_pct === null ? "—" : <Change value={Number(r.change_pct)} tabular />}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {city?.now && (
          <div>
            <h3 className="font-bold">طقس {city.name}</h3>
            <p className="mt-2 text-5xl font-bold">
              <span dir="ltr">{Math.round(city.now.temperature)}°</span>
            </p>
            <p className="mt-1">{city.now.condition.text}</p>
            <ul className="mt-4 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
              {home.cities.filter((c) => c !== city && c.now).slice(0, 6).map((c) => (
                <li key={c.slug} className="flex justify-between">
                  <span>{c.name}</span>
                  <span dir="ltr" className="tabular-nums">{Math.round(c.now!.temperature)}°</span>
                </li>
              ))}
            </ul>
            <p dir="ltr" className="mt-4 text-end text-xs text-[var(--muted)]">{weatherCredit}</p>
          </div>
        )}
      </div>
    </section>
  );
}

function ThumbIndex({ chapters }: { chapters: Chapter[] }) {
  return (
    <nav aria-label="أبواب الصفحة" className="hidden xl:block">
      <ul className="sticky top-6 space-y-1">
        {chapters.map((c) => (
          <li key={c.id}>
            <a href={`#${anchor(c)}`} className={`ha-tab flex min-h-11 items-center bg-[var(--tab)] ps-3 pe-5 text-sm font-medium text-[var(--tab-ink)] hover:bg-[var(--chapter)] hover:text-[var(--ground)]`}>
              {c.type === "category" ? c.category.name : c.section.name}
            </a>
          </li>
        ))}
        <li>
          <a href="#bab-markets" className={`ha-tab flex min-h-11 items-center bg-[var(--tab)] ps-3 pe-5 text-sm font-medium text-[var(--tab-ink)] hover:bg-[var(--chapter)] hover:text-[var(--ground)]`}>
            الأسواق والطقس
          </a>
        </li>
      </ul>
    </nav>
  );
}

function MostRead({ home }: { home: Home }) {
  const posts = postsOf(home, "most_read");

  return (
    <section aria-labelledby="most-read-a" className="border-t border-[var(--rule)] py-8 lg:py-10">
      <h2 id="most-read-a" className="text-2xl font-bold text-[var(--chapter)]">الأكثر قراءة</h2>
      <ol className="mt-4 grid gap-x-8 sm:grid-cols-2 lg:grid-cols-5">
        {posts.map((post, i) => (
          <li key={post.id} className="flex gap-3 border-b border-[var(--rule)] py-3 lg:border-b-0">
            <span aria-hidden="true" className="text-3xl leading-none font-bold text-[var(--accent)]">{i + 1}</span>
            <a href={post.path} className="leading-[1.6] font-medium hover:text-[var(--chapter)]">{post.title}</a>
          </li>
        ))}
      </ol>
    </section>
  );
}

async function DirectoryHome() {
  const home = await loadHome();
  const chapters = home.blocks.filter((b): b is Chapter => b.type === "category" || b.type === "section");
  const between = home.ads.find((a) => a.code === "between_sections");

  return (
    <div className={`ha-page ${alexandria.className} min-h-dvh`}>
      <Header home={home} />
      <RatesStrip home={home} />
      <main>
        <h1 className="sr-only">رقم: دليل الاقتصاد اليوم</h1>
        <nav aria-label="أبواب الصفحة" className="mx-auto max-w-[84rem] border-b border-[var(--rule)] px-4 py-2 lg:px-8 xl:hidden">
          <ul className={`ha-strip relative flex min-w-0 gap-2 overflow-x-auto pb-1`}>
            {chapters.map((c) => (
              <li key={c.id} className="shrink-0">
                <a href={`#${anchor(c)}`} className={`ha-tab flex min-h-11 items-center bg-[var(--tab)] ps-3 pe-5 text-sm font-medium text-[var(--tab-ink)]`}>
                  {c.type === "category" ? c.category.name : c.section.name}
                </a>
              </li>
            ))}
          </ul>
        </nav>
        <Lead home={home} />
        <div className="mx-auto max-w-[84rem] px-4 lg:px-8 xl:grid xl:grid-cols-[1fr_12rem] xl:gap-10">
          <div>
            {chapters.slice(0, 3).map((c) => (
              <ChapterRow key={c.id} chapter={c} />
            ))}
            <Ad slot={between} className="border-t border-[var(--rule)] py-8" />
            <Markets home={home} />
            {chapters.slice(3).map((c) => (
              <ChapterRow key={c.id} chapter={c} />
            ))}
            <MostRead home={home} />
          </div>
          <ThumbIndex chapters={chapters} />
        </div>
      </main>
      <footer className="mt-8 border-t border-[var(--rule)] py-8">
        <div className="mx-auto flex max-w-[84rem] flex-wrap items-center justify-between gap-4 px-4 lg:px-8">
          <Logo className="h-8 w-auto" />
          <p className="text-sm text-[var(--muted)]">تاريخ الاقتصاد ومستقبله · نابلس</p>
        </div>
      </footer>
    </div>
  );
}

export default function HomeA() {
  return (
    <Suspense fallback={<p className="p-8">جاري التحميل…</p>}>
      <DirectoryHome />
    </Suspense>
  );
}
