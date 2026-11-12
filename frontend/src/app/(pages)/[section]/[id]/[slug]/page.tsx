import type { Metadata } from "next";
import Link from "next/link";
import { notFound, permanentRedirect } from "next/navigation";
import { connection } from "next/server";

import { AdSlot } from "@/components/ads/AdSlot";
import {
  Breadcrumbs,
  Byline,
  CharacterSays,
  CorrectionNote,
  Figure,
  Gallery,
  Infographic,
  KindFacts,
  People,
  SponsorLabel,
  Tags,
  Video,
  kindLabel,
  type Crumb,
} from "@/components/article/ArticleParts";
import styles from "@/components/article/ArticleBody.module.css";
import { CompactCard } from "@/components/cards/PostCard";
import { NewsletterBlock } from "@/components/newsletter/NewsletterBlock";
import { NavPlace } from "@/components/site/CurrentPage";
import { JsonLd } from "@/components/seo/JsonLd";
import { Icon } from "@/components/ui/Icon";
import { markSponsoredLinks, splitAfterParagraph } from "@/lib/body";
import { getPost, newestPostPaths, postPrefixes, type PostAnswer } from "@/lib/server/posts";
import { getAds, getPostsOf, getSections, getSite } from "@/lib/server/site-data";
import { articleStructuredData } from "@/lib/server/structured-data";
import type { PostDetail, Section } from "@/lib/types";

/*
 * The article: one template for every kind (news, report, opinion, infographic, and the items of the seven special
 * sections), with each kind's own parts. Blocking on purpose: an article answers with its real status (404 for an
 * unknown or removed post, a permanent redirect for an old address) before any byte is sent. A streamed shell would
 * already have answered 200.
 */
export const instant = false;

/** A few of the newest posts are built ahead; any other renders on its first visit and is kept. */
export async function generateStaticParams() {
  return newestPostPaths();
}

type Params = PageProps<"/[section]/[id]/[slug]">["params"];

type Loaded = { answer: PostAnswer | { status: "unavailable" }; requested: string };

async function load(params: Params): Promise<Loaded> {
  const { section, id, slug } = await params;
  const decoded = safeDecode(slug);
  if (!(postPrefixes as readonly string[]).includes(section) || !/^[1-9]\d{0,9}$/.test(id) || decoded === null) return { answer: { status: "gone" }, requested: "" };

  try {
    return { answer: await getPost(Number(id), decoded), requested: `/${section}/${id}/${decoded}` };
  } catch (error) {
    // The platform failed (an error, too many requests): nothing is kept, and the page says so.
    console.error(`[site] post ${id} is unavailable: ${(error as Error).message}`);

    return { answer: { status: "unavailable" }, requested: "" };
  }
}

function safeDecode(value: string): string | null {
  try {
    return decodeURIComponent(value);
  } catch {
    return null;
  }
}

/** Home, then the category (news kinds) or the section (section kinds), then the article. */
function crumbsOf(post: PostDetail, sections: Section[] | null): Crumb[] {
  const prefix = post.path.split("/")[1];
  const section = prefix === "news" ? null : sections?.find((s) => s.slug === prefix);
  const parent = section ? { name: section.name, path: `/${section.slug}` } : post.category ? { name: post.category.name, path: `/category/${post.category.slug}` } : null;

  return [{ name: "الرئيسية", path: "/" }, ...(parent ? [parent] : []), { name: post.title, path: post.path }];
}

export async function generateMetadata({ params }: { params: Params }): Promise<Metadata> {
  const { answer } = await load(params);
  if (answer.status !== "ok") return {};
  const { post } = answer;
  const title = post.seo.title || post.title;
  const description = post.seo.description || post.excerpt || undefined;
  const image = post.seo.share_image ?? post.cover;
  const images = image ? [{ url: image.url, width: image.width, height: image.height, alt: image.alt }] : [{ url: "/og/default.png", width: 1200, height: 630, alt: "شعار رقم" }];
  const sections = await getSections();
  const crumbs = crumbsOf(post, sections);

  return {
    title,
    description,
    alternates: { canonical: post.seo.canonical_url || encodeURI(post.path) },
    robots: post.seo.noindex ? { index: false, follow: true } : undefined,
    authors: post.authors.length ? post.authors.map((a) => ({ name: a.name, url: encodeURI(`/author/${a.slug}`) })) : [{ name: "فريق رَقَم" }],
    openGraph: {
      type: "article",
      url: encodeURI(post.path),
      title,
      description,
      siteName: "رَقَم",
      locale: "ar_AR",
      images,
      publishedTime: post.published_at,
      modifiedTime: post.updated_at,
      ...(crumbs.length > 2 ? { section: crumbs[1].name } : {}),
      tags: post.tags.map((t) => t.name),
      authors: post.authors.map((a) => encodeURI(`/author/${a.slug}`)),
    },
    twitter: { card: "summary_large_image", title, description, images: images.map((i) => i.url) },
  };
}

export default async function ArticlePage({ params }: { params: Params }) {
  const { answer, requested } = await load(params);
  if (answer.status === "unavailable") {
    // Rendered for this request only, so a passing failure is never kept as the article's page.
    await connection();

    return (
      <div className="mx-auto max-w-page px-4 py-16 sm:px-6 lg:px-8">
        <h1 className="text-title font-bold">تعذّر تحميل المادة الآن</h1>
        <p className="mt-4 max-w-text text-lead text-muted">حدّث الصفحة بعد قليل.</p>
      </div>
    );
  }
  if (answer.status === "gone") notFound();
  if (answer.status === "moved") permanentRedirect(encodeURI(answer.path));
  const { post } = answer;
  // The same post under another prefix or an old slug: one address only.
  if (post.path !== requested) permanentRedirect(encodeURI(post.path));

  const [sections, ads, site] = await Promise.all([getSections(), getAds(), getSite()]);
  const crumbs = crumbsOf(post, sections);
  const parent = crumbs.length > 2 ? crumbs[1] : null;
  const prefix = post.path.split("/")[1];
  const more = (prefix === "news" ? (post.category ? await getPostsOf("category", post.category.slug) : null) : await getPostsOf("section", prefix))?.filter((p) => p.id !== post.id).slice(0, 4) ?? [];

  const label = kindLabel(post);
  const details = post.details;
  const html = post.sponsor ? markSponsoredLinks(post.body_html) : post.body_html;
  const parts = splitAfterParagraph(html, 3);
  const inArticle = ads?.slots.find((s) => s.code === "in_article");
  const side = ads?.slots.find((s) => s.code === "sidebar_mpu");
  const videoFirst = !!post.video && (details?.kind === "episode" || details?.kind === "roundtable");

  return (
    <>
      <article className="mx-auto max-w-page px-4 pt-2 pb-12 sm:px-6 lg:px-8">
        {parent && <NavPlace path={parent.path} />}
        <div className="lg:grid lg:grid-cols-12 lg:gap-10">
          <div className="min-w-0 lg:col-span-8">
            <Breadcrumbs crumbs={crumbs} />

            <header className="mt-2 max-w-text">
              {post.sponsor && <SponsorLabel sponsor={post.sponsor} />}
              {label && <p className={`text-small font-bold text-brand ${post.sponsor ? "mt-3" : ""}`}>{label}</p>}
              <h1 className="mt-1 text-title font-bold">{post.title}</h1>
              {post.subtitle && <p className="mt-2 text-lead text-muted">{post.subtitle}</p>}
              <div className="mt-4">
                <Byline post={post} />
              </div>
            </header>

            {post.correction && (
              <div className="mt-6 max-w-text">
                <CorrectionNote correction={post.correction} />
              </div>
            )}

            <div className="mt-6">
              {details?.kind === "infographic" && (details.download ?? post.cover) ? (
                <Infographic image={(details.download ?? post.cover)!} />
              ) : details?.kind === "awareness" ? (
                <div className="max-w-text">
                  <CharacterSays details={details} text={post.excerpt ?? post.title} />
                </div>
              ) : videoFirst ? (
                <Video video={post.video!} />
              ) : post.cover ? (
                <Figure media={post.cover} sizes="(min-width: 84rem) 54rem, (min-width: 64rem) 64vw, 100vw" priority />
              ) : null}
            </div>

            {details && (
              <div className="mt-6 max-w-text">
                <KindFacts details={details} />
              </div>
            )}

            <div className="mt-6 max-w-text">
              {parts ? (
                <>
                  <div data-inline-links className={styles.body} dangerouslySetInnerHTML={{ __html: parts[0] }} />
                  {inArticle && <AdSlot slot={inArticle} label={ads?.label} className="my-8" />}
                  <div data-inline-links className={styles.body} dangerouslySetInnerHTML={{ __html: parts[1] }} />
                </>
              ) : (
                <>
                  <div data-inline-links className={styles.body} dangerouslySetInnerHTML={{ __html: html }} />
                  {inArticle && <AdSlot slot={inArticle} label={ads?.label} className="mt-8" />}
                </>
              )}
            </div>

            {post.video && !videoFirst && (
              <section aria-labelledby="video-title" className="mt-10 max-w-text">
                <h2 id="video-title" className="mb-4 text-heading font-bold">
                  الفيديو
                </h2>
                <Video video={post.video} />
              </section>
            )}

            <div className="mt-10 max-w-text space-y-10 empty:hidden">
              <People guests={post.guests} title={details?.kind === "roundtable" ? "المشاركون" : details?.kind === "episode" ? "المقدّم والضيوف" : "الضيوف"} />
              <Gallery pictures={post.gallery} large={details?.kind === "product"} />
              {post.awareness_cards.length > 0 && (
                <section aria-labelledby="cards-title">
                  <h2 id="cards-title" className="text-heading font-bold">
                    بطاقات توعية
                  </h2>
                  <ul className="mt-2">
                    {post.awareness_cards.map((card) => (
                      <li key={card.id} className="border-line not-first:border-t">
                        <CompactCard post={card} />
                      </li>
                    ))}
                  </ul>
                </section>
              )}
            </div>

            <div className="mt-10 max-w-text border-t border-line pt-6">
              <Tags tags={post.tags} />
            </div>

            {post.related.length > 0 && (
              <section aria-labelledby="related-title" className="mt-10">
                <h2 id="related-title" className="text-heading font-bold text-brand">
                  مواد ذات صلة
                </h2>
                <ul className="mt-2 grid gap-x-8 sm:grid-cols-2">
                  {post.related.slice(0, 4).map((r) => (
                    <li key={r.id} className="border-b border-line">
                      <CompactCard post={r} />
                    </li>
                  ))}
                </ul>
              </section>
            )}
          </div>

          <aside aria-label="المزيد" className="mt-12 lg:col-span-4 lg:mt-0">
            <div className="space-y-10 lg:sticky lg:top-6">
              {parent && more.length > 0 && (
                <section aria-labelledby="more-title" className="border-t-2 border-brand pt-3">
                  <h2 id="more-title" className="text-subheading font-bold text-brand">
                    المزيد من {parent.name}
                  </h2>
                  <ul className="mt-1">
                    {more.map((p) => (
                      <li key={p.id} className="border-line not-first:border-t">
                        <CompactCard post={p} />
                      </li>
                    ))}
                  </ul>
                  <Link href={parent.path} className="inline-flex min-h-11 items-center gap-1 text-small font-medium text-brand hover:underline">
                    كل المواد في {parent.name}
                    <Icon name="end" size={16} />
                  </Link>
                </section>
              )}
              {side && <AdSlot slot={side} label={ads?.label} />}
            </div>
          </aside>
        </div>

        <div className="mt-12">
          <NewsletterBlock title="النشرة البريدية" placement="post_end" path={post.path} />
        </div>
      </article>
      <JsonLd data={articleStructuredData(post, crumbs, site)} />
    </>
  );
}
