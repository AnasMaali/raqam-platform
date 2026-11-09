import { SectionView, sectionMetadata } from "@/components/sections/SectionView";

/** A further page of /news (everything under it), /page/{n}: its own canonical page; past the last one, a 404. */
export const instant = false;

export async function generateMetadata({ params, searchParams }: PageProps<"/news/page/[n]">) {
  return sectionMetadata("news", (await params).n, await searchParams);
}

export default async function NewsSectionPage({ params, searchParams }: PageProps<"/news/page/[n]">) {
  return <SectionView slug="news" rawPage={(await params).n} query={await searchParams} />;
}
