import { SectionView, sectionMetadata } from "@/components/sections/SectionView";

/** A further page of the podcast section, /page/{n}: its own canonical page; past the last one, a 404. */
export const instant = false;

export async function generateMetadata({ params, searchParams }: PageProps<"/podcast/page/[n]">) {
  return sectionMetadata("podcast", (await params).n, await searchParams);
}

export default async function PodcastSectionPage({ params, searchParams }: PageProps<"/podcast/page/[n]">) {
  return <SectionView slug="podcast" rawPage={(await params).n} query={await searchParams} />;
}
