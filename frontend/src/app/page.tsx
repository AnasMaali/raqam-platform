import type { Metadata } from "next";

import { AdSlot } from "@/components/ads/AdSlot";
import { CompactCard, LeadCard } from "@/components/cards/PostCard";
import { ChapterEdge, ChapterRow, type Chapter } from "@/components/chapters/ChapterIndex";
import { chapterAnchor } from "@/components/home/Chapter";
import { CategoryChapter, HistoryChapter, LatestChapter, MostReadChapter, SectionChapter, VideoChapter } from "@/components/home/Blocks";
import { RatesBoard } from "@/components/markets/RatesBoard";
import { NewsletterBlock } from "@/components/newsletter/NewsletterBlock";
import { JsonLd } from "@/components/seo/JsonLd";
import { BreakingBar } from "@/components/site/BreakingBar";
import { Main } from "@/components/site/Main";
import { WeatherWidget } from "@/components/weather/WeatherWidget";
import { getAds, getHome, getRatesBoard, getSite, getWeather } from "@/lib/server/site-data";
import { homeStructuredData } from "@/lib/server/structured-data";
import type { HomeBlock, Post } from "@/lib/types";

const title = "رقم: تاريخ الاقتصاد ومستقبله";
const description = "منصة إعلام اقتصادي فلسطيني من نابلس: أخبار الاقتصاد وأسعار العملات وقصص الشركات وتاريخ الاقتصاد.";

export async function generateMetadata(): Promise<Metadata> {
  const site = await getSite();
  const text = site?.seo.default_description || description;
  const image = site?.seo.default_image;
  const images = image
    ? [{ url: image.url, width: image.width, height: image.height, alt: image.alt }]
    : [{ url: "/og/default.png", width: 1200, height: 630, alt: "شعار رقم" }];

  return {
    title: { absolute: title },
    description: text,
    alternates: { canonical: "/" },
    openGraph: { type: "website", url: "/", siteName: site?.name ?? "رَقَم", locale: "ar_AR", title, description: text, images },
    twitter: { card: "summary_large_image", title, description: text, images: images.map((i) => i.url) },
  };
}

function chapterOf(block: HomeBlock): Chapter | null {
  if (block.type === "category") return { id: chapterAnchor(block.id), name: block.category.name, href: `#${chapterAnchor(block.id)}` };
  if (block.type === "section") return { id: chapterAnchor(block.id), name: block.section.name, href: `#${chapterAnchor(block.id)}` };
  if (block.type === "awareness") return { id: chapterAnchor(block.id), name: block.title, href: `#${chapterAnchor(block.id)}` };

  return null;
}

const hasPosts = (block: HomeBlock): block is Extract<HomeBlock, { posts: Post[] }> => "posts" in block && block.posts.length > 0;

/**
 * The home page, the directory (DESIGN.md, Visual direction). The first screen holds the main story with the small
 * currency board and the weather beside it; then the chapters, in the order the admin set in the panel, each block in
 * its own shape, with the ad between chapters and the newsletter where the admin put them. «الأبواب» comes after the
 * first screen on phones and tablets, and stands on the page's edge from 1280 px.
 */
export default async function Home() {
  const [blocks, board, weather, ads, site] = await Promise.all([getHome(), getRatesBoard(), getWeather(), getAds(), getSite()]);
  const list = blocks ?? [];
  const hero = list.find((b) => b.type === "hero");
  const breaking = list.find((b) => b.type === "breaking");
  const markets = list.some((b) => b.type === "markets");
  const [lead, ...rest] = hero && hasPosts(hero) ? hero.posts : [];
  const chapters = list.map(chapterOf).filter((c): c is Chapter => c !== null && list.some((b) => hasPosts(b) && chapterAnchor(b.id) === c.id));
  const side = markets && (board || weather);
  const shown = new Set([lead, ...rest.slice(0, 3)].filter(Boolean).map((p) => p!.id));

  return (
    <>
      <BreakingBar posts={breaking && hasPosts(breaking) ? breaking.posts : null} />
      <Main>
        <h1 className="sr-only">{title}</h1>
        <div className="mx-auto max-w-page px-4 sm:px-6 lg:px-8">
          {blocks === null && <p className="py-16 text-lead text-muted">تعذّر تحميل الصفحة الرئيسية الآن. حدّث الصفحة بعد قليل.</p>}

          <div className={`grid gap-8 pt-5 pb-8 lg:pt-8 ${side && lead ? "lg:grid-cols-12" : ""}`}>
            {lead && (
              <section aria-label="الخبر الرئيسي" className={side ? "lg:col-span-8" : ""}>
                <LeadCard post={lead} stacked={!!side} />
                {rest.length > 0 && (
                  <ul className="mt-6 grid gap-x-6 border-t border-line sm:grid-cols-2 lg:grid-cols-3">
                    {rest.slice(0, 3).map((post) => (
                      <li key={post.id} className="border-line not-first:border-t sm:not-first:border-t-0">
                        <CompactCard post={post} />
                      </li>
                    ))}
                  </ul>
                )}
              </section>
            )}
            {side && (
              <aside aria-label="العملات والطقس" className={`grid content-start gap-8 sm:grid-cols-2 ${lead ? "lg:col-span-4 lg:grid-cols-1" : ""}`}>
                <RatesBoard data={board} />
                <WeatherWidget data={weather} others={4} />
              </aside>
            )}
          </div>

          <ChapterRow chapters={chapters} />

          <div className="xl:grid xl:grid-cols-[minmax(0,1fr)_12rem] xl:gap-10">
            <div className="min-w-0">
              {list.map((block) => (
                <HomeBlockView key={block.id} block={block} ads={ads} shown={shown} />
              ))}
            </div>
            <ChapterEdge chapters={chapters} />
          </div>
        </div>
      </Main>
      <JsonLd data={homeStructuredData(site)} />
    </>
  );
}

function HomeBlockView({ block, ads, shown }: { block: HomeBlock; ads: Awaited<ReturnType<typeof getAds>>; shown: Set<number> }) {
  switch (block.type) {
    case "category":
      return <CategoryChapter id={block.id} name={block.category.name} href={`/category/${block.category.slug}`} posts={block.posts} />;
    case "section":
      return block.section.key === "nablus_history" ? (
        <HistoryChapter id={block.id} name={block.section.name} href={`/${block.section.slug}`} posts={block.posts} />
      ) : (
        <SectionChapter id={block.id} name={block.section.name} href={`/${block.section.slug}`} posts={block.posts} />
      );
    case "awareness":
      return <SectionChapter id={block.id} name={block.title} href="/awareness" posts={block.posts} />;
    case "latest":
      return <LatestChapter id={block.id} name={block.title} posts={block.posts.filter((p) => !shown.has(p.id))} />;
    case "most_read":
      return <MostReadChapter id={block.id} name={block.title} posts={block.posts} />;
    case "videos":
      return <VideoChapter id={block.id} name={block.title} href="/video" videos={block.videos} />;
    case "playlist":
      return <VideoChapter id={block.id} name={block.title} href={`/video/playlists/${block.playlist.slug}`} videos={block.videos} />;
    case "ad": {
      const slot = ads?.slots.find((s) => s.code === block.placement);

      return slot ? <AdSlot slot={slot} label={ads?.label} className="border-t border-line py-8" /> : null;
    }
    case "newsletter":
      return (
        <div className="border-t border-line py-8">
          <NewsletterBlock title={block.title} placement="home" path="/" />
        </div>
      );
    default:
      // hero, breaking and markets are drawn in the first screen.
      return null;
  }
}
