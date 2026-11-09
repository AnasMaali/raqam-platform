import Link from "next/link";

import { factsLine, storyFigure } from "@/lib/facts";
import { formatPublished } from "@/lib/format";
import type { Post } from "@/lib/types";

import { Pic } from "../ui/Pic";

type Level = 2 | 3 | 4;

/** The line under a headline: where it belongs and when it was published, and any label it must carry. */
export function PostMeta({ post, className = "" }: { post: Post; className?: string }) {
  const facts = factsLine(post);

  return (
    <p className={`flex flex-wrap items-center gap-x-2 text-small text-muted ${className}`}>
      {post.is_breaking && <span className="font-bold text-breaking">عاجل</span>}
      {post.sponsor && (
        <span className="rounded-xs border border-line-strong px-1.5 text-tiny text-ink">
          محتوى ممول{post.sponsor.name ? `: ${post.sponsor.name}` : ""}
        </span>
      )}
      <span>{facts ?? post.category?.name ?? post.kind.name}</span>
      <span aria-hidden="true" className="text-line-strong">
        ·
      </span>
      <time dateTime={post.published_at}>{formatPublished(post.published_at)}</time>
    </p>
  );
}

/**
 * The headline is the card's one link, stretched over the whole card: one stop for the keyboard and for screen
 * readers, and the whole card can be pressed. The focus ring is drawn around the card.
 */
function Headline({ post, level, className }: { post: Post; level: Level; className: string }) {
  const Tag = `h${level}` as const;

  return (
    <Tag className={className}>
      <Link href={post.path} className="after:absolute after:inset-0 focus-visible:outline-none group-hover:text-brand">
        {post.title}
      </Link>
    </Tag>
  );
}

const frame = "group relative has-[a:focus-visible]:outline-2 has-[a:focus-visible]:outline-offset-4 has-[a:focus-visible]:outline-brand";

/** The main story: a large headline and its summary beside a wide picture (stacked on phones, picture first). */
export function LeadCard({ post, level = 2 }: { post: Post; level?: Level }) {
  return (
    <article className={`${frame} grid gap-4 lg:grid-cols-12 lg:gap-8`}>
      <div className="lg:col-span-6 lg:self-center 2xl:col-span-5">
        <Headline post={post} level={level} className="text-display font-bold" />
        {post.excerpt && <p className="mt-3 text-lead text-muted">{post.excerpt}</p>}
        <PostMeta post={post} className="mt-3" />
      </div>
      <Pic media={post.cover} sizes="(min-width: 64rem) 55vw, 100vw" ratio="16/9" priority className="-order-1 lg:order-none lg:col-span-6 2xl:col-span-7" />
    </article>
  );
}

/**
 * An item with its picture: the first item of a chapter. A success story shows its figure ("120 موظفاً"), so the
 * number comes with its story. Without a picture it stays a complete card, with no empty box.
 */
export function FeatureCard({ post, level = 3, sizes = "(min-width: 64rem) 30vw, (min-width: 40rem) 50vw, 100vw" }: { post: Post; level?: Level; sizes?: string }) {
  const figure = storyFigure(post);

  return (
    <article className={`${frame} flex flex-col`}>
      <Pic media={post.cover} sizes={sizes} className="mb-3" />
      {figure && (
        <p className="mb-1 text-brand">
          <span className="text-figure font-bold">{figure.value}</span> <span className="text-lead">{figure.label}</span>
        </p>
      )}
      <Headline post={post} level={level} className="text-subheading font-bold" />
      {post.excerpt && !post.cover && <p className="mt-2 text-muted">{post.excerpt}</p>}
      <PostMeta post={post} className="mt-2" />
    </article>
  );
}

/** A headline and its line, no picture: the rest of a chapter, latest news. */
export function CompactCard({ post, level = 3 }: { post: Post; level?: Level }) {
  return (
    <article className={`${frame} py-3`}>
      <Headline post={post} level={level} className="text-body font-medium" />
      <PostMeta post={post} className="mt-1" />
    </article>
  );
}

/** A ranked item (most read): its place, then its headline. */
export function RankedCard({ post, rank, level = 3 }: { post: Post; rank: number; level?: Level }) {
  return (
    <article className={`${frame} flex gap-3 py-3`}>
      <p aria-hidden="true" className="figures w-8 shrink-0 text-heading leading-none font-bold text-brand">
        {rank}
      </p>
      <div>
        <span className="sr-only">المرتبة {rank}: </span>
        <Headline post={post} level={level} className="text-body font-medium" />
        <PostMeta post={post} className="mt-1" />
      </div>
    </article>
  );
}
