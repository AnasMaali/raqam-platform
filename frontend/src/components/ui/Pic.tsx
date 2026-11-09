import type { Media } from "@/lib/types";

const order = ["sm", "md", "lg", "xl"];

/**
 * A content picture in the sizes the platform already made, WebP first. It keeps its frame and its size: the box is
 * reserved by its aspect ratio before the file arrives, and a hairline edge keeps a light picture from fading into a
 * white page.
 */
export function Pic({
  media,
  sizes,
  ratio = "3/2",
  priority = false,
  className = "",
}: {
  media: Media | null;
  sizes: string;
  ratio?: "16/9" | "3/2" | "1/1";
  priority?: boolean;
  className?: string;
}) {
  if (!media) return null;
  const webp = order.flatMap((k) => (media.variants[k]?.webp ? [`${media.variants[k].webp.url} ${media.variants[k].webp.width}w`] : [])).join(", ");
  const jpeg = order.flatMap((k) => (media.variants[k]?.jpeg ? [`${media.variants[k].jpeg.url} ${media.variants[k].jpeg.width}w`] : [])).join(", ");
  const fallback = media.variants.md?.jpeg ?? media.variants.sm?.jpeg;
  const aspect = { "16/9": "aspect-[16/9]", "3/2": "aspect-[3/2]", "1/1": "aspect-square" }[ratio];

  return (
    <picture className={`relative block overflow-hidden bg-surface ${aspect} ${className}`}>
      {webp && <source type="image/webp" srcSet={webp} sizes={sizes} />}
      <img
        src={fallback?.url ?? media.url}
        srcSet={jpeg || undefined}
        sizes={sizes}
        alt={media.alt}
        width={media.width}
        height={media.height}
        loading={priority ? "eager" : "lazy"}
        fetchPriority={priority ? "high" : undefined}
        decoding="async"
        className="size-full object-cover"
      />
      <span aria-hidden="true" className="pointer-events-none absolute inset-0 ring-1 ring-ink/10 ring-inset" />
    </picture>
  );
}
