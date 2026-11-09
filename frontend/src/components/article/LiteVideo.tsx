"use client";

import { useRef, useState } from "react";

import type { VideoChapter } from "@/lib/types";

import { Icon } from "../ui/Icon";

/**
 * A light video player: the picture and a play button, and YouTube (youtube-nocookie) only once it is pressed, so a
 * page with a video loads nothing from YouTube by itself. Chapters start the video at their time, or move a playing
 * video there. An uploaded video uses the browser's own player and loads nothing before play.
 */
export function LiteVideo({
  title,
  embedUrl,
  uploadUrl,
  uploadType,
  thumbnail,
  chapters,
}: {
  title: string;
  embedUrl: string | null;
  uploadUrl: string | null;
  uploadType: string | null;
  thumbnail: string | null;
  chapters: VideoChapter[];
}) {
  const [start, setStart] = useState<number | null>(null);
  const frame = useRef<HTMLIFrameElement>(null);
  const native = useRef<HTMLVideoElement>(null);

  function play(at: number) {
    if (native.current) {
      native.current.currentTime = at;
      void native.current.play();

      return;
    }
    if (start !== null && frame.current?.contentWindow) {
      // The playing video moves to the chapter (YouTube's player commands, enabled with enablejsapi=1).
      frame.current.contentWindow.postMessage(JSON.stringify({ event: "command", func: "seekTo", args: [at, true] }), "*");
      frame.current.contentWindow.postMessage(JSON.stringify({ event: "command", func: "playVideo", args: [] }), "*");

      return;
    }
    setStart(at);
  }

  return (
    <div>
      <div className="relative aspect-video overflow-hidden bg-surface">
        {uploadUrl ? (
          <video ref={native} controls preload="none" poster={thumbnail ?? undefined} className="size-full" aria-label={title}>
            <source src={uploadUrl} type={uploadType ?? undefined} />
          </video>
        ) : start !== null && embedUrl ? (
          <iframe
            ref={frame}
            src={`${embedUrl}?autoplay=1&rel=0&enablejsapi=1&start=${start}`}
            title={title}
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
            allowFullScreen
            className="size-full"
          />
        ) : (
          <button type="button" onClick={() => play(0)} className="group absolute inset-0 block size-full">
            {thumbnail && (
              // eslint-disable-next-line @next/next/no-img-element -- the video's own picture, sized by its host
              <img src={thumbnail} alt="" width={1280} height={720} className="size-full object-cover" />
            )}
            <span className="absolute inset-0 grid place-items-center">
              <span className="inline-flex size-16 items-center justify-center rounded-full bg-brand text-on-brand group-hover:scale-105 motion-safe:transition-transform">
                <Icon name="play" size={28} />
              </span>
            </span>
            <span className="sr-only">شغّل الفيديو: {title}</span>
          </button>
        )}
      </div>
      {chapters.length > 0 && (
        <nav aria-label="فصول الفيديو" className="mt-3">
          <h2 className="text-small font-bold text-muted">الفصول</h2>
          <ol className="mt-1 border-t border-line">
            {chapters.map((c) => (
              <li key={c.start} className="border-b border-line">
                <button type="button" onClick={() => play(c.start)} className="flex min-h-11 w-full items-center gap-3 py-1 text-start hover:text-brand">
                  <span dir="ltr" className="figures w-14 shrink-0 text-small font-medium text-brand">
                    {c.time}
                  </span>
                  <span>{c.title}</span>
                </button>
              </li>
            ))}
          </ol>
        </nav>
      )}
    </div>
  );
}
