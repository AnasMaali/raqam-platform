import type { Metadata } from "next";
import { Suspense } from "react";

import { loadHome, postsOf, type Home, type Post, type Video } from "../_home/data";
import { factsLine, historyYear, storyFigure } from "../_home/facts";
import { Ad, alexandria, Change, formatClock, formatDate, formatWeekday, Logo, Pic, since, weatherCredit } from "../_home/ui";
import "./home-b.css";

export const metadata: Metadata = { title: "الرئيسية: الكشيدة" };

type Entry = { kind: "post"; post: Post; at: string } | { kind: "video"; video: Video; at: string };

const DAY = 24 * 60 * 60 * 1000;

function Icon({ name }: { name: "menu" | "search" | "play" }) {
  if (name === "play") {
    return (
      <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
        <path d="M8 5.5v13l11-6.5z" fill="currentColor" />
      </svg>
    );
  }
  const d = name === "menu" ? "M3 6h18M3 12h18M3 18h18" : "M10.5 17a6.5 6.5 0 1 0 0-13 6.5 6.5 0 0 0 0 13Zm5-1.5L21 21";

  return (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true">
      <path d={d} />
    </svg>
  );
}

/** Every item of the home blocks once, newest first; history waits for its own place at the end of the line. */
function timeline(home: Home) {
  const seen = new Set<number>();
  const posts: Post[] = [];
  for (const block of home.blocks) {
    if (!("posts" in block)) continue;
    for (const post of block.posts) {
      if (!seen.has(post.id)) {
        seen.add(post.id);
        posts.push(post);
      }
    }
  }
  const history = posts.filter((p) => p.kind.code === "history").sort((a, b) => (historyYear(b) ?? 0) - (historyYear(a) ?? 0));
  const videos = home.blocks.flatMap((b) => ("videos" in b && b.type === "videos" ? b.videos : [])).slice(0, 4);
  const entries: Entry[] = [
    ...posts.filter((p) => p.kind.code !== "history").map((post) => ({ kind: "post" as const, post, at: post.published_at })),
    ...videos.map((video) => ({ kind: "video" as const, video, at: video.published_at })),
  ].sort((a, b) => b.at.localeCompare(a.at));

  return { entries, history };
}

function group(entries: Entry[], now: number) {
  const age = (e: Entry) => now - new Date(e.at).getTime();

  return [
    { title: "اليوم", items: entries.filter((e) => age(e) < DAY) },
    { title: "أمس", items: entries.filter((e) => age(e) >= DAY && age(e) < 2 * DAY) },
    { title: "هذا الأسبوع", items: entries.filter((e) => age(e) >= 2 * DAY && age(e) < 7 * DAY).slice(0, 8) },
    { title: "قبل ذلك", items: entries.filter((e) => age(e) >= 7 * DAY).slice(0, 6) },
  ].filter((g) => g.items.length > 0);
}

function Header({ home }: { home: Home }) {
  const today = new Date(home.now).toISOString();

  return (
    <header className="border-b border-[var(--rule)]">
      <div className="mx-auto flex max-w-[78rem] items-center justify-between gap-4 px-4 py-3 lg:px-8">
        <div className="flex items-center gap-4">
          <Logo className="h-9 w-auto lg:h-10" />
          <p className="hidden border-s border-[var(--rule)] ps-4 text-sm sm:block">
            <span className="font-bold">{formatWeekday(today)}</span> <span className="text-[var(--muted)]">{formatDate(today)}</span>
          </p>
        </div>
        <nav aria-label="الأقسام الرئيسية" className="hidden lg:block">
          <ul className="flex gap-5 text-[15px] font-medium">
            {home.menu.slice(1, 7).map((item) => (
              <li key={item.label}>
                <a href={item.href} className="inline-flex min-h-11 items-center hover:text-[var(--spine)]">
                  {item.label}
                </a>
              </li>
            ))}
          </ul>
        </nav>
        <div className="flex items-center gap-1">
          <button type="button" aria-label="بحث" className="inline-flex size-11 items-center justify-center rounded-full hover:bg-[var(--panel)]">
            <Icon name="search" />
          </button>
          <button type="button" aria-label="القائمة" className="inline-flex size-11 items-center justify-center rounded-full hover:bg-[var(--panel)] lg:hidden">
            <Icon name="menu" />
          </button>
        </div>
      </div>
    </header>
  );
}

function Rates({ home, compact = false }: { home: Home; compact?: boolean }) {
  const rates = compact ? home.rates.slice(0, 4) : home.rates;

  return (
    <ul className={compact ? `hb-strip relative flex min-w-0 gap-5 overflow-x-auto text-sm` : "divide-y divide-[var(--rule)] text-[15px]"}>
      {rates.map((r) => (
        <li key={r.code} className={compact ? "shrink-0" : "py-2"}>
          <a href={r.path} className={`inline-flex min-h-11 items-center gap-2 hover:text-[var(--spine)] ${compact ? "" : "w-full justify-between"}`}>
            <span>{r.name}</span>
            <span className="inline-flex items-center gap-3">
              <span dir="ltr" className="font-bold tabular-nums">{r.mid}</span>
              {r.change_pct !== null && <Change value={Number(r.change_pct)} tabular className="text-[var(--muted)]" />}
            </span>
          </a>
        </li>
      ))}
    </ul>
  );
}

function Item({ entry, withPicture }: { entry: Entry; withPicture: boolean }) {
  if (entry.kind === "video") {
    const v = entry.video;

    return (
      <li className={`hb-node pb-7`}>
        <p className="text-sm text-[var(--muted)]">{formatClock(v.published_at)} · فيديو</p>
        <a href={v.path} className="mt-1 flex items-start gap-3 hover:text-[var(--spine)]">
          <span className="relative mt-1 shrink-0 overflow-hidden">
            {v.thumbnail_url && (
              // eslint-disable-next-line @next/next/no-img-element -- the video's own picture
              <img src={v.thumbnail_url} alt="" width={128} height={72} loading="lazy" className="h-[72px] w-32 object-cover" />
            )}
            <span className="absolute inset-0 flex items-center justify-center text-white">
              <Icon name="play" />
            </span>
          </span>
          <span className="text-[1.0625rem] leading-[1.6] font-bold">{v.title}</span>
        </a>
      </li>
    );
  }
  const p = entry.post;
  const figure = storyFigure(p);
  const facts = factsLine(p);

  return (
    <li className={`hb-node pb-7`}>
      <p className="text-sm text-[var(--muted)]">
        {since(p.published_at)} · {p.category?.name ?? p.kind.name}
        {p.sponsor && <span className="ms-2 rounded-sm border border-[var(--rule)] px-1.5 text-xs">محتوى ممول</span>}
      </p>
      <div className="mt-1 flex items-start justify-between gap-4">
        <div className="min-w-0">
          {figure && (
            <p className="text-[var(--label)]">
              <span className="text-3xl font-bold">{figure.value}</span> <span>{figure.label}</span>
            </p>
          )}
          <h3 className="text-[1.0625rem] leading-[1.6] font-bold sm:text-lg">
            <a href={p.path} className="hover:text-[var(--spine)]">
              {p.title}
            </a>
          </h3>
          {facts && <p className="mt-1 text-sm">{facts}</p>}
        </div>
        {withPicture && p.cover && (
          <a href={p.path} tabIndex={-1} aria-hidden="true" className="w-28 shrink-0 sm:w-40">
            <Pic media={p.cover} sizes="160px" className="aspect-[4/3] w-full object-cover" />
          </a>
        )}
      </div>
    </li>
  );
}

async function KashidaHome() {
  const home = await loadHome();
  const now = home.now;
  const lead = postsOf(home, "hero")[0];
  const { entries, history } = timeline(home);
  const groups = group(entries.filter((e) => !(e.kind === "post" && e.post.id === lead?.id)), now);
  const between = home.ads.find((a) => a.code === "between_sections");
  const city = home.cities.find((c) => c.is_default) ?? home.cities[0];
  const mostRead = postsOf(home, "most_read");

  return (
    <div className={`hb-page ${alexandria.className} min-h-dvh`}>
      <Header home={home} />
      <section aria-label="العملات اليوم" className="border-b border-[var(--rule)] lg:hidden">
        <div className="px-4 py-1">
          <Rates home={home} compact />
        </div>
      </section>

      <div className="mx-auto max-w-[78rem] px-4 lg:grid lg:grid-cols-[minmax(0,1fr)_20rem] lg:gap-14 lg:px-8">
        <main className="pt-6 lg:pt-10" style={{ "--gap": "1.5rem" } as React.CSSProperties}>
          <h1 className="sr-only">رقم: آخر الأخبار على خط الزمن</h1>
          <div className={`hb-stream ps-6`}>
            {lead && (
              <article className={`hb-node hb-now pb-10`}>
                <p className="text-sm font-bold text-[var(--label)]">الآن</p>
                <h2 className="mt-1 text-[clamp(1.6rem,1.1rem+2.4vw,2.9rem)] leading-[1.3] font-bold text-balance">
                  <a href={lead.path} className="hover:text-[var(--spine)]">
                    {lead.title}
                  </a>
                </h2>
                {lead.excerpt && <p className="mt-3 max-w-[60ch] text-[1.0625rem] leading-[1.8] text-[var(--muted)]">{lead.excerpt}</p>}
                <p className="mt-2 text-sm text-[var(--muted)]">
                  {lead.category?.name} · {since(lead.published_at, now)}
                </p>
                <a href={lead.path} tabIndex={-1} aria-hidden="true" className="mt-5 block">
                  <Pic media={lead.cover} sizes="(min-width: 1024px) 50vw, 100vw" priority className="aspect-[16/9] w-full object-cover" />
                </a>
              </article>
            )}

            {groups.map((g, gi) => (
              <section key={g.title} aria-labelledby={`g-${gi}`} className="hb-group">
                <h2 id={`g-${gi}`} className={`hb-group-label mb-5 text-xl font-bold`}>
                  {g.title}
                </h2>
                <ul>
                  {g.items.map((e, i) => (
                    <Item key={`${e.kind}-${e.kind === "post" ? e.post.id : e.video.id}`} entry={e} withPicture={i % 3 === 0} />
                  ))}
                </ul>
                {gi === 0 && <Ad slot={between} className="mb-10 border-y border-[var(--rule)] py-6" />}
              </section>
            ))}

            {history.length > 0 && (
              <section aria-labelledby="g-history" className={`hb-group pb-4`}>
                <h2 id="g-history" className={`hb-group-label mb-2 text-xl font-bold`}>
                  من تاريخ نابلس الاقتصادي
                </h2>
                <p className="mb-6 text-sm text-[var(--muted)]">الخط يكمل للخلف: من اليوم إلى أول القرن الماضي.</p>
                <ul>
                  {history.map((p) => (
                    <li key={p.id} className={`hb-node pb-7`}>
                      <p dir="ltr" className="text-end text-2xl font-bold text-[var(--label)] tabular-nums">
                        {historyYear(p) ?? ""}
                      </p>
                      <h3 className="mt-1 text-[1.0625rem] leading-[1.6] font-bold sm:text-lg">
                        <a href={p.path} className="hover:text-[var(--spine)]">
                          {p.title}
                        </a>
                      </h3>
                      {factsLine(p) && <p className="mt-1 text-sm text-[var(--muted)]">{factsLine(p)}</p>}
                    </li>
                  ))}
                </ul>
              </section>
            )}
          </div>
        </main>

        <aside aria-label="اليوم بالأرقام" className="border-t border-[var(--rule)] py-8 lg:border-t-0 lg:py-10">
          <div className="space-y-10 lg:sticky lg:top-6">
            <section aria-labelledby="b-rates" className="hidden lg:block">
              <h2 id="b-rates" className="text-lg font-bold">العملات اليوم</h2>
              <p className="text-xs text-[var(--muted)]">{home.rateNote}</p>
              <div className="mt-2">
                <Rates home={home} />
              </div>
            </section>
            {city?.now && (
              <section aria-labelledby="b-weather">
                <h2 id="b-weather" className="text-lg font-bold">طقس {city.name}</h2>
                <p className="mt-1 flex items-baseline gap-3">
                  <span dir="ltr" className="text-4xl font-bold">{Math.round(city.now.temperature)}°</span>
                  <span>{city.now.condition.text}</span>
                </p>
                <p dir="ltr" className="mt-2 text-end text-xs text-[var(--muted)]">{weatherCredit}</p>
              </section>
            )}
            <section aria-labelledby="b-most">
              <h2 id="b-most" className="text-lg font-bold">الأكثر قراءة</h2>
              <ol className="mt-3 space-y-3">
                {mostRead.map((p, i) => (
                  <li key={p.id} className="flex gap-3">
                    <span aria-hidden="true" className="text-xl leading-none font-bold text-[var(--label)]">{i + 1}</span>
                    <a href={p.path} className="leading-[1.6] font-medium hover:text-[var(--spine)]">{p.title}</a>
                  </li>
                ))}
              </ol>
            </section>
          </div>
        </aside>
      </div>

      <footer className="mt-8 border-t border-[var(--rule)] py-8">
        <div className="mx-auto flex max-w-[78rem] flex-wrap items-center justify-between gap-4 px-4 lg:px-8">
          <Logo className="h-8 w-auto" />
          <p className="text-sm text-[var(--muted)]">تاريخ الاقتصاد ومستقبله · نابلس</p>
        </div>
      </footer>
    </div>
  );
}

export default function HomeB() {
  return (
    <Suspense fallback={<p className="p-8">جاري التحميل…</p>}>
      <KashidaHome />
    </Suspense>
  );
}
