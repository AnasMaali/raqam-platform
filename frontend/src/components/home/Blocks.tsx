import Link from "next/link";

import { factsLine, historyYear } from "@/lib/facts";
import type { Post, Video } from "@/lib/types";

import { VideoCard } from "../video/VideoCard";

import { CompactCard, FeatureCard, RankedCard } from "../cards/PostCard";

import { Chapter, phoneOnly } from "./Chapter";
import styles from "./HistoryLine.module.css";

/*
 * Each kind of home-page block keeps its own shape (DESIGN.md, Visual direction):
 *   a news category   one item with its picture, the rest as headlines beside it;
 *   a special section its items side by side, each with its picture (a story's figure, an episode's number);
 *   history           the kashida's time line, ordered by year;
 *   latest news       headlines in columns, with their time;
 *   videos            a row of pictures with their length, swiped by hand;
 *   most read         a ranked list.
 */

type ChapterProps = { id: number; name: string; href?: string; posts: Post[] };

export function CategoryChapter({ id, name, href, posts }: ChapterProps) {
  const [first, ...rest] = posts;
  if (!first) return null;

  return (
    <Chapter id={id} name={name} href={href}>
      <div className="grid gap-6 lg:grid-cols-5 lg:gap-8">
        <div className="lg:col-span-3">
          <FeatureCard post={first} sizes="(min-width: 84rem) 40rem, (min-width: 64rem) 40vw, 100vw" />
        </div>
        {rest.length > 0 && (
          <ul className="border-t border-line lg:col-span-2 lg:border-t-0">
            {rest.slice(0, 5).map((post, i) => (
              <li key={post.id} className={`border-line not-first:border-t lg:first:[&>article]:pt-0 ${phoneOnly(i + 1)}`}>
                <CompactCard post={post} />
              </li>
            ))}
          </ul>
        )}
      </div>
    </Chapter>
  );
}

export function SectionChapter({ id, name, href, posts }: ChapterProps) {
  if (posts.length === 0) return null;

  return (
    <Chapter id={id} name={name} href={href}>
      <ul className="relative flex min-w-0 gap-4 pb-2 max-sm:scroll-strip sm:grid sm:grid-cols-2 sm:gap-x-6 sm:gap-y-8 sm:pb-0 lg:grid-cols-3">
        {posts.slice(0, 6).map((post, i) => (
          <li key={post.id} className={`w-64 shrink-0 sm:w-auto ${phoneOnly(i)}`}>
            <FeatureCard post={post} sizes="(min-width: 84rem) 18rem, (min-width: 64rem) 22vw, (min-width: 40rem) 50vw, 100vw" />
          </li>
        ))}
      </ul>
    </Chapter>
  );
}

/**
 * The history chapter on the kashida's time line: the «ق»'s hollow circle is each item's node, its year beside it,
 * from the oldest down. Items without a year come last.
 */
export function HistoryChapter({ id, name, href, posts }: ChapterProps) {
  if (posts.length === 0) return null;
  const ordered = [...posts].sort((a, b) => (historyYear(a) ?? Infinity) - (historyYear(b) ?? Infinity));

  return (
    <Chapter id={id} name={name} href={href}>
      <ol className={`${styles.line} max-w-text`}>
        {ordered.slice(0, 6).map((post, i) => {
          const year = historyYear(post);
          const facts = factsLine(post);

          return (
            <li key={post.id} className={`${styles.node} ${phoneOnly(i)} pb-6 last:pb-0`}>
              <article className="group relative has-[a:focus-visible]:outline-2 has-[a:focus-visible]:outline-offset-4 has-[a:focus-visible]:outline-brand">
                {year !== null && <p className="figures text-subheading leading-none font-bold text-brand">{year}</p>}
                <h3 className="mt-1 text-subheading font-bold">
                  <Link href={post.path} className="after:absolute after:inset-0 focus-visible:outline-none group-hover:text-brand">
                    {post.title}
                  </Link>
                </h3>
                {facts && <p className="mt-1 text-small text-muted">{facts}</p>}
              </article>
            </li>
          );
        })}
      </ol>
    </Chapter>
  );
}

/** A rule between rows only, for a list in one, two (640 px) or three (1024 px) columns: the first row has none. */
const betweenRows = "border-t border-line first:border-t-0 sm:nth-2:border-t-0 lg:nth-3:border-t-0";

export function LatestChapter({ id, name, posts }: ChapterProps) {
  if (posts.length === 0) return null;

  return (
    <Chapter id={id} name={name}>
      <ul className="grid gap-x-8 sm:grid-cols-2 lg:grid-cols-3">
        {posts.slice(0, 9).map((post, i) => (
          <li key={post.id} className={`${betweenRows} ${phoneOnly(i)}`}>
            <CompactCard post={post} />
          </li>
        ))}
      </ul>
    </Chapter>
  );
}

export function MostReadChapter({ id, name, posts }: ChapterProps) {
  if (posts.length === 0) return null;

  return (
    <Chapter id={id} name={name}>
      <ol className="grid gap-x-8 sm:grid-cols-2 lg:grid-cols-3">
        {posts.slice(0, 6).map((post, i) => (
          <li key={post.id} className={`${betweenRows} ${phoneOnly(i)}`}>
            <RankedCard post={post} rank={i + 1} />
          </li>
        ))}
      </ol>
    </Chapter>
  );
}

export function VideoChapter({ id, name, href, videos }: { id: number; name: string; href: string; videos: Video[] }) {
  if (videos.length === 0) return null;

  return (
    <Chapter id={id} name={name} href={href} linkText="كل الفيديو">
      <ul className="scroll-strip relative flex min-w-0 gap-4 pb-2">
        {videos.slice(0, 8).map((video) => (
          <li key={video.id} className="w-64 shrink-0 sm:w-72">
            <VideoCard video={video} />
          </li>
        ))}
      </ul>
    </Chapter>
  );
}
