import Link from "next/link";

import type { Video } from "@/lib/types";

/** A video's length as the player shows it: 58:32, 1:02:05. */
export function duration(seconds: number) {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = String(seconds % 60).padStart(2, "0");

  return h > 0 ? `${h}:${String(m).padStart(2, "0")}:${s}` : `${m}:${s}`;
}

/** A video: its picture with its length, and its title as the card's one link. */
export function VideoCard({ video, level = 3 }: { video: Video; level?: 2 | 3 }) {
  const Heading = `h${level}` as const;

  return (
    <article className="group relative has-[a:focus-visible]:outline-2 has-[a:focus-visible]:outline-offset-4 has-[a:focus-visible]:outline-brand">
      <div className="relative aspect-video bg-surface ring-1 ring-ink/10 ring-inset">
        {video.thumbnail_url && (
          // eslint-disable-next-line @next/next/no-img-element -- the video's own picture, already sized by its host
          <img src={video.thumbnail_url} alt="" width={480} height={270} loading="lazy" decoding="async" className="size-full object-cover" />
        )}
        {video.duration_seconds ? (
          <p className="figures absolute bottom-2 start-2 rounded-xs bg-ink px-1.5 text-tiny font-medium text-ground">
            <span className="sr-only">المدة </span>
            <span dir="ltr">{duration(video.duration_seconds)}</span>
          </p>
        ) : null}
      </div>
      <Heading className="mt-2 text-body font-medium">
        <Link href={video.path} className="after:absolute after:inset-0 focus-visible:outline-none group-hover:text-brand">
          {video.title}
        </Link>
      </Heading>
    </article>
  );
}
