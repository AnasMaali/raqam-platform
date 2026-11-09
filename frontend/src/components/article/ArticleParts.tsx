import Link from "next/link";
import type { ReactNode } from "react";

import { formatDateTime, formatDay } from "@/lib/format";
import type { Guest, Organization, Picture, PostDetail, PostDetails } from "@/lib/types";

import { Icon } from "../ui/Icon";
import { Pic } from "../ui/Pic";

import { LiteVideo } from "./LiteVideo";

/* The parts of the shared article template, used by every kind. */

export type Crumb = { name: string; path: string };

/** Where the article sits: the home page, its category or section, then the article itself (not a link). */
export function Breadcrumbs({ crumbs }: { crumbs: Crumb[] }) {
  return (
    <nav aria-label="مسار الصفحة" className="text-small text-muted">
      <ol className="flex flex-wrap items-center gap-x-2">
        {crumbs.map((c, i) => {
          const last = i === crumbs.length - 1;

          return (
            <li key={c.path} className={`flex min-w-0 items-center gap-x-2 ${last ? "sr-only" : ""}`}>
              {last ? (
                <span aria-current="page">{c.name}</span>
              ) : (
                <>
                  <Link href={c.path} className="inline-flex min-h-11 min-w-11 items-center hover:text-brand">
                    {c.name}
                  </Link>
                  <Icon name="end" size={14} className="text-line-strong" />
                </>
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}

/** The kind's own label above the headline: «رأي», «تقرير», «الموسم 2 · الحلقة 10» … A plain news item has none. */
export function kindLabel(post: PostDetail): string | null {
  const d = post.details;
  if (d?.kind === "episode") return [d.season ? `الموسم ${d.season.number}` : null, d.episode_number ? `الحلقة ${d.episode_number}` : null].filter(Boolean).join(" · ") || post.kind.name;
  if (d?.kind === "awareness") return d.type?.name ?? post.kind.name;
  if (post.kind.code === "news") return null;

  return post.kind.name;
}

const tenMinutes = 10 * 60 * 1000;

/** Who wrote it (with links to their pages, or «فريق رَقَم») and when: published, and updated when it was. */
export function Byline({ post }: { post: PostDetail }) {
  const updated = Date.parse(post.updated_at) - Date.parse(post.published_at) > tenMinutes;

  return (
    <div className="flex flex-col gap-1 text-small">
      <p data-inline-links>
        {post.kind.code === "opinion" ? "بقلم " : ""}
        {post.authors.length === 0 ? (
          <span className="font-bold">فريق رَقَم</span>
        ) : (
          post.authors.map((a, i) => (
            <span key={a.id}>
              {i > 0 && (i === post.authors.length - 1 ? " و" : "، ")}
              <Link href={`/author/${a.slug}`} rel="author" className="font-bold hover:text-brand">
                {a.name}
              </Link>
            </span>
          ))
        )}
      </p>
      <p className="text-muted">
        نُشر <time dateTime={post.published_at}>{formatDateTime(post.published_at)}</time>
        {updated && (
          <>
            <span aria-hidden="true" className="mx-2 text-line-strong">
              ·
            </span>
            حُدّث <time dateTime={post.updated_at}>{formatDateTime(post.updated_at)}</time>
          </>
        )}
      </p>
    </div>
  );
}

/** Sponsored content says so before anything else, with the sponsor's name (and its link marked as paid). */
export function SponsorLabel({ sponsor }: { sponsor: NonNullable<PostDetail["sponsor"]> }) {
  return (
    <p data-inline-links className="inline-flex flex-wrap items-center gap-x-1 rounded-xs border border-line-strong px-2 py-0.5 text-small">
      <span className="font-bold">محتوى ممول</span>
      <span>
        :{" "}
        {sponsor.website ? (
          <a href={sponsor.website} rel="sponsored noopener" target="_blank" className="underline underline-offset-4 hover:text-brand">
            {sponsor.name}
          </a>
        ) : (
          sponsor.name
        )}
      </span>
    </p>
  );
}

/** A correction is stated openly, with its date, at the top of the article. */
export function CorrectionNote({ correction }: { correction: NonNullable<PostDetail["correction"]> }) {
  return (
    <aside aria-labelledby="correction-title" className="border-s-4 border-brand bg-tint px-4 py-3">
      <h2 id="correction-title" className="text-small font-bold text-brand">
        تصحيح
      </h2>
      <p className="mt-1">{correction.note}</p>
      <p className="mt-1 text-small text-muted">
        <time dateTime={correction.corrected_at}>{formatDateTime(correction.corrected_at)}</time>
      </p>
    </aside>
  );
}

/** A picture with its caption and credit. */
export function Figure({ media, sizes, priority = false, ratio = "16/9" }: { media: Picture; sizes: string; priority?: boolean; ratio?: "16/9" | "3/2" | "1/1" }) {
  const credit = media.credit ? `© ${media.credit}` : null;

  return (
    <figure>
      <Pic media={media} sizes={sizes} priority={priority} ratio={ratio} />
      {(media.caption || credit) && (
        <figcaption className="mt-2 text-small text-muted">
          {media.caption}
          {media.caption && credit ? " · " : ""}
          {credit && <span className="text-tiny">{credit}</span>}
        </figcaption>
      )}
    </figure>
  );
}

export function Video({ video }: { video: NonNullable<PostDetail["video"]> }) {
  return (
    <LiteVideo
      title={video.title}
      embedUrl={video.youtube?.embed_url ?? null}
      uploadUrl={video.upload?.url ?? null}
      uploadType={video.upload?.mime_type ?? null}
      thumbnail={video.thumbnail_url}
      chapters={video.chapters}
    />
  );
}

/** An infographic is the article: shown as large as the column, opened at full size, or downloaded (9.11). */
export function Infographic({ image }: { image: Picture }) {
  return (
    <figure>
      {/* eslint-disable-next-line @next/next/no-img-element -- the full file, at its own size: it is the article */}
      <img src={image.url} alt={image.alt} width={image.width} height={image.height} fetchPriority="high" className="h-auto w-full bg-surface" />
      <figcaption className="mt-3 flex flex-wrap items-center gap-x-6 text-small">
        <a href={image.url} target="_blank" rel="noopener" className="inline-flex min-h-11 items-center gap-2 font-medium text-brand hover:underline">
          <Icon name="expand" size={18} />
          افتح بالحجم الكامل
        </a>
        <a href={image.url} download className="inline-flex min-h-11 items-center gap-2 font-medium text-brand hover:underline">
          <Icon name="download" size={18} />
          نزّل الصورة
        </a>
        {image.credit && <span className="text-tiny text-muted">© {image.credit}</span>}
      </figcaption>
    </figure>
  );
}

/** The post's own gallery; a product's is large (one picture per row, 9.6). */
export function Gallery({ pictures, large }: { pictures: Picture[]; large: boolean }) {
  if (pictures.length === 0) return null;

  return (
    <section aria-labelledby="gallery-title">
      <h2 id="gallery-title" className="text-heading font-bold">
        الصور
      </h2>
      <ul className={`mt-4 grid gap-6 ${large ? "" : "sm:grid-cols-2"}`}>
        {pictures.map((p) => (
          <li key={p.id}>
            <Figure media={p} ratio="3/2" sizes={large ? "(min-width: 64rem) 42rem, 100vw" : "(min-width: 64rem) 21rem, (min-width: 40rem) 50vw, 100vw"} />
          </li>
        ))}
      </ul>
    </section>
  );
}

/** Hosts, guests and panelists, each with a picture, a role, and a link to everything they appeared in. */
export function People({ guests, title }: { guests: Guest[]; title: string }) {
  if (guests.length === 0) return null;

  return (
    <section aria-labelledby="people-title">
      <h2 id="people-title" className="text-heading font-bold">
        {title}
      </h2>
      <ul className="mt-4 grid gap-4 sm:grid-cols-2">
        {guests.map(({ role, guest }) => (
          <li key={`${role.code}-${guest.slug}`} className="group relative flex items-center gap-3 has-[a:focus-visible]:outline-2 has-[a:focus-visible]:outline-offset-4 has-[a:focus-visible]:outline-brand">
            <div className="w-16 shrink-0">{guest.photo ? <Pic media={guest.photo} sizes="4rem" ratio="1/1" className="rounded-full" /> : <div className="aspect-square rounded-full bg-surface" />}</div>
            <div className="min-w-0">
              <p className="text-small text-muted">{role.name}</p>
              <p className="font-bold">
                <Link href={`/guest/${guest.slug}`} className="after:absolute after:inset-0 focus-visible:outline-none group-hover:text-brand">
                  {guest.name}
                </Link>
              </p>
              {guest.job_title && <p className="text-small text-muted">{guest.job_title}</p>}
            </div>
          </li>
        ))}
      </ul>
    </section>
  );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-wrap gap-x-3 border-b border-line py-2 last:border-b-0">
      <dt className="w-28 shrink-0 text-muted">{label}</dt>
      <dd className="min-w-0 font-medium">{children}</dd>
    </div>
  );
}

const external = "inline-flex min-h-11 items-center underline underline-offset-4 hover:text-brand";

function OrganizationFacts({ org, label }: { org: Organization; label: string }) {
  return (
    <>
      <Fact label={label}>
        {org.website ? (
          <a href={org.website} target="_blank" rel="noopener" className={external}>
            {org.name}
          </a>
        ) : (
          org.name
        )}
      </Fact>
      {org.sector && <Fact label="القطاع">{org.sector.name}</Fact>}
      {org.city && <Fact label="المدينة">{org.city.name}</Fact>}
      {org.founded_year && (
        <Fact label="سنة التأسيس">
          <span className="figures">{org.founded_year}</span>
        </Fact>
      )}
    </>
  );
}

/**
 * The kind's own facts, in a tinted box before the text: a story's company and figures, a product's maker and how to
 * reach it, an initiative's organiser and state, a history item's era and years, a round table's date.
 */
export function KindFacts({ details }: { details: PostDetails | null }) {
  if (!details) return null;
  let facts: ReactNode = null;
  let figures: { value: string; label: string }[] = [];

  switch (details.kind) {
    case "story":
      figures = details.figures;
      facts = (
        <>
          {details.person_name && <Fact label="صاحب القصة">{details.person_name}</Fact>}
          {details.organization && <OrganizationFacts org={details.organization} label="المنشأة" />}
        </>
      );
      break;
    case "product":
      facts = (
        <>
          {details.product_name && <Fact label="المنتج">{details.product_name}</Fact>}
          {details.organization && <OrganizationFacts org={details.organization} label="الصانع" />}
          {(details.contact_url || details.contact_phone) && (
            <Fact label="للتواصل">
              <span className="flex flex-wrap gap-x-4">
                {details.contact_url && (
                  <a href={details.contact_url} target="_blank" rel="noopener" className={external}>
                    صفحة المنتج
                  </a>
                )}
                {details.contact_phone && (
                  <a href={`tel:${details.contact_phone}`} dir="ltr" className={`${external} figures`}>
                    {details.contact_phone}
                  </a>
                )}
              </span>
            </Fact>
          )}
        </>
      );
      break;
    case "initiative":
      facts = (
        <>
          {details.initiative_name && <Fact label="المبادرة">{details.initiative_name}</Fact>}
          {details.organizer && <OrganizationFacts org={details.organizer} label="الجهة المنظِّمة" />}
          <Fact label="الحالة">{details.status === "ongoing" ? "جارية" : "منتهية"}</Fact>
          {details.url && (
            <Fact label="الرابط">
              <a href={details.url} target="_blank" rel="noopener" className={external}>
                صفحة المبادرة
              </a>
            </Fact>
          )}
        </>
      );
      break;
    case "history": {
      const years = details.year_from ? (details.year_to ? `${details.year_from}–${details.year_to}` : String(details.year_from)) : null;
      facts = (
        <>
          {details.era && <Fact label="الحقبة">{details.era.name}</Fact>}
          {(years || details.period_label) && (
            <Fact label="الفترة">
              <span className="figures">{years ?? details.period_label}</span>
            </Fact>
          )}
        </>
      );
      break;
    }
    case "roundtable":
      if (details.held_on) {
        facts = (
          <Fact label="عُقدت">
            <time dateTime={details.held_on}>{formatDay(details.held_on)}</time>
          </Fact>
        );
      }
      break;
    default:
      return null;
  }

  return (
    <aside aria-label="بطاقة المادة" className="bg-surface px-4 py-3">
      {figures.length > 0 && (
        <ul className="mb-3 grid grid-cols-2 gap-4 border-b border-line pb-3 sm:grid-cols-3">
          {figures.map((f) => (
            <li key={f.label}>
              <p className="text-figure font-bold text-brand">
                <span dir="ltr">{f.value}</span>
              </p>
              <p className="text-small">{f.label}</p>
            </li>
          ))}
        </ul>
      )}
      {facts && <dl className="text-small">{facts}</dl>}
    </aside>
  );
}

/** An awareness card: the character, still (its animation comes with the awareness package), speaking the information. */
export function CharacterSays({ details, text }: { details: Extract<PostDetails, { kind: "awareness" }>; text: string }) {
  const image = details.pose?.image;

  return (
    <figure className="flex items-start gap-4">
      {image && (
        <div className="w-24 shrink-0 text-center sm:w-32">
          <Pic media={image} sizes="8rem" ratio="1/1" priority className="rounded-full" />
          {details.character && <figcaption className="mt-1 text-small text-muted">{details.character.name}</figcaption>}
        </div>
      )}
      <blockquote className="flex-1 rounded-md border-2 border-brand px-4 py-3 text-lead">{text}</blockquote>
    </figure>
  );
}

export function Tags({ tags }: { tags: PostDetail["tags"] }) {
  if (tags.length === 0) return null;

  return (
    <nav aria-labelledby="tags-title" className="flex flex-wrap items-center gap-x-3 gap-y-2">
      <h2 id="tags-title" className="text-small font-bold text-muted">
        الوسوم
      </h2>
      <ul className="flex flex-wrap gap-2">
        {tags.map((t) => (
          <li key={t.slug}>
            <Link href={`/tag/${t.slug}`} className="inline-flex min-h-11 items-center rounded-sm border border-line px-3 text-small hover:border-brand hover:text-brand">
              {t.name}
            </Link>
          </li>
        ))}
      </ul>
    </nav>
  );
}
