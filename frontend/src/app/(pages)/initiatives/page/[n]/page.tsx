import { SectionView, sectionMetadata } from "@/components/sections/SectionView";

/** A further page of the initiatives section, /page/{n}: its own canonical page; past the last one, a 404. */
export const instant = false;

export async function generateMetadata({ params, searchParams }: PageProps<"/initiatives/page/[n]">) {
  return sectionMetadata("initiatives", (await params).n, await searchParams);
}

export default async function InitiativesSectionPage({ params, searchParams }: PageProps<"/initiatives/page/[n]">) {
  return <SectionView slug="initiatives" rawPage={(await params).n} query={await searchParams} />;
}
