import Link from "next/link";

import { formatClock } from "@/lib/format";
import type { Post } from "@/lib/types";

/**
 * Breaking news, when there is any (rare). A quiet tinted bar with a slanted «عاجل» label: no flashing, no motion, no
 * alarm. It shows the newest breaking item; the others are a list away.
 */
export function BreakingBar({ posts }: { posts: Post[] | null }) {
  const post = posts?.[0];
  if (!post) return null;

  return (
    <section aria-labelledby="breaking-title" className="bg-breaking-surface">
      <div className="mx-auto flex max-w-page items-center gap-3 px-4 sm:px-6 lg:px-8">
        <h2 id="breaking-title" className="slant-end shrink-0 bg-breaking py-1 ps-3 pe-6 text-small font-bold text-ground [--slant:1rem]">
          عاجل
        </h2>
        <p className="min-w-0 flex-1 py-1.5">
          <Link href={post.path} className="inline-flex min-h-11 items-center font-medium hover:underline">
            {post.title}
          </Link>
        </p>
        <time dateTime={post.published_at} className="hidden shrink-0 text-small text-muted sm:block">
          {formatClock(post.published_at)}
        </time>
      </div>
    </section>
  );
}
