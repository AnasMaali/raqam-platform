import Link from "next/link";
import { connection } from "next/server";

import type { Crumb } from "@/components/article/ArticleParts";
import { PageBody, PageHeader, PageJsonLd, PageUnavailable, noSeo, pageMetadata } from "@/components/pages/PageParts";
import { Pic } from "@/components/ui/Pic";
import { serverEnv } from "@/lib/server/env";
import { getPage, getTeam } from "@/lib/server/pages";
import type { TeamMember } from "@/lib/types";

/*
 * The team (decision 28, page inventory: /team): the page's text from the panel, then every writer the admin put on
 * the page, in the admin's order, each with a photo, the job, a short bio and a link to the writer's own page.
 */
export const instant = false;

const path = "/team";
const crumbs: Crumb[] = [
  { name: "الرئيسية", path: "/" },
  { name: "فريق العمل", path },
];

const linkNames: Record<string, string> = { website: "الموقع", x: "إكس", twitter: "إكس", linkedin: "لينكدإن", facebook: "فيسبوك", instagram: "إنستغرام", youtube: "يوتيوب" };

async function load() {
  try {
    const [team, page] = await Promise.all([getTeam(), getPage("team")]);

    return { team, page };
  } catch (error) {
    console.error(`[site] the team is unavailable: ${(error as Error).message}`);

    return null;
  }
}

export async function generateMetadata() {
  const loaded = await load();
  const page = loaded?.page ?? { title: "فريق العمل", seo: noSeo };
  const names = loaded?.team.slice(0, 4).map((m) => m.name) ?? [];

  return pageMetadata(page, path, names.length ? `فريق تحرير رَقَم في نابلس: ${names.join("، ")}${loaded!.team.length > 4 ? "، وغيرهم" : ""}. من يكتب الأخبار والتقارير وماذا يتابع.` : undefined);
}

function Member({ member, priority }: { member: TeamMember; priority: boolean }) {
  const links = Object.entries(member.links ?? {});

  return (
    <article className="flex gap-4" aria-labelledby={`member-${member.id}`}>
      <div className="w-20 shrink-0 sm:w-24">
        {member.photo ? (
          <Pic media={member.photo} sizes="6rem" ratio="1/1" priority={priority} className="rounded-full" />
        ) : (
          <span aria-hidden="true" className="flex aspect-square items-center justify-center rounded-full bg-tint text-heading font-bold text-brand">
            {member.name.slice(0, 1)}
          </span>
        )}
      </div>
      <div className="min-w-0">
        <h2 id={`member-${member.id}`} className="text-subheading font-bold">
          {member.name}
        </h2>
        {member.job_title && <p className="mt-1 text-small text-muted">{member.job_title}</p>}
        {member.bio && <p className="mt-2">{member.bio}</p>}
        <ul className="mt-1 flex flex-wrap gap-x-4">
          <li>
            <Link href={`/author/${member.slug}`} className="inline-flex min-h-11 items-center text-small font-medium text-brand hover:underline">
              مواد {member.name}
            </Link>
          </li>
          {links.map(([code, url]) => (
            <li key={code}>
              <a href={url} target="_blank" rel="me noopener" className="inline-flex min-h-11 min-w-11 items-center justify-center text-small font-medium text-brand hover:underline">
                {linkNames[code] ?? code}
                <span className="sr-only"> (يفتح في نافذة جديدة)</span>
              </a>
            </li>
          ))}
        </ul>
      </div>
    </article>
  );
}

export default async function TeamPage() {
  const loaded = await load();
  if (!loaded) {
    await connection();

    return <PageUnavailable what="فريق العمل" />;
  }
  const { team, page } = loaded;
  const title = page?.title ?? "فريق العمل";
  const base = serverEnv().siteUrl.origin;

  return (
    <>
      <div className="mx-auto max-w-page px-4 pt-2 pb-16 sm:px-6 lg:px-8">
        <PageHeader title={title} crumbs={crumbs} />
        {page && <PageBody html={page.body_html} className="mt-6" />}
        {team.length > 0 ? (
          <ul className="mt-10 grid gap-x-8 gap-y-10 md:grid-cols-2 xl:grid-cols-3">
            {team.map((member, i) => (
              <li key={member.id}>
                {/* The first photos are in the first screen on a phone: they load at once. */}
                <Member member={member} priority={i < 2} />
              </li>
            ))}
          </ul>
        ) : (
          <p className="mt-8 text-lead text-muted">لا أسماء على هذه الصفحة بعد.</p>
        )}
      </div>
      <PageJsonLd
        page={{ title, seo: page?.seo ?? noSeo, system_key: "team", updated_at: page?.updated_at ?? null }}
        path={path}
        crumbs={crumbs}
        extra={{
          mainEntity: {
            "@type": "ItemList",
            numberOfItems: team.length,
            itemListElement: team.map((m, i) => ({
              "@type": "ListItem",
              position: i + 1,
              item: {
                "@type": "Person",
                name: m.name,
                url: `${base}${encodeURI(`/author/${m.slug}`)}`,
                ...(m.job_title ? { jobTitle: m.job_title } : {}),
                ...(m.photo ? { image: m.photo.url } : {}),
                ...(Object.keys(m.links ?? {}).length ? { sameAs: Object.values(m.links) } : {}),
                worksFor: { "@id": `${base}/#organization` },
              },
            })),
          },
        }}
      />
    </>
  );
}
